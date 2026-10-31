<?php

namespace Tests\Support;

use App\Models\Document;
use App\Models\GoodsReceipt;
use App\Models\Party;
use App\Models\PartyRole;
use App\Models\PartyRoleType;
use App\Models\PaymentTerm;
use App\Models\PurchaseOrder;
use App\Models\SupplierProfile;
use App\Modules\Purchasing\Services\PurchasingService;
use Illuminate\Support\Str;

trait PurchasingFixtures
{
    use InventoryFixtures;

    protected PartyRole $supplier;

    protected PurchasingService $purchasing;

    protected function purchasingFixtures(): void
    {
        $this->inventoryFixtures();
        $party = Party::create(['code' => 'SUP-TEST', 'legal_name' => 'Textile supplier', 'party_kind' => 'organization', 'is_active' => true]);
        $this->supplier = PartyRole::create(['party_id' => $party->id, 'party_role_type_id' => PartyRoleType::where('code', 'supplier')->value('id'), 'is_active' => true]);
        SupplierProfile::create(['party_role_id' => $this->supplier->id, 'payment_term_id' => PaymentTerm::firstOrFail()->id]);
        $this->purchasing = app(PurchasingService::class);
    }

    protected function order(string $qty = '100', string $price = '10', bool $approve = true, array $line = []): Document
    {
        $doc = $this->purchasing->save($this->admin->id, ['kind' => 'order', 'idempotency_key' => (string) Str::uuid(), 'document_date' => $this->date, 'supplier_party_role_id' => $this->supplier->id, 'warehouse_id' => $this->source->warehouse_id, 'notes' => 'Verified textile purchase commitment.', 'lines' => [array_replace(['item_id' => $this->item->id, 'unit_id' => $this->item->base_unit_id, 'quantity' => $qty, 'unit_price' => $price], $line)]]);
        if (! $approve) {
            return $doc;
        }
        $this->purchasing->submit($this->admin->id, $doc->id, 0, (string) Str::uuid());

        return $this->purchasing->post($this->admin->id, $doc->id, (string) Str::uuid(), 1);
    }

    protected function goods(Document $order, string $accepted = '100', string $rejected = '0', bool $post = true, array $overrides = []): Document
    {
        $po = PurchaseOrder::findOrFail($order->id);
        $data = ['kind' => 'receipt', 'idempotency_key' => (string) Str::uuid(), 'document_date' => $this->date, 'supplier_party_role_id' => $this->supplier->id, 'warehouse_id' => $this->source->warehouse_id, 'supplier_challan_number' => 'DC-'.Str::random(8), 'lines' => [array_replace(['purchase_order_line_id' => $po->lines->first()->id, 'warehouse_location_id' => $this->source->id, 'accepted_quantity' => $accepted, 'rejected_quantity' => $rejected, 'lot_number' => 'GR-'.Str::random(10), 'quality_status' => 'released', 'inspection_notes' => 'Inspection completed; rejected fabric remains supplier owned.'], $overrides)]];
        $doc = $this->purchasing->save($this->admin->id, $data);

        return $post ? $this->purchasePost($doc) : $doc;
    }

    protected function purchasePost(Document $doc, ?string $key = null): Document
    {
        return $this->purchasing->post($this->admin->id, $doc->id, $key ?? (string) Str::uuid(), $doc->fresh()->lock_version);
    }

    protected function supplierBill(Document $receipt, string $quantity = '100', string $price = '10', bool $post = true, ?string $reference = null): Document
    {
        $doc = $this->purchasing->save($this->admin->id, ['kind' => 'bill', 'idempotency_key' => (string) Str::uuid(), 'document_date' => $this->date, 'supplier_party_role_id' => $this->supplier->id, 'supplier_invoice_number' => $reference ?? 'INV-'.Str::random(8), 'supplier_invoice_date' => $this->date, 'due_on' => today()->addDays(30)->format('Y-m-d'), 'lines' => [['goods_receipt_line_id' => GoodsReceipt::findOrFail($receipt->id)->lines->first()->id, 'quantity' => $quantity, 'unit_price' => $price]]]);

        return $post ? $this->purchasePost($doc) : $doc;
    }

    protected function supplierReturn(Document $receipt, string $quantity, bool $post = true): Document
    {
        $line = GoodsReceipt::findOrFail($receipt->id)->lines->first();
        $doc = $this->purchasing->save($this->admin->id, ['kind' => 'return', 'idempotency_key' => (string) Str::uuid(), 'document_date' => $this->date, 'supplier_party_role_id' => $this->supplier->id, 'warehouse_id' => $this->source->warehouse_id, 'reason' => 'Supplier accepts defective fabric return.', 'lines' => [['goods_receipt_line_id' => $line->id, 'stock_position_id' => $line->stock_position_id, 'base_quantity' => $quantity, 'reason' => 'Fabric quality did not pass final inspection.']]]);

        return $post ? $this->purchasePost($doc) : $doc;
    }
}
