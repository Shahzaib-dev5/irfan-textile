<?php

namespace Tests\Feature;

use App\Models\AccessRole;
use App\Models\AccountingPeriod;
use App\Models\Document;
use App\Models\DocumentSequence;
use App\Models\InventoryCostLink;
use App\Models\InventoryValueEntry;
use App\Models\JournalLine;
use App\Models\Party;
use App\Models\PartyRole;
use App\Models\PartyRoleType;
use App\Models\PaymentTerm;
use App\Models\Permission;
use App\Models\StockCostLayer;
use App\Models\StockMovement;
use App\Models\StockPosition;
use App\Models\StockReservation;
use App\Models\User;
use App\Models\VendorProfile;
use App\Models\WarehouseLocation;
use App\Modules\Catalog\Services\CatalogService;
use App\Modules\Inventory\Services\InventoryInquiry;
use App\Modules\Inventory\Services\StockLedger;
use App\Modules\Warehouses\Services\WarehouseService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\InventoryFixtures;
use Tests\TestCase;

class InventoryTest extends TestCase
{
    use InventoryFixtures,RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->inventoryFixtures();
    }

    private function rejects(callable $action): void
    {
        try {
            $action();
            $this->fail('Expected domain validation to reject this operation.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }
    }

    public function test_opening_is_evidenced_audited_numbered_valued_and_idempotent(): void
    {
        $doc = $this->opening('100.125', '12.5', false);
        $position = $this->position($doc);
        $this->assertSame('0.000000', app(StockLedger::class)->onHand($position));
        $key = (string) Str::uuid();
        $posted = $this->postDocument($doc, $key);
        $this->assertSame('posted', $posted->status);
        $this->assertStringStartsWith('IADJ-', $posted->number);
        $this->assertSame('100.125000', app(StockLedger::class)->onHand($position));
        $this->assertSame('1251.5625', $posted->journal->total_debit);
        $this->assertSame($posted->journal->total_debit, $posted->journal->total_credit);
        $this->assertSame($posted->id, $this->inventory->post($this->admin->id, $doc->id, $key, 0)->id);
        $this->assertSame(1, StockMovement::count());
        $this->assertSame(2, JournalLine::count());
        $this->rejects(fn () => $this->inventory->post($this->admin->id, $doc->id, (string) Str::uuid(), 0));
        $this->assertDatabaseHas('audit_events', ['document_id' => $doc->id, 'action' => 'posted', 'actor_id' => $this->admin->id]);
    }

    public function test_transfer_preserves_quantity_value_lot_and_fifo_age(): void
    {
        $opening = $this->opening('100', '10');
        $source = $this->position($opening);
        $gain = $this->adjustment($source, '20', 'count', '20', '120');
        $this->postDocument($gain);
        $transfer = $this->postDocument($this->transfer($source, '110'));
        $target = $transfer->transfer->lines()->first()->destination_stock_position_id;
        $ledger = app(StockLedger::class);
        $this->assertSame('10.000000', $ledger->onHand($source));
        $this->assertSame('110.000000', $ledger->onHand($target));
        $this->assertSame('0.000000', (string) $transfer->movements()->sum('quantity_delta'));
        $this->assertSame('0.0000', (string) InventoryValueEntry::where('document_id', $transfer->id)->sum('amount_pkr'));
        $this->assertSame(2, $transfer->transfer->lines()->count());
        $this->assertNull($transfer->journal);
        $this->assertSame(2, InventoryCostLink::where('document_id', $transfer->id)->count());
        $this->assertSame(StockPosition::find($source)->stock_lot_id, StockPosition::find($target)->stock_lot_id);
        $this->assertSame('1200.0000', (string) InventoryValueEntry::whereIn('stock_cost_layer_id', StockCostLayer::where('stock_position_id', $target)->pluck('id'))->sum('amount_pkr'));
        $this->assertSame([], app(InventoryInquiry::class)->reconciliation());
    }

    public function test_invalid_issues_counts_costs_and_precision_leave_no_partial_posting(): void
    {
        $this->rejects(fn () => $this->opening('0'));
        $this->rejects(fn () => $this->opening('-1'));
        $this->rejects(fn () => $this->opening('1e3'));
        $this->rejects(fn () => $this->opening('1', '-10'));
        $doc = $this->opening('10', '3');
        $position = $this->position($doc);
        $bad = $this->adjustment($position, '-11');
        $this->rejects(fn () => $this->postDocument($bad));
        $this->assertSame('draft', $bad->fresh()->status);
        $this->assertSame(1, StockMovement::count());
        $this->rejects(fn () => $this->adjustment($position, '1', 'damage'));
        $this->rejects(fn () => $this->adjustment($position, '0', 'count', null, '10'));
        $this->item->baseUnit->update(['decimal_places' => 2]);
        $this->rejects(fn () => $this->adjustment($position, '-0.001'));
    }

    public function test_count_snapshot_detects_intervening_movements_and_can_be_refreshed(): void
    {
        $doc = $this->opening('10');
        $position = $this->position($doc);
        $count = $this->adjustment($position, '-2', 'count', null, '8');
        $this->postDocument($this->adjustment($position, '-1'));
        $this->rejects(fn () => $this->postDocument($count));
        $fresh = $this->adjustment($position, '-1', 'count', null, '8');
        $this->postDocument($fresh);
        $this->assertSame('8.000000', app(StockLedger::class)->onHand($position));
    }

    public function test_reservations_block_competing_issues_and_are_fulfilled_by_the_transfer(): void
    {
        $doc = $this->opening('100');
        $position = $this->position($doc);
        $transfer = $this->transfer($position, '80');
        $this->inventory->reserve($this->admin->id, $transfer->id, 0, now()->addHour()->toDateTimeString());
        $this->assertSame('20.000000', app(StockLedger::class)->available($position));
        $this->rejects(fn () => $this->postDocument($this->adjustment($position, '-21')));
        $this->postDocument($transfer);
        $this->assertSame('80.000000', StockReservation::first()->fulfilled_quantity);
        $this->assertSame('20.000000', app(StockLedger::class)->available($position));
        $this->assertSame([], app(InventoryInquiry::class)->reconciliation());
    }

    public function test_cancel_releases_reservations_and_expiry_requires_an_audited_release(): void
    {
        $position = $this->position($this->opening('30'));
        $transfer = $this->transfer($position, '20');
        $this->inventory->reserve($this->admin->id, $transfer->id, 0, now()->addMinute()->toDateTimeString());
        $this->travel(2)->minutes();
        $this->assertSame('10.000000', app(StockLedger::class)->available($position));
        $this->rejects(fn () => $this->postDocument($transfer));
        $this->inventory->release($this->admin->id, $transfer->id, $transfer->fresh()->lock_version, 'Expired reservation released.');
        $this->assertSame('30.000000', app(StockLedger::class)->available($position));
        $this->inventory->reserve($this->admin->id, $transfer->id, $transfer->fresh()->lock_version);
        $this->inventory->cancel($this->admin->id, $transfer->id, $transfer->fresh()->lock_version, 'Transfer cancelled by supervisor.');
        $this->assertSame('cancelled', $transfer->fresh()->status);
        $this->assertNull($transfer->fresh()->number);
        $this->assertSame('30.000000', app(StockLedger::class)->available($position));
        $this->travelBack();
    }

    public function test_reversal_preserves_history_and_requires_downstream_compensation_first(): void
    {
        $opening = $this->opening('100', '12.345678');
        $source = $this->position($opening);
        $transfer = $this->postDocument($this->transfer($source, '40'));
        $this->rejects(fn () => $this->inventory->reverse($this->admin->id, $opening->id, $this->date, 'Opening correction required.', (string) Str::uuid()));
        $key = (string) Str::uuid();
        $reversal = $this->inventory->reverse($this->admin->id, $transfer->id, $this->date, 'Incorrect destination selected.', $key);
        $this->assertSame($reversal->id, $this->inventory->reverse($this->admin->id, $transfer->id, $this->date, 'Incorrect destination selected.', $key)->id);
        $this->assertSame('100.000000', app(StockLedger::class)->onHand($source));
        $this->inventory->reverse($this->admin->id, $opening->id, $this->date, 'Opening correction required.', (string) Str::uuid());
        $this->assertSame('0.000000', app(StockLedger::class)->onHand($source));
        $this->assertSame('0.0000', (string) InventoryValueEntry::sum('amount_pkr'));
        $this->assertSame('reversed', $opening->fresh()->status);
        $this->assertSame(6, StockMovement::count());
        $this->assertSame([], app(InventoryInquiry::class)->reconciliation());
    }

    public function test_issue_reversal_restores_exact_layers_and_rounding_residual(): void
    {
        $opening = $this->opening('3', '0.33333333');
        $source = $this->position($opening);
        $issue = $this->postDocument($this->adjustment($source, '-1'));
        $reversal = $this->inventory->reverse($this->admin->id, $issue->id, $this->date, 'Damaged stock was misclassified.', (string) Str::uuid());
        $this->assertSame('0.33330000', $reversal->adjustment->lines->first()->positive_unit_cost_pkr);
        $this->assertSame(1, StockCostLayer::count());
        $this->postDocument($this->adjustment($source, '-3'));
        $this->assertSame('0.0000', (string) InventoryValueEntry::sum('amount_pkr'));
        $this->assertSame('0.000000', app(StockLedger::class)->onHand($source));
    }

    public function test_rolls_move_as_whole_identity_and_cannot_be_duplicated(): void
    {
        $this->item->update(['track_rolls' => true]);
        $opening = $this->opening('50', '10', true, 'GREY-ROLL', ['roll_number' => 'ROLL-001']);
        $source = $this->position($opening);
        $this->rejects(fn () => $this->transfer($source, '20'));
        $transfer = $this->postDocument($this->transfer($source, '50'));
        $target = $transfer->transfer->lines->first()->destination_stock_position_id;
        $this->assertSame(StockPosition::find($source)->fabric_roll_id, StockPosition::find($target)->fabric_roll_id);
        $this->rejects(fn () => $this->opening('20', '10', true, 'OTHER-LOT', ['roll_number' => 'ROLL-001']));
    }

    public function test_projection_drift_blocks_posting_and_repair_rebuilds_only_caches(): void
    {
        $opening = $this->opening('10');
        $source = $this->position($opening);
        $issue = $this->adjustment($source, '-2');
        DB::table('stock_balances')->where('stock_position_id', $source)->update(['on_hand_quantity' => '999']);
        $this->assertSame('10.000000', app(StockLedger::class)->onHand($source));
        $this->rejects(fn () => $this->postDocument($issue));
        $this->assertNotEmpty(app(InventoryInquiry::class)->reconciliation());
        app(InventoryInquiry::class)->repair($this->admin->id, 'Rebuild after detected cache discrepancy.');
        $this->postDocument($issue);
        $this->assertSame('8.000000', app(StockLedger::class)->onHand($source));
        $this->assertSame([], app(InventoryInquiry::class)->reconciliation());
    }

    public function test_closed_periods_inactive_storage_quality_and_stale_versions_are_rejected(): void
    {
        $opening = $this->opening('10', '0', false);
        $source = $this->position($opening);
        AccountingPeriod::query()->update(['status' => 'closed', 'closed_at' => now(), 'closed_by' => $this->admin->id]);
        $this->rejects(fn () => $this->postDocument($opening));
        AccountingPeriod::query()->update(['status' => 'open', 'closed_at' => null, 'closed_by' => null]);
        $this->postDocument($opening);
        $this->assertNull($opening->fresh()->journal);
        $this->inventory->quality($this->admin->id, StockPosition::find($source)->stock_lot_id, 'held', 'Inspection hold for fabric quality.');
        $this->rejects(fn () => $this->transfer($source, '2'));
        $quarantine = WarehouseLocation::factory()->create(['location_type' => 'quarantine']);
        $this->postDocument($this->transfer($source, '2', $quarantine->id));
        $draft = $this->adjustment($source, '-1');
        $this->rejects(fn () => $this->inventory->cancel($this->admin->id, $draft->id, 99, 'Stale browser cancellation.'));
    }

    public function test_setup_and_all_inventory_screens_render_with_permission_enforcement(): void
    {
        $opening = $this->opening('10');
        $draft = $this->transfer($this->position($opening), '2');
        foreach (['inventory.index', 'inventory.documents.index', 'inventory.movements', 'inventory.reconciliation', 'warehouses.index', 'warehouses.create'] as $route) {
            $this->get(route($route))->assertOk();
        }
        $this->get(route('warehouses.create', ['location' => 1]))->assertOk();
        $this->get(route('inventory.documents.create'))->assertOk();
        $this->get(route('inventory.documents.create', ['kind' => 'transfer']))->assertOk();
        $this->get(route('inventory.documents.show', $opening))->assertOk();
        $this->get(route('inventory.documents.show', $draft))->assertOk();
        $this->get(route('inventory.documents.edit', $draft))->assertOk();
        $viewer = User::factory()->create();
        $role = AccessRole::create(['code' => 'stock-viewer', 'name' => 'Stock viewer', 'is_active' => true]);
        $role->permissions()->attach(Permission::where('code', 'inventory.view')->value('id'));
        $viewer->accessRoles()->attach($role->id, ['assigned_by' => $this->admin->id]);
        $this->actingAs($viewer)->get(route('inventory.index'))->assertOk()->assertDontSee('Prepare adjustment');
        $this->get(route('inventory.documents.create'))->assertForbidden();
        $this->post(route('inventory.documents.action', [$draft, 'post']), ['lock_version' => 0, 'posting_key' => (string) Str::uuid()])->assertForbidden();
        try {
            $this->inventory->post($viewer->id, $draft->id, (string) Str::uuid(), 0);
            $this->fail();
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_warehouse_service_prevents_custody_reassignment_and_deactivation_with_stock(): void
    {
        $service = app(WarehouseService::class);
        $this->opening('2');
        $this->rejects(fn () => $service->save($this->admin->id, ['code' => $this->source->code, 'name' => 'Changed', 'warehouse_id' => $this->source->warehouse_id, 'location_type' => 'contractor', 'contractor_vendor_party_role_id' => null, 'is_active' => true], $this->source->id, true));
        $this->rejects(fn () => $service->save($this->admin->id, ['code' => $this->source->code, 'name' => $this->source->name, 'warehouse_id' => $this->source->warehouse_id, 'location_type' => 'internal', 'is_active' => false], $this->source->id, true));
        $this->post(route('warehouses.store'), ['code' => 'WH-MAIN', 'name' => 'Main grey warehouse', 'is_active' => 1, 'address' => 'Lahore'])->assertRedirect(route('warehouses.index'));
        $this->assertDatabaseHas('warehouses', ['code' => 'WH-MAIN']);
    }

    public function test_http_draft_edit_post_and_missing_action_fields_are_validated(): void
    {
        $input = ['kind' => 'adjustment', 'document_date' => $this->date, 'idempotency_key' => (string) Str::uuid(), 'adjustment_reason' => 'opening', 'reference' => 'Count sheet OC-HTTP', 'explanation' => 'Verified opening with signed valuation schedule.', 'lines' => [['item_id' => $this->item->id, 'warehouse_location_id' => $this->source->id, 'lot_number' => 'HTTP-OPENING', 'quality_status' => 'released', 'quantity_delta' => '25', 'positive_unit_cost_pkr' => '15']]];
        $this->post(route('inventory.documents.store'), $input)->assertSessionHasNoErrors()->assertRedirect();
        $doc = Document::where('idempotency_key', $input['idempotency_key'])->firstOrFail();
        $this->assertSame($doc->id, $this->inventory->save($this->admin->id, $input)->id);
        $different = $input;
        $different['explanation'] = 'Different unapproved opening evidence.';
        $this->rejects(fn () => $this->inventory->save($this->admin->id, $different));
        $input['lock_version'] = 0;
        $input['explanation'] = 'Reviewed opening with signed valuation schedule.';
        $this->put(route('inventory.documents.update', $doc), $input)->assertSessionHasNoErrors()->assertRedirect();
        $this->put(route('inventory.documents.update', $doc), $input)->assertSessionHasErrors('inventory');
        $this->post(route('inventory.documents.action', [$doc, 'post']), ['lock_version' => 1])->assertSessionHasErrors('posting_key');
        $this->post(route('inventory.documents.action', [$doc, 'post']), ['lock_version' => 1, 'posting_key' => (string) Str::uuid()])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('posted', $doc->fresh()->status);
        $this->put(route('inventory.documents.update', $doc), $input)->assertForbidden();
    }

    public function test_contractor_custody_uses_existing_vendor_identity_and_preserves_owned_value(): void
    {
        $party = Party::create(['code' => 'PTY-CUSTODY', 'legal_name' => 'Existing dyeing contractor', 'party_kind' => 'organization', 'is_active' => true]);
        $role = PartyRole::create(['party_id' => $party->id, 'party_role_type_id' => PartyRoleType::where('code', 'vendor')->value('id'), 'is_active' => true]);
        VendorProfile::create(['party_role_id' => $role->id, 'payment_term_id' => PaymentTerm::firstOrFail()->id]);
        $contractor = app(WarehouseService::class)->save($this->admin->id, ['warehouse_id' => $this->destination->warehouse_id, 'code' => 'DYE-CUSTODY', 'name' => 'Contractor fabric custody', 'location_type' => 'contractor', 'contractor_vendor_party_role_id' => $role->id, 'is_active' => true], null, true);
        $opening = $this->opening('100', '25');
        $transfer = $this->postDocument($this->transfer($this->position($opening), '60', $contractor->id));
        $target = $transfer->transfer->lines->first()->destination_stock_position_id;
        $this->assertSame('60.000000', app(StockLedger::class)->onHand($target));
        $this->assertSame('2500.0000', (string) InventoryValueEntry::sum('amount_pkr'));
        $this->assertSame(1, Party::count());
        $this->get(route('warehouses.create', ['location' => 1]))->assertOk()->assertSee('Existing dyeing contractor');
        $this->assertSame([], app(InventoryInquiry::class)->reconciliation());
    }

    public function test_stock_referenced_master_and_posted_history_are_immutable(): void
    {
        $opening = $this->opening('5');
        $item = $this->item->fresh();
        $input = $item->only(['sku', 'name', 'category_id', 'item_type', 'base_unit_id', 'is_stocked', 'track_rolls', 'is_active', 'description']);
        $input['fabric'] = ['composition' => 'Changed after stock'];
        $input['sku'] = 'NEW-STOCK-IDENTITY';
        $this->rejects(fn () => app(CatalogService::class)->save('items', $input, $item->id));
        foreach ([fn () => StockMovement::first()->update(['quantity_delta' => '999']), fn () => $opening->update(['document_date' => '2026-01-01']), fn () => $opening->adjustment->lines->first()->update(['quantity_delta' => '999']), fn () => StockPosition::first()->update(['warehouse_location_id' => $this->destination->id])] as $write) {
            try {
                $write();
                $this->fail('History must be immutable.');
            } catch (\LogicException $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }
    }

    public function test_database_constraints_reject_negative_projection_and_duplicate_unrolled_positions(): void
    {
        $opening = $this->opening('5');
        $position = StockPosition::findOrFail($this->position($opening));
        try {
            DB::table('stock_balances')->where('stock_position_id', $position->id)->update(['on_hand_quantity' => '-1']);
            $this->fail('Expected CHECK rejection.');
        } catch (QueryException $e) {
            $this->assertNotEmpty($e->errorInfo);
        }
        try {
            DB::table('stock_positions')->insert(['stock_lot_id' => $position->stock_lot_id, 'warehouse_location_id' => $position->warehouse_location_id, 'fabric_roll_id' => null]);
            $this->fail('Expected generated-key uniqueness rejection.');
        } catch (QueryException $e) {
            $this->assertNotEmpty($e->errorInfo);
        }
        $this->assertSame('5.000000', app(StockLedger::class)->onHand($position->id));
    }

    public function test_late_line_failure_rolls_back_all_movements_costs_and_numbers(): void
    {
        $opening = $this->opening('10');
        $source = $this->position($opening);
        $other = $this->opening('5');
        $second = $this->position($other);
        $draft = $this->inventory->save($this->admin->id, ['kind' => 'adjustment', 'document_date' => $this->date, 'idempotency_key' => (string) Str::uuid(), 'adjustment_reason' => 'damage', 'explanation' => 'Two physically evidenced damaged lots.', 'lines' => [['stock_position_id' => $source, 'quantity_delta' => '-2'], ['stock_position_id' => $second, 'quantity_delta' => '-6']]]);
        $before = DocumentSequence::where('document_type_id', $draft->document_type_id)->value('next_value');
        $this->rejects(fn () => $this->postDocument($draft));
        $this->assertSame(2, StockMovement::count());
        $this->assertSame(2, InventoryValueEntry::count());
        $this->assertSame('10.000000', app(StockLedger::class)->onHand($source));
        $this->assertSame($before, DocumentSequence::where('document_type_id', $draft->document_type_id)->value('next_value'));
        $this->assertNull($draft->fresh()->number);
    }
}
