<?php

namespace Tests\Feature;

use App\Models\AccessRole;
use App\Models\AuditEvent;
use App\Models\Document;
use App\Models\GoodsReceipt;
use App\Models\JournalEntry;
use App\Models\PartyOpenItem;
use App\Models\Permission;
use App\Models\PurchaseOrder;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\PurchasingFixtures;
use Tests\TestCase;

class SimplePurchasingFlowTest extends TestCase
{
    use PurchasingFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->purchasingFixtures();
    }

    private function orderData(): array
    {
        return ['intent' => 'confirm', 'kind' => 'order', 'document_date' => $this->date, 'idempotency_key' => (string) Str::uuid(), 'supplier_party_role_id' => $this->supplier->id, 'warehouse_id' => $this->source->warehouse_id, 'lines' => [['item_id' => $this->item->id, 'unit_id' => $this->item->base_unit_id, 'quantity' => '100', 'unit_price' => '10']]];
    }

    private function submitData(string $kind, array $data): Document
    {
        $data += ['intent' => 'confirm', 'kind' => $kind, 'document_date' => $this->date, 'idempotency_key' => (string) Str::uuid(), 'supplier_party_role_id' => $this->supplier->id, 'warehouse_id' => $this->source->warehouse_id];
        $this->post(route('purchasing.'.['order' => 'orders', 'receipt' => 'receipts', 'return' => 'returns', 'bill' => 'bills'][$kind].'.store'), $data)->assertRedirect()->assertSessionHasNoErrors();

        return Document::where('idempotency_key', $data['idempotency_key'])->firstOrFail();
    }

    public function test_admin_confirms_full_purchase_lifecycle_and_downloads_all_four_documents(): void
    {
        $order = $this->submitData('order', $this->orderData());
        $this->assertSame('posted', $order->status);
        $this->assertSame(0, StockMovement::count());
        $this->assertDatabaseHas('audit_events', ['document_id' => $order->id, 'action' => 'order_submitted']);
        $receipt = $this->submitData('receipt', ['lines' => [['purchase_order_line_id' => PurchaseOrder::find($order->id)->lines->first()->id, 'warehouse_location_id' => $this->source->id, 'accepted_quantity' => '60', 'rejected_quantity' => '0', 'lot_number' => 'SIMPLE-GRN', 'quality_status' => 'released']]]);
        $this->assertSame('posted', $receipt->status);
        $line = GoodsReceipt::find($receipt->id)->lines->first();
        $returned = $this->submitData('return', ['reason' => 'Defective goods returned to supplier.', 'lines' => [['goods_receipt_line_id' => $line->id, 'stock_position_id' => $line->stock_position_id, 'base_quantity' => '10', 'reason' => 'Supplier accepted the defective fabric.']]]);
        $bill = $this->submitData('bill', ['supplier_invoice_number' => 'SIMPLE-BILL', 'supplier_invoice_date' => $this->date, 'due_on' => $this->date, 'lines' => [['goods_receipt_line_id' => $line->id, 'quantity' => '50', 'unit_price' => '10']]]);
        $this->assertSame('50.000000', bcadd((string) StockMovement::sum('quantity_delta'), '0', 6));
        $this->assertSame('500.0000', PartyOpenItem::first()->original_amount);
        foreach ([$order, $receipt, $returned, $bill] as $doc) {
            $this->assertSame('posted', $doc->status);
            $this->get(route('purchasing.print', $doc))->assertOk()->assertSee('Confirmed')->assertDontSee('sidebar')->assertSee('Prepared by');
            $pdf = $this->get(route('purchasing.pdf', $doc))->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertHeader('Cache-Control', 'no-store, private');
            $this->assertStringStartsWith('%PDF-', $pdf->getContent());
            $this->assertGreaterThan(10000, strlen($pdf->getContent()));
            if (getenv('TRANSACTION_PDF_FIXTURES')) {
                $dir = storage_path('framework/testing/transaction-pdf');
                if (! is_dir($dir)) {
                    mkdir($dir, 0777, true);
                } file_put_contents($dir.'/purchasing-'.$this->purchasing->kind($doc).'.pdf', $pdf->getContent());
            }
        }
        $this->assertSame(2, StockMovement::count());
        $this->assertSame(1, PartyOpenItem::count());
    }

    public function test_confirm_retry_is_idempotent_and_different_payload_is_rejected(): void
    {
        $data = $this->orderData();
        $a = $this->submitData('order', $data);
        $b = $this->submitData('order', $data);
        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, Document::count());
        $this->assertSame(1, AuditEvent::where('document_id', $a->id)->where('action', 'order_submitted')->count());
        $data['lines'][0]['quantity'] = '200';
        $this->post(route('purchasing.orders.store'), $data)->assertSessionHasErrors('inventory');
        $this->assertSame(1, Document::count());
    }

    public function test_saved_order_can_be_confirmed_in_one_action_with_stale_version_protection(): void
    {
        $draft = $this->order('100', '10', false);
        $key = (string) Str::uuid();
        $this->post(route('purchasing.action', [$draft, 'confirm']), ['lock_version' => 0, 'request_key' => $key])->assertSessionHasNoErrors();
        $this->post(route('purchasing.action', [$draft, 'confirm']), ['lock_version' => 0, 'request_key' => $key])->assertSessionHasNoErrors();
        $this->assertSame('posted', $draft->fresh()->status);
        $this->post(route('purchasing.action', [$draft, 'confirm']), ['lock_version' => 0, 'request_key' => (string) Str::uuid()])->assertSessionHasErrors('inventory');
    }

    public function test_confirm_failure_rolls_back_new_draft_lots_audit_and_movements(): void
    {
        $order = $this->order();
        $line = PurchaseOrder::find($order->id)->lines->first();
        // Preparation permits pending stock at contractor-ineligible receiving storage;
        // final posting rechecks the active warehouse and must roll preparation back.
        $this->source->is_active = false;
        $this->source->save();
        $before = Document::count();
        $audit = AuditEvent::count();
        $this->post(route('purchasing.receipts.store'), ['intent' => 'confirm', 'document_date' => $this->date, 'idempotency_key' => (string) Str::uuid(), 'supplier_party_role_id' => $this->supplier->id, 'warehouse_id' => $this->source->warehouse_id, 'lines' => [['purchase_order_line_id' => $line->id, 'warehouse_location_id' => $this->source->id, 'accepted_quantity' => '40', 'lot_number' => 'MUST-ROLL-BACK', 'quality_status' => 'released']]])->assertSessionHasErrors('inventory');
        $this->assertSame($before, Document::count());
        $this->assertSame($audit, AuditEvent::count());
        $this->assertDatabaseMissing('stock_lots', ['lot_number' => 'MUST-ROLL-BACK']);
        $this->assertSame(0, StockMovement::count());
        $this->assertSame(0, JournalEntry::count());
    }

    public function test_save_for_later_and_manager_permissions_are_preserved(): void
    {
        $role = AccessRole::create(['code' => 'prepare-only', 'name' => 'Prepare only']);
        $role->permissions()->attach(Permission::whereIn('code', ['purchasing.documents.view', 'purchasing.documents.manage'])->pluck('id'));
        $user = User::factory()->create();
        $user->accessRoles()->attach($role->id, ['assigned_by' => $this->admin->id]);
        $this->actingAs($user);
        $this->get(route('purchasing.orders.create'))->assertOk()->assertSee('Save for later')->assertDontSee('value="confirm"', false);
        $data = $this->orderData();
        $data['intent'] = 'save';
        $draft = $this->submitData('order', $data);
        $this->assertSame('draft', $draft->status);
        $data = $this->orderData();
        $this->post(route('purchasing.orders.store'), $data)->assertForbidden();
        $this->get(route('purchasing.print', $draft))->assertOk()->assertSee('DRAFT - For review only.');
        $this->assertSame(1, Document::count());
    }

    public function test_wholesale_user_cannot_enter_purchasing_processing_or_download_their_pdfs(): void
    {
        $draft = $this->order('100', '10', false);
        $role = AccessRole::create(['code' => 'wholesale-test', 'name' => 'Wholesale only']);
        $role->permissions()->attach(Permission::where('code', 'sales.customer.view')->pluck('id'));
        $user = User::factory()->create();
        $user->accessRoles()->attach($role->id, ['assigned_by' => $this->admin->id]);
        $this->actingAs($user);
        foreach (['/purchasing', '/processing', route('purchasing.pdf', $draft), route('purchasing.print', $draft), route('processing.pdf', $draft)] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->post(route('purchasing.orders.store'), $this->orderData())->assertForbidden();
        $this->post(route('purchasing.action', [$draft, 'confirm']), ['request_key' => (string) Str::uuid(), 'lock_version' => 0])->assertForbidden();
        $this->post(route('processing.jobs.store'), [])->assertForbidden();
        $this->assertSame('draft', $draft->fresh()->status);
    }
}
