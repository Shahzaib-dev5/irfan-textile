<?php

namespace Tests\Feature;

use App\Models\AccessRole;
use App\Models\Document;
use App\Models\GoodsReceipt;
use App\Models\InventoryValueEntry;
use App\Models\ItemUnitConversion;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\PartyOpenItem;
use App\Models\PartyRoleType;
use App\Models\Permission;
use App\Models\PostingAccount;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Modules\Inventory\Services\InventoryInquiry;
use App\Modules\Inventory\Services\StockLedger;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\PurchasingFixtures;
use Tests\TestCase;

class PurchasingTest extends TestCase
{
    public function test_schema_uses_approved_datetime_precision_and_blocks_future_dimensions(): void
    {
        $column = DB::table('information_schema.columns')->where('table_schema', config('database.connections.mysql.database'))->where('table_name', 'purchase_orders')->where('column_name', 'created_at')->first();
        $this->assertSame('datetime', $column->DATA_TYPE);
        $this->assertSame('NO', $column->IS_NULLABLE);
        $this->assertSame(6, (int) $column->DATETIME_PRECISION);
        $order = $this->order('1', '1', false);
        $line = PurchaseOrder::findOrFail($order->id)->lines->first();
        try {
            DB::table('purchase_order_lines')->where('id', $line->id)->update(['tax_rate_id' => 999]);
            $this->fail('Unsupported tax dimension must remain NULL.');
        } catch (QueryException $exception) {
            $this->assertNotEmpty($exception->errorInfo);
        }
    }

    use PurchasingFixtures,RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->purchasingFixtures();
    }

    private function rejects(callable $action): void
    {
        try {
            $action();
            $this->fail('Expected purchase validation rejection.');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }
    }

    public function test_approved_order_full_receipt_creates_stock_and_grni_once(): void
    {
        $order = $this->order();
        $this->assertSame('posted', $order->status);
        $this->assertSame(0, StockMovement::count());
        $this->assertSame(0, JournalEntry::count());
        $receipt = $this->goods($order, '100', '0', false);
        $key = (string) Str::uuid();
        $posted = $this->purchasePost($receipt, $key);
        $this->assertStringStartsWith('GRN-', $posted->number);
        $this->assertSame($posted->id, $this->purchasing->post($this->admin->id, $receipt->id, $key, 0)->id);
        $this->assertSame(1, StockMovement::count());
        $this->assertSame(1, JournalEntry::count());
        $this->assertSame('100.000000', $this->purchasing->accepted(PurchaseOrder::find($order->id)->lines->first()->id));
        $this->assertSame('1000.0000', $posted->journal->total_debit);
        $this->assertSame([], app(InventoryInquiry::class)->reconciliation());
        $this->rejects(fn () => $this->purchasePost($receipt));
    }

    public function test_partial_receipts_rejections_and_zero_over_receipt_tolerance(): void
    {
        $order = $this->order();
        $first = $this->goods($order, '40', '5');
        $line = GoodsReceipt::find($first->id)->lines->first();
        $this->assertSame('45.000000', $line->received_quantity);
        $this->assertSame('40.000000', $line->accepted_base_quantity);
        $this->assertSame('40.000000', app(StockLedger::class)->onHand($line->stock_position_id));
        $this->goods($order, '60');
        $this->rejects(fn () => $this->goods($order, '0.001'));
        $this->assertSame(2, StockMovement::count());
        $this->purchasing->rejectedDelivery($this->admin->id, $order->id, ['purchase_order_line_id' => PurchaseOrder::find($order->id)->lines->first()->id, 'rejected_quantity' => '2', 'supplier_challan_number' => 'DC-REJECT', 'reason' => 'Entire delivery rejected before acceptance.']);
        $this->assertDatabaseHas('audit_events', ['document_id' => $order->id, 'action' => 'delivery_rejected']);
        $this->assertSame(2, StockMovement::count());
    }

    public function test_order_requires_submission_approval_and_explicit_remaining_balance_closure(): void
    {
        $order = $this->order('100', '10', false);
        $this->rejects(fn () => $this->purchasePost($order));
        $this->rejects(fn () => $this->goods($order, '1'));
        $this->purchasing->submit($this->admin->id, $order->id, 0, (string) Str::uuid());
        $this->purchasing->orderDecision($this->admin->id, $order->id, 1, 'return_to_draft', 'Quantity needs purchasing review.');
        $this->assertSame('draft', $this->purchasing->workflow($order->fresh()));
        $this->purchasing->submit($this->admin->id, $order->id, 2, (string) Str::uuid());
        $this->purchasePost($order);
        $this->goods($order, '20');
        $this->purchasing->orderDecision($this->admin->id, $order->id, 0, 'close', 'Cancel unfulfilled balance with supplier.');
        $this->assertTrue($this->purchasing->closed($order->id));
        $this->rejects(fn () => $this->goods($order, '1'));
        $this->assertSame('posted', $order->fresh()->status);
        $this->assertSame(1, StockMovement::count());
    }

    public function test_matched_supplier_bill_clears_grni_and_creates_one_payable_without_stock_duplication(): void
    {
        $receipt = $this->goods($this->order());
        $bill = $this->supplierBill($receipt, '100', '10', false);
        $key = (string) Str::uuid();
        $this->purchasePost($bill, $key);
        $this->assertSame(1, StockMovement::count());
        $this->assertSame(2, JournalEntry::count());
        $this->assertSame(1, PartyOpenItem::count());
        $this->assertSame('1000.0000', PartyOpenItem::first()->original_amount);
        $grni = PostingAccount::where('purpose_code', 'grni')->value('ledger_account_id');
        $this->assertSame('0.0000', (string) JournalLine::where('ledger_account_id', $grni)->sum(DB::raw('debit-credit')));
        $this->purchasing->post($this->admin->id, $bill->id, $key, 0);
        $this->assertSame(1, PartyOpenItem::count());
        $this->assertSame([], app(InventoryInquiry::class)->reconciliation());
    }

    public function test_partial_bills_cannot_bill_the_same_receipt_twice_and_reference_is_normalized(): void
    {
        $receipt = $this->goods($this->order());
        $this->supplierBill($receipt, '40', '10', true, ' inv-123 ');
        $this->supplierBill($receipt, '60', '10');
        $this->assertSame(2, PartyOpenItem::count());
        $this->assertSame('INV-123', PurchaseInvoice::where('supplier_invoice_number', 'INV-123')->firstOrFail()->supplier_invoice_number);
        $this->rejects(fn () => $this->supplierBill($receipt, '1', '10'));
        $other = $this->goods($this->order());
        $this->rejects(fn () => $this->supplierBill($other, '10', '10', false, 'INV-123'));
    }

    public function test_bill_variance_rejects_atomically_without_second_inventory_or_payable(): void
    {
        $receipt = $this->goods($this->order());
        $bill = $this->supplierBill($receipt, '100', '11', false);
        $this->rejects(fn () => $this->purchasePost($bill));
        $this->assertSame('draft', $bill->fresh()->status);
        $this->assertSame(0, PartyOpenItem::count());
        $this->assertSame(1, StockMovement::count());
        $this->assertSame(1, JournalEntry::count());
    }

    public function test_unbilled_return_relieves_stock_and_grni_and_never_reopens_order_capacity(): void
    {
        $order = $this->order();
        $receipt = $this->goods($order);
        $return = $this->supplierReturn($receipt, '40');
        $this->assertSame('600.0000', (string) InventoryValueEntry::sum('amount_pkr'));
        $this->assertSame(0, PartyOpenItem::count());
        $this->supplierBill($receipt, '60', '10');
        $this->assertSame('600.0000', PartyOpenItem::first()->original_amount);
        $this->rejects(fn () => $this->goods($order, '1'));
        $this->rejects(fn () => $this->supplierReturn($receipt, '61'));
        $this->assertSame([], app(InventoryInquiry::class)->reconciliation());
    }

    public function test_invoiced_return_preserves_payable_and_posts_supplier_return_clearing(): void
    {
        $receipt = $this->goods($this->order());
        $this->supplierBill($receipt);
        $return = $this->supplierReturn($receipt, '30');
        $this->assertSame('1000.0000', PartyOpenItem::first()->original_amount);
        $this->assertSame(1, PartyOpenItem::count());
        $clearing = PostingAccount::where('purpose_code', 'supplier_return_clearing')->value('ledger_account_id');
        $this->assertDatabaseHas('journal_lines', ['journal_entry_id' => $return->id, 'ledger_account_id' => $clearing, 'debit' => '300.0000']);
        $this->assertSame([], app(InventoryInquiry::class)->reconciliation());
    }

    public function test_wrong_supplier_roles_and_mismatched_receipt_supplier_are_rejected(): void
    {
        $this->supplier->update(['is_active' => false]);
        $this->rejects(fn () => $this->order());
        $this->supplier->update(['is_active' => true]);
        $order = $this->order();
        $this->supplier->update(['party_role_type_id' => PartyRoleType::where('code', 'vendor')->value('id')]);
        $this->rejects(fn () => $this->goods($order));
        $this->assertSame(0, StockMovement::count());
    }

    public function test_conversion_and_order_price_snapshots_survive_master_changes(): void
    {
        $unit = Unit::factory()->create(['code' => 'CM-TEST', 'name' => 'Centimeter test', 'dimension' => 'length', 'decimal_places' => 3]);
        $conversion = ItemUnitConversion::factory()->create(['item_id' => $this->item->id, 'unit_id' => $unit->id, 'factor_to_base' => '0.01']);
        $order = $this->order('10000', '0.10', true, ['unit_id' => $unit->id]);
        $conversion->update(['factor_to_base' => '0.02']);
        $receipt = $this->goods($order, '4000');
        $line = GoodsReceipt::find($receipt->id)->lines->first();
        $this->assertSame('40.000000', $line->accepted_base_quantity);
        $this->assertSame('10.00000000', $line->provisional_unit_cost);
        $this->supplierBill($receipt, '40', '10');
        $this->assertSame('400.0000', PartyOpenItem::first()->original_amount);
    }

    public function test_forms_lists_print_history_and_payable_sources_render(): void
    {
        $order = $this->order();
        $receipt = $this->goods($order);
        $bill = $this->supplierBill($receipt);
        $return = $this->supplierReturn($receipt, '10');
        foreach (['purchasing.index', 'purchasing.history', 'purchasing.payables'] as $route) {
            $this->get(route($route))->assertOk();
        }
        foreach (['order', 'receipt', 'return', 'bill'] as $kind) {
            $this->get(route('purchasing.create', ['kind' => $kind]))->assertOk();
        }
        foreach ([$order, $receipt, $bill, $return] as $document) {
            $this->get(route('purchasing.show', $document))->assertOk();
        }
        $this->get(route('purchasing.print', $order))->assertOk()->assertSee('Print purchase order');
        $this->get(route('inventory.movements'))->assertOk()->assertSee(route('purchasing.show', $receipt), false);
        $draft = $this->order('50', '12', false);
        $this->get(route('purchasing.edit', $draft))->assertOk();
    }

    public function test_module_permissions_and_inventory_routes_cannot_bypass_purchase_authorization(): void
    {
        $order = $this->order('100', '10', false);
        $viewer = User::factory()->create();
        $role = AccessRole::create(['code' => 'purchase-viewer', 'name' => 'Purchase viewer', 'is_active' => true]);
        $role->permissions()->attach(Permission::where('code', 'purchasing.documents.view')->value('id'));
        $viewer->accessRoles()->attach($role->id, ['assigned_by' => $this->admin->id]);
        $this->actingAs($viewer)->get(route('purchasing.index'))->assertOk();
        $this->get(route('purchasing.create'))->assertForbidden();
        $this->post(route('purchasing.action', [$order, 'post']), ['lock_version' => 0, 'request_key' => (string) Str::uuid()])->assertForbidden();
        $this->actingAs($this->admin)->get(route('inventory.documents.show', $order))->assertNotFound();
        $this->post(route('inventory.documents.action', [$order, 'cancel']), ['lock_version' => 0, 'reason' => 'Attempt cross-module cancellation.'])->assertNotFound();
        $this->assertSame('draft', $order->fresh()->status);
    }

    public function test_http_create_edit_submit_approve_and_cancel_checks_versions(): void
    {
        $data = ['kind' => 'order', 'idempotency_key' => (string) Str::uuid(), 'document_date' => $this->date, 'supplier_party_role_id' => $this->supplier->id, 'warehouse_id' => $this->source->warehouse_id, 'lines' => [['item_id' => $this->item->id, 'unit_id' => $this->item->base_unit_id, 'quantity' => '100', 'unit_price' => '10']]];
        $this->post(route('purchasing.store'), $data)->assertSessionHasNoErrors()->assertRedirect();
        $order = Document::where('idempotency_key', $data['idempotency_key'])->firstOrFail();
        $this->assertSame($order->id, $this->purchasing->save($this->admin->id, $data)->id);
        $data['lock_version'] = 0;
        $data['notes'] = 'Reviewed commercial order terms.';
        $this->put(route('purchasing.update', $order), $data)->assertSessionHasNoErrors()->assertRedirect();
        $this->put(route('purchasing.update', $order), $data)->assertSessionHasErrors('inventory');
        $this->post(route('purchasing.action', [$order, 'submit']), ['lock_version' => 1, 'request_key' => (string) Str::uuid()])->assertSessionHasNoErrors()->assertRedirect();
        $this->get(route('purchasing.edit', $order))->assertForbidden();
        $this->post(route('purchasing.action', [$order, 'post']), ['lock_version' => 2])->assertSessionHasErrors('request_key');
        $this->post(route('purchasing.action', [$order, 'post']), ['lock_version' => 2, 'request_key' => (string) Str::uuid()])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('posted', $order->fresh()->status);
        $draft = $this->order('10', '10', false);
        $this->post(route('purchasing.action', [$draft, 'cancel']), ['lock_version' => 0, 'reason' => 'Supplier cannot meet delivery date.'])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('cancelled', $draft->fresh()->status);
        $this->assertNull($draft->fresh()->number);
    }

    public function test_tracked_receipts_and_returns_preserve_physical_roll_identity(): void
    {
        $this->item->update(['track_rolls' => true]);
        $order = $this->order('50');
        $receipt = $this->goods($order, '50', '0', true, ['roll_number' => 'SUP-ROLL-001']);
        $this->assertDatabaseHas('fabric_rolls', ['roll_number' => 'SUP-ROLL-001']);
        $partial = $this->supplierReturn($receipt, '10', false);
        $this->rejects(fn () => $this->purchasePost($partial));
        $this->supplierReturn($receipt, '50');
        $line = GoodsReceipt::find($receipt->id)->lines->first();
        $this->assertSame('0.000000', app(StockLedger::class)->onHand($line->stock_position_id));
    }

    public function test_returns_invalidate_draft_billing_and_reservations_block_stock_return(): void
    {
        $receipt = $this->goods($this->order());
        $bill = $this->supplierBill($receipt, '100', '10', false);
        $this->supplierReturn($receipt, '20');
        $this->rejects(fn () => $this->purchasePost($bill));
        $this->assertSame(0, PartyOpenItem::count());
        $position = GoodsReceipt::find($receipt->id)->lines->first()->stock_position_id;
        $transfer = $this->transfer($position, '70');
        $this->inventory->reserve($this->admin->id, $transfer->id, 0);
        $return = $this->supplierReturn($receipt, '20', false);
        $this->rejects(fn () => $this->purchasePost($return));
        $this->assertSame('80.000000', app(StockLedger::class)->onHand($position));
    }
}
