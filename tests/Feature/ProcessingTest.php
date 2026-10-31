<?php

namespace Tests\Feature;

use App\Models\AccessRole;
use App\Models\AccountingPeriod;
use App\Models\Document;
use App\Models\FabricRollLineage;
use App\Models\InventoryValueEntry;
use App\Models\ItemUnitConversion;
use App\Models\JournalEntry;
use App\Models\PartyOpenItem;
use App\Models\Permission;
use App\Models\ProcessingDispatch;
use App\Models\ProcessingJob;
use App\Models\ProcessingReceipt;
use App\Models\ProcessingService;
use App\Models\StockCostLayer;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Models\VendorService;
use App\Models\VendorServiceRate;
use App\Modules\Inventory\Services\InventoryInquiry;
use App\Modules\Inventory\Services\StockLedger;
use App\Modules\Parties\Services\PartyManagementService;
use App\Modules\Processing\Services\ProcessingRoutes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\ProcessingFixtures;
use Tests\TestCase;

class ProcessingTest extends TestCase
{
    use ProcessingFixtures,RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->processingFixtures();
    }

    public function test_full_lifecycle_keeps_company_stock_and_posts_charge_once(): void
    {
        $p = $this->position($this->opening());
        $job = $this->job();
        $dispatch = $this->dispatch($job, $p);
        $line = ProcessingDispatch::find($dispatch->id)->lines->first();
        $stock = app(StockLedger::class);
        $this->assertSame('0.000000', $stock->onHand($p));
        $this->assertSame('100.000000', $stock->onHand($line->contractor_stock_position_id));
        $this->assertSame('1000.0000', bcadd((string) InventoryValueEntry::sum('amount_pkr'), '0', 4));
        $r = $this->processingReceipt($dispatch);
        $this->processingPost($r, $r->posting_key);
        $this->assertSame('0.000000', $this->processing->outstanding($line));
        $this->assertSame('1200.0000', bcadd((string) InventoryValueEntry::sum('amount_pkr'), '0', 4));
        $this->assertSame('200.0000', $r->journal->total_debit);
        $key = (string) Str::uuid();
        $bill = $this->contractorBill($r);
        $this->processing->post($this->admin->id, $bill->id, $bill->posting_key, 0);
        $this->assertSame(1, PartyOpenItem::count());
        $this->assertSame('200.0000', PartyOpenItem::first()->original_amount);
        $this->assertSame(3, JournalEntry::count());
        $this->assertSame(5, StockMovement::count());
        $progress = $this->processing->jobProgress(ProcessingJob::find($job->id));
        $this->assertSame('100.000000', array_values($progress)[0]['accepted']);
        $this->assertSame('0.000000', array_values($progress)[0]['outstanding']);
    }

    public function test_partial_receipts_normal_and_abnormal_loss_reconcile_cost(): void
    {
        $d = $this->dispatch($this->job(), $this->position($this->opening()));
        $a = $this->processingReceipt($d, '60', '54', true, ['normal_loss_quantity' => '4', 'abnormal_loss_quantity' => '2']);
        $line = ProcessingReceipt::find($a->id)->lines->first();
        $this->assertSame('108.0000', $line->service_accrual_pkr);
        $this->assertSame('20.0000', $line->abnormal_loss_cost_pkr);
        $this->assertSame('688.0000', StockCostLayer::where('receipt_stock_movement_id', $line->output_stock_movement_id)->first()->values()->sum('amount_pkr'));
        $this->assertSame('40.000000', $this->processing->outstanding(ProcessingDispatch::find($d->id)->lines->first()));
        $this->processingReceipt($d, '40', '40');
        $this->assertSame('0.000000', $this->processing->outstanding(ProcessingDispatch::find($d->id)->lines->first()));
    }

    public function test_over_receipt_rolls_back_without_stock_or_journal_changes(): void
    {
        $d = $this->dispatch($this->job(), $this->position($this->opening()));
        $r = $this->processingReceipt($d, '101', '101', false);
        $before = StockMovement::count();
        try {
            $this->processingPost($r);
            $this->fail('Over receipt accepted');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('outstanding', $e->getMessage());
        }
        $this->assertSame($before, StockMovement::count());
        $this->assertSame('draft', $r->fresh()->status);
        $this->assertSame(1, JournalEntry::count());
    }

    public function test_rejected_return_has_no_service_charge_and_can_be_redispatched(): void
    {
        $job = $this->job();
        $d = $this->dispatch($job, $this->position($this->opening()));
        $r = $this->processingReceipt($d, '25', '25', true, ['disposition' => 'rejected']);
        $line = ProcessingReceipt::find($r->id)->lines->first();
        $this->assertSame('0.0000', $line->service_accrual_pkr);
        $this->assertNull($r->journal);
        $again = $this->dispatch($job, $line->output_stock_position_id, '25');
        $this->assertSame('posted', $again->status);
    }

    public function test_rate_changes_do_not_change_posted_job_snapshot(): void
    {
        $job = $this->job();
        $rate = VendorServiceRate::first();
        app(PartyManagementService::class)->publishRate($this->capability, ['billing_unit_id' => $rate->billing_unit_id, 'unit_rate' => '9', 'effective_from' => today()->addDay()->format('Y-m-d'), 'effective_to' => null, 'rate_reason' => 'Prospective vendor revision'], $this->admin);
        $this->assertSame('2.00000000', ProcessingJob::find($job->id)->lines->first()->agreed_unit_rate_pkr);
        $d = $this->dispatch($job, $this->position($this->opening()));
        $r = $this->processingReceipt($d);
        $this->assertSame('200.0000', ProcessingReceipt::find($r->id)->lines->first()->service_accrual_pkr);
    }

    public function test_negotiated_rate_requires_permission_and_a_reason_and_is_snapshotted(): void
    {
        $job = $this->job(true, ['agreed_unit_rate_pkr' => '1.5', 'override_reason' => 'Negotiated signed volume discount']);
        $this->assertSame('1.50000000', ProcessingJob::find($job->id)->lines->first()->agreed_unit_rate_pkr);
        $this->assertDatabaseHas('audit_events', ['document_id' => $job->id, 'action' => 'negotiated_rate_authorized']);
        $this->expectException(ValidationException::class);
        $this->job(false, ['agreed_unit_rate_pkr' => '1']);
    }

    public function test_open_processing_custody_cannot_be_drained_by_generic_inventory(): void
    {
        $d = $this->dispatch($this->job(), $this->position($this->opening()));
        $p = ProcessingDispatch::find($d->id)->lines->first()->contractor_stock_position_id;
        $adjustment = $this->adjustment($p, '-1');
        $this->expectException(ValidationException::class);
        $this->postDocument($adjustment);
    }

    public function test_double_billing_is_rejected_and_partial_matching_conserves_accrual(): void
    {
        $r = $this->processingReceipt($this->dispatch($this->job(), $this->position($this->opening())));
        $this->contractorBill($r, '40');
        $this->contractorBill($r, '60');
        $this->assertSame('200.0000', bcadd((string) PartyOpenItem::sum('original_amount'), '0', 4));
        $draft = $this->contractorBill($r, '1', false);
        $this->expectException(ValidationException::class);
        $this->processingPost($draft);
    }

    public function test_roll_dispatch_and_partial_returns_preserve_genealogy(): void
    {
        $this->item->update(['track_rolls' => true]);
        $p = $this->position($this->opening('100', '10', true, null, ['roll_number' => 'ROLL-ORIGINAL']));
        $d = $this->dispatch($this->job(), $p);
        $this->processingReceipt($d, '60', '60', true, ['roll_number' => 'ROLL-CHILD-A']);
        $this->processingReceipt($d, '40', '40', true, ['roll_number' => 'ROLL-CHILD-B']);
        $this->assertSame(2, FabricRollLineage::count());
        $this->assertSame('100.000000', bcadd((string) FabricRollLineage::sum('parent_quantity_used'), '0', 6));
    }

    public function test_normal_loss_above_allowance_is_rejected(): void
    {
        $d = $this->dispatch($this->job(), $this->position($this->opening()));
        $this->expectException(ValidationException::class);
        $this->processingReceipt($d, '100', '80', false, ['normal_loss_quantity' => '20']);
    }

    public function test_posted_details_and_published_routes_are_immutable(): void
    {
        $j = $this->job();
        $this->expectException(\LogicException::class);
        ProcessingJob::find($j->id)->update(['instructions' => 'Changed posted job']);
    }

    public function test_all_processing_screens_render_and_backend_permissions_are_enforced(): void
    {
        $j = $this->job();
        $d = $this->dispatch($j, $this->position($this->opening()));
        $r = $this->processingReceipt($d);
        $bill = $this->contractorBill($r);
        foreach (['/processing/documents', '/processing/routes', '/processing/custody', '/processing/reports', '/processing/documents/create?kind=job&route='.$this->route->id, '/processing/documents/create?kind=dispatch&job='.$j->id, '/processing/documents/create?kind=receipt&job='.$j->id, '/processing/documents/create?kind=bill'] as $url) {
            $this->get($url)->assertOk();
        }
        foreach ([$j, $d, $r, $bill] as $doc) {
            $this->get(route('processing.show', $doc))->assertOk();
            $this->get(route('processing.print', $doc))->assertOk();
        }
        $this->assertSame([], app(InventoryInquiry::class)->reconciliation());
        $u = User::factory()->create();
        $this->actingAs($u)->get('/processing/documents')->assertForbidden();
        $this->post(route('processing.action', [$j, 'post']), ['request_key' => (string) Str::uuid(), 'lock_version' => 0])->assertForbidden();
    }

    public function test_dispatch_layers_remain_separate_when_two_jobs_share_contractor_storage(): void
    {
        $p = $this->position($this->opening('200'));
        $a = $this->dispatch($this->job(), $p);
        $b = $this->dispatch($this->job(), $p);
        $la = ProcessingDispatch::find($a->id)->lines->first();
        $lb = ProcessingDispatch::find($b->id)->lines->first();
        $this->assertSame($la->contractor_stock_position_id, $lb->contractor_stock_position_id);
        $this->processingReceipt($b, '100', '100');
        $this->assertSame('100.000000', $this->processing->outstanding($la));
        $this->assertTrue($this->processing->custodyMatches($la));
        $this->assertTrue($this->processing->custodyMatches($lb));
        $this->processingReceipt($a, '100', '100');
        $this->assertSame([], app(InventoryInquiry::class)->reconciliation());
    }

    public function test_creation_retries_and_posting_retries_do_not_duplicate_documents(): void
    {
        $payload = ['kind' => 'job', 'document_date' => $this->date, 'idempotency_key' => (string) Str::uuid(), 'processing_route_version_id' => $this->route->id, 'planned_input_quantity' => '100', 'lines' => [['vendor_service_id' => $this->capability->id]]];
        $a = $this->processing->save($this->admin->id, $payload);
        $b = $this->processing->save($this->admin->id, $payload);
        $this->assertSame($a->id, $b->id);
        $key = (string) Str::uuid();
        $this->processingPost($a, $key);
        $this->processingPost($a, $key);
        $this->assertSame(1, Document::whereHas('type', fn ($q) => $q->where('module_code', 'processing'))->count());
        $payload['planned_input_quantity'] = '200';
        $this->expectException(ValidationException::class);
        $this->processing->save($this->admin->id, $payload);
    }

    public function test_a_different_contractor_location_is_rejected(): void
    {
        $p = $this->position($this->opening());
        $j = $this->job();
        $this->expectException(ValidationException::class);
        $this->processingDraft('dispatch', ['processing_job_document_id' => $j->id, 'vendor_party_role_id' => $this->vendor->id, 'lines' => [['processing_job_step_id' => ProcessingJob::find($j->id)->lines->first()->id, 'source_stock_position_id' => $p, 'contractor_location_id' => $this->source->id, 'quantity' => '10']]]);
    }

    public function test_draft_cancellation_is_audited_and_posted_cancellation_is_rejected(): void
    {
        $j = $this->job(false);
        $this->processing->cancel($this->admin->id, $j->id, 0, 'Route was selected incorrectly');
        $this->assertSame('cancelled', $j->fresh()->status);
        $this->assertDatabaseHas('document_status_events', ['document_id' => $j->id, 'to_status' => 'cancelled']);
        $posted = $this->job();
        $this->expectException(ValidationException::class);
        $this->processing->cancel($this->admin->id, $posted->id, $posted->lock_version, 'Cannot erase approved history');
    }

    public function test_negotiated_rate_is_blocked_without_the_override_permission(): void
    {
        $role = AccessRole::create(['code' => 'processor', 'name' => 'Processing preparer', 'is_active' => true]);
        $role->permissions()->attach(Permission::whereIn('code', ['processing.documents.view', 'processing.documents.manage'])->pluck('id'));
        $u = User::factory()->create();
        $u->accessRoles()->attach($role->id, ['assigned_by' => $this->admin->id]);
        $before = Document::count();
        try {
            $this->processing->save($u->id, ['kind' => 'job', 'document_date' => $this->date, 'idempotency_key' => (string) Str::uuid(), 'processing_route_version_id' => $this->route->id, 'planned_input_quantity' => '100', 'lines' => [['vendor_service_id' => $this->capability->id, 'agreed_unit_rate_pkr' => '1', 'override_reason' => 'Negotiated without correct authority']]]);
            $this->fail('Unauthorized override accepted');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertSame($before, Document::count());
    }

    public function test_meter_stock_and_piece_billed_embroidery_require_count_evidence(): void
    {
        $embroidery = ProcessingService::where('code', 'embroidery')->firstOrFail();
        $cap = VendorService::create(['vendor_party_role_id' => $this->vendor->id, 'processing_service_id' => $embroidery->id, 'is_active' => true]);
        VendorServiceRate::create(['vendor_service_id' => $cap->id, 'billing_unit_id' => $embroidery->billing_unit_id, 'unit_rate' => '5', 'currency_code' => 'PKR', 'effective_from' => $this->date, 'approved_by' => $this->admin->id, 'rate_reason' => 'Signed embroidery per-piece contract']);
        $routes = app(ProcessingRoutes::class);
        $route = $routes->create($this->admin->id, ['route_code' => 'EMB', 'name' => 'Embroidery inspection count', 'steps' => [['processing_service_id' => $embroidery->id, 'input_item_id' => $this->item->id, 'output_item_id' => $this->item->id, 'planned_yield_ratio' => '1', 'permitted_normal_loss_fraction' => '0']]]);
        $routes->publish($this->admin->id, $route->id);
        $this->route = $route;
        $this->capability = $cap;
        $d = $this->dispatch($this->job(), $this->position($this->opening()));
        try {
            $this->processingReceipt($d, '100', '100', false, ['accepted_service_quantity' => '20']);
            $this->fail('Missing count evidence accepted');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('evidence', $e->getMessage());
        }
        $h = ProcessingDispatch::find($d->id);
        $r = $this->processingPost($this->processingDraft('receipt', ['processing_job_document_id' => $h->processing_job_document_id, 'vendor_party_role_id' => $this->vendor->id, 'remarks' => 'Inspection count sheet E-001: twenty embroidered panels.', 'lines' => [['processing_dispatch_line_id' => $h->lines->first()->id, 'disposition' => 'accepted', 'source_quantity' => '100', 'output_quantity' => '100', 'accepted_service_quantity' => '20', 'receiving_location_id' => $this->source->id, 'lot_number' => 'EMB-OUTPUT', 'quality_status' => 'released']]]));
        $this->assertSame('100.0000', ProcessingReceipt::find($r->id)->lines->first()->service_accrual_pkr);
    }

    public function test_calculated_processing_charge_outside_decimal_range_is_rejected(): void
    {
        $job = $this->processingPost($this->processingDraft('job', ['processing_route_version_id' => $this->route->id, 'planned_input_quantity' => '100000', 'lines' => [['vendor_service_id' => $this->capability->id, 'agreed_unit_rate_pkr' => '200000000000', 'override_reason' => 'Authorized test of numeric range boundary']]]));
        $dispatch = $this->dispatch($job, $this->position($this->opening('100000')), '100000');
        $before = StockMovement::count();
        try {
            $this->processingReceipt($dispatch, '100000', '100000', false);
            $this->fail('Unrepresentable amount accepted');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('precision', $e->getMessage());
        }
        $this->assertSame($before, StockMovement::count());
    }

    public function test_compatible_length_billing_requires_exact_item_conversion(): void
    {
        $unit = Unit::create(['code' => 'BCM', 'name' => 'Billing centimeter', 'dimension' => 'length', 'decimal_places' => 3, 'is_active' => true]);
        ItemUnitConversion::create(['item_id' => $this->item->id, 'unit_id' => $unit->id, 'factor_to_base' => '0.01']);
        $svc = ProcessingService::create(['code' => 'cm-finishing', 'name' => 'Finishing per centimeter', 'billing_unit_id' => $unit->id, 'is_active' => true]);
        $cap = VendorService::create(['vendor_party_role_id' => $this->vendor->id, 'processing_service_id' => $svc->id, 'is_active' => true]);
        VendorServiceRate::create(['vendor_service_id' => $cap->id, 'billing_unit_id' => $unit->id, 'unit_rate' => '0.5', 'currency_code' => 'PKR', 'effective_from' => $this->date, 'approved_by' => $this->admin->id, 'rate_reason' => 'Signed length conversion contract']);
        $routes = app(ProcessingRoutes::class);
        $route = $routes->create($this->admin->id, ['route_code' => 'BILL-LENGTH', 'name' => 'Converted service billing', 'steps' => [['processing_service_id' => $svc->id, 'input_item_id' => $this->item->id, 'output_item_id' => $this->item->id, 'planned_yield_ratio' => '1', 'permitted_normal_loss_fraction' => '0']]]);
        $routes->publish($this->admin->id, $route->id);
        $this->route = $route;
        $this->capability = $cap;
        $d = $this->dispatch($this->job(), $this->position($this->opening()));
        try {
            $this->processingReceipt($d, '100', '100', false, ['accepted_service_quantity' => '9999']);
            $this->fail('Incompatible conversion accepted');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('conversion', $e->getMessage());
        }
        $r = $this->processingReceipt($d, '100', '100', true, ['accepted_service_quantity' => '10000']);
        $this->assertSame('5000.0000', ProcessingReceipt::find($r->id)->lines->first()->service_accrual_pkr);
    }

    public function test_posting_rejects_a_draft_whose_covering_accounting_period_changed(): void
    {
        $j = $this->job(false);
        $old = AccountingPeriod::findOrFail($j->accounting_period_id);
        $old->update(['ends_on' => today()->subDay()->format('Y-m-d')]);
        AccountingPeriod::create(['financial_year_id' => $j->financial_year_id, 'code' => 'REPLACEMENT', 'starts_on' => $this->date, 'ends_on' => today()->endOfYear()->format('Y-m-d'), 'status' => 'open']);
        try {
            $this->processingPost($j);
            $this->fail('Posted into a stale accounting period');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('calendar changed', $e->getMessage());
        }
        $this->assertSame('draft', $j->fresh()->status);
    }
}
