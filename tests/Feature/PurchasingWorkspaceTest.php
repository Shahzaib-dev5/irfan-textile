<?php

namespace Tests\Feature;

use App\Models\AccessRole;
use App\Models\Document;
use App\Models\GoodsReceipt;
use App\Models\Permission;
use App\Models\PurchaseOrder;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\PurchasingFixtures;
use Tests\TestCase;

class PurchasingWorkspaceTest extends TestCase
{
    use PurchasingFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->purchasingFixtures();
    }

    private function limitedUser(array $permissions): User
    {
        $role = AccessRole::create(['code' => 'purchase-ui-'.Str::random(8), 'name' => 'Purchase operator']);
        $role->permissions()->attach(Permission::whereIn('code', $permissions)->pluck('id'));
        $user = User::factory()->create();
        $user->accessRoles()->attach($role->id, ['assigned_by' => $this->admin->id]);

        return $user;
    }

    private function browserFixture(string $name, $response): void
    {
        if (! getenv('PURCHASING_BROWSER_FIXTURES')) {
            return;
        }
        $directory = storage_path('framework/testing/purchasing-browser');
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }
        file_put_contents($directory.'/'.$name.'.html', $response->getContent());
    }

    public function test_separate_pages_and_legacy_archive_preserve_type_and_detail_scope(): void
    {
        $order = $this->order();
        $receipt = $this->goods($order, '60');
        $bill = $this->supplierBill($receipt, '20');
        $return = $this->supplierReturn($receipt, '10');
        foreach (['/purchasing', '/purchasing/orders', '/purchasing/receipts', '/purchasing/returns', '/purchasing/bills', '/purchasing/history', '/purchasing/payable-sources', '/purchasing/documents'] as $path) {
            $this->get($path)->assertOk();
        }
        $this->get('/purchasing/orders?kind=bill')->assertViewHas('documents', fn ($rows) => $rows->count() === 1 && $rows->first()->id === $order->id);
        $this->get(route('purchasing.orders.show', $bill))->assertNotFound();
        foreach ([$order, $receipt, $bill, $return] as $doc) {
            $this->get(route('purchasing.print', $doc))->assertOk();
        }
    }

    public function test_direct_workflow_links_and_prefilled_sources_render(): void
    {
        $this->browserFixture('overview', $this->get('/purchasing')->assertOk()->assertSee('Before your first purchase'));
        $this->browserFixture('order', $this->get(route('purchasing.orders.create'))->assertOk());
        $order = $this->order();
        $this->get(route('purchasing.orders.show', $order))->assertSee(route('purchasing.receipts.create', ['order' => $order->id]));
        $response = $this->get(route('purchasing.receipts.create', ['order' => $order->id]))->assertOk()->assertViewHas('supplierId', $this->supplier->id)->assertViewHas('warehouseId', $this->source->warehouse_id)->assertViewHas('orderLines', fn ($lines) => $lines->count() === 1)->assertViewHas('locations', fn ($locations) => $locations->pluck('id')->all() === [$this->source->id]);
        $this->browserFixture('receipt', $response);
        $receipt = $this->goods($order, '60');
        $this->get(route('purchasing.receipts.show', $receipt))->assertSee(route('purchasing.bills.create', ['receipt' => $receipt->id]))->assertSee(route('purchasing.returns.create', ['receipt' => $receipt->id]));
        $this->browserFixture('bill', $this->get(route('purchasing.bills.create', ['receipt' => $receipt->id]))->assertOk());
        $this->browserFixture('return', $this->get(route('purchasing.returns.create', ['receipt' => $receipt->id]))->assertOk());
    }

    public function test_remaining_receipt_bill_and_return_quantities_come_from_posted_history(): void
    {
        $order = $this->order();
        $receipt = $this->goods($order, '60');
        $this->supplierBill($receipt, '20');
        $this->supplierReturn($receipt, '10');
        $line = GoodsReceipt::find($receipt->id)->lines->first();
        $this->get(route('purchasing.receipts.create', ['order' => $order->id]))->assertOk()->assertViewHas('orderCapacity', fn ($rows) => array_values($rows)[0]['quantity'] === '40.000000');
        $this->get(route('purchasing.bills.create', ['receipt' => $receipt->id]))->assertOk()->assertViewHas('receiptCapacity', fn ($rows) => $rows[$line->id]['billable'] === '30.000000');
        $this->get(route('purchasing.returns.create', ['receipt' => $receipt->id]))->assertOk()->assertViewHas('receiptCapacity', fn ($rows) => $rows[$line->id]['returnable'] === '50.000000')->assertViewHas('positions', fn ($rows) => $rows->first()->available_quantity === '50.000000');
    }

    public function test_fulfilled_or_closed_orders_and_fully_billed_receipts_show_empty_states(): void
    {
        $order = $this->order();
        $receipt = $this->goods($order);
        $this->get(route('purchasing.receipts.create', ['order' => $order->id]))->assertOk()->assertSee('No eligible lines in this selection');
        $this->supplierBill($receipt);
        $this->get(route('purchasing.bills.create', ['receipt' => $receipt->id]))->assertOk()->assertSee('No eligible lines in this selection');
        $this->get(route('purchasing.receipts.show', $receipt))->assertDontSee('Create supplier bill');
        $closed = $this->order();
        $this->purchasing->orderDecision($this->admin->id, $closed->id, $closed->lock_version, 'close', 'Supplier cannot fulfill the remaining order.');
        $this->get(route('purchasing.receipts.create', ['order' => $closed->id]))->assertOk()->assertSee('No eligible lines in this selection');
    }

    public function test_multi_order_and_multi_receipt_preparation_remains_available(): void
    {
        $a = $this->order();
        $b = $this->order();
        $original = $this->source;
        $this->source = $this->destination;
        $other = $this->order();
        $this->source = $original;
        $this->get(route('purchasing.receipts.create', ['supplier' => $this->supplier->id, 'warehouse' => $this->source->warehouse_id]))->assertOk()->assertViewHas('orderLines', fn ($rows) => $rows->pluck('document_id')->sort()->values()->all() === [$a->id, $b->id]);
        $ra = $this->goods($a, '40');
        $rb = $this->goods($b, '30');
        $this->get(route('purchasing.bills.create', ['supplier' => $this->supplier->id]))->assertOk()->assertViewHas('receiptLines', fn ($rows) => $rows->count() === 2);
        $this->get(route('purchasing.bills.create', ['receipt' => $ra->id]))->assertViewHas('receiptLines', fn ($rows) => $rows->count() === 1 && $rows->first()->document_id === $ra->id);
    }

    public function test_returns_follow_transferred_stock_and_exclude_reserved_positions(): void
    {
        $receipt = $this->goods($this->order());
        $line = GoodsReceipt::find($receipt->id)->lines->first();
        $transfer = $this->postDocument($this->transfer($line->stock_position_id, '100'));
        $target = $transfer->transfer->lines->first()->destination_stock_position_id;
        $url = route('purchasing.returns.create', ['receipt' => $receipt->id, 'warehouse' => $this->destination->warehouse_id]);
        $this->get($url)->assertOk()->assertViewHas('positions', fn ($rows) => $rows->count() === 1 && $rows->first()->id === $target);
        $reserved = $this->transfer($target, '100', $this->source->id);
        $this->inventory->reserve($this->admin->id, $reserved->id, $reserved->lock_version);
        $this->get($url)->assertOk()->assertViewHas('positions', fn ($rows) => $rows->isEmpty())->assertSee('No eligible lines in this selection');
    }

    public function test_editing_drafts_retains_exhausted_sources_and_original_input(): void
    {
        $order = $this->order();
        $draft = $this->goods($order, '20', '0', false);
        $this->goods($order);
        $this->get(route('purchasing.receipts.edit', $draft))->assertOk()->assertViewHas('orderLines', fn ($rows) => $rows->contains('document_id', $order->id))->assertSee('20.000000');
        $this->get(route('purchasing.receipts.edit', $order))->assertForbidden();
        $this->get(route('purchasing.receipts.edit', $this->order('100', '10', false)))->assertNotFound();
        $this->get(route('purchasing.receipts.edit', $this->goods($this->order())))->assertForbidden();
    }

    public function test_typed_save_edit_and_order_approval_keep_existing_lifecycle(): void
    {
        $data = ['kind' => 'bill', 'document_date' => $this->date, 'idempotency_key' => (string) Str::uuid(), 'supplier_party_role_id' => $this->supplier->id, 'warehouse_id' => $this->source->warehouse_id, 'lines' => [['item_id' => $this->item->id, 'unit_id' => $this->item->base_unit_id, 'quantity' => '100', 'unit_price' => '10', 'discount_amount' => '0']]];
        $this->post(route('purchasing.orders.store'), $data)->assertSessionHasNoErrors();
        $order = Document::latest('id')->first();
        $this->assertSame('order', $this->purchasing->kind($order));
        $data['lock_version'] = 0;
        $data['notes'] = 'Updated purchase requirements.';
        $this->put(route('purchasing.orders.update', $order), $data)->assertSessionHasNoErrors()->assertRedirect(route('purchasing.orders.show', $order));
        $this->post(route('purchasing.action', [$order, 'submit']), ['lock_version' => 1, 'request_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->get(route('purchasing.orders.edit', $order))->assertForbidden();
        $this->post(route('purchasing.action', [$order, 'post']), ['lock_version' => 2, 'request_key' => (string) Str::uuid()])->assertSessionHasNoErrors()->assertRedirect(route('purchasing.orders.show', $order));
        $this->assertSame('posted', $order->fresh()->status);
        $this->assertSame(0, StockMovement::count());
    }

    public function test_new_urls_and_navigation_respect_separate_document_and_supplier_permissions(): void
    {
        $read = $this->limitedUser(['purchasing.documents.view']);
        $this->actingAs($read)->get('/purchasing')->assertOk()->assertDontSee(route('supplier.index'));
        foreach (['orders', 'receipts', 'returns', 'bills'] as $type) {
            $this->get('/purchasing/'.$type)->assertOk();
            $this->get('/purchasing/'.$type.'/create')->assertForbidden();
            $this->post('/purchasing/'.$type, [])->assertForbidden();
        }
        $supplier = $this->limitedUser(['purchasing.supplier.view']);
        $this->actingAs($supplier)->get(route('supplier.index'))->assertOk()->assertSee('purchasing-sidebar-links')->assertDontSee(route('purchasing.orders.index'));
        foreach (['', '/orders', '/receipts', '/returns', '/bills', '/history', '/payable-sources'] as $path) {
            $this->get('/purchasing'.$path)->assertForbidden();
        }
    }

    public function test_payable_source_links_use_the_bill_document_instead_of_the_journal_id(): void
    {
        $bill = $this->supplierBill($this->goods($this->order()));
        $this->assertNotSame($bill->id, $bill->journal->id);
        $this->get(route('purchasing.payables'))->assertOk()->assertSee(route('purchasing.bills.show', $bill));
        $this->get(route('purchasing.bills.show', $bill))->assertOk()->assertSee('View supplier payable sources');
    }

    public function test_invalid_sources_and_validation_input_are_handled_safely(): void
    {
        $this->get('/purchasing/receipts/create?order=999999')->assertNotFound();
        $this->get(route('purchasing.receipts.create', ['order' => $this->order('100', '10', false)->id]))->assertNotFound();
        $order = $this->order();
        $po = PurchaseOrder::find($order->id)->lines->first();
        $url = route('purchasing.receipts.create', ['order' => $order->id]);
        $this->from($url)->post(route('purchasing.receipts.store'), ['document_date' => $this->date, 'idempotency_key' => (string) Str::uuid(), 'supplier_party_role_id' => $this->supplier->id, 'warehouse_id' => $this->source->warehouse_id, 'lines' => [['purchase_order_line_id' => $po->id, 'warehouse_location_id' => $this->source->id, 'accepted_quantity' => '101', 'rejected_quantity' => '0', 'lot_number' => 'KEEP-GRN', 'quality_status' => 'released']]])->assertRedirect($url)->assertSessionHasErrors('inventory');
        $this->get($url)->assertOk()->assertSee('KEEP-GRN')->assertSee('101');
        $this->assertSame(0, GoodsReceipt::count());
    }
}
