<?php

namespace App\Modules\Inventory\Services;

use App\Models\Document;
use App\Models\GoodsReceipt;
use App\Models\Item;
use App\Models\PartyOpenItem;
use App\Models\PostingAccount;
use App\Models\PurchaseInvoice;
use App\Models\WarehouseLocation;
use App\Modules\Accounting\Services\FinanceService;
use App\Modules\Purchasing\Services\PurchasingService;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

class DirectFabricPurchase
{
    public function __construct(
        private PurchasingService $purchasing,
        private FinanceService $finance,
    ) {}

    public function confirm(int $actor, array $data): array
    {
        return DB::transaction(function () use ($actor, $data) {
            InventoryGuard::company();
            InventoryGuard::actor($actor, 'purchasing.documents.manage');
            InventoryGuard::actor($actor, 'purchasing.receipts.post');
            $hasInvoice = filled($data['supplier_invoice_number'] ?? null);
            if ($hasInvoice) {
                InventoryGuard::actor($actor, 'purchasing.bills.post');
            } elseif (($data['payment_status'] ?? 'unpaid') !== 'unpaid') {
                InventoryGuard::fail('Record this receipt first. Payments before an invoice use Finance supplier advances.', 'payment_status');
            }
            if (($data['payment_status'] ?? 'unpaid') !== 'unpaid') {
                InventoryGuard::actor($actor, 'accounting.finance.post');
            }
            $payloadHash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
            $confirmed = DB::table('direct_purchase_confirmations')->where('request_key', $data['idempotency_key'])->first();
            if ($confirmed) {
                if (! hash_equals($confirmed->payload_hash, $payloadHash)) {
                    InventoryGuard::fail('This confirmation key was already used with different details.', 'idempotency_key');
                }

                return ['receipt' => Document::findOrFail($confirmed->receipt_document_id), 'bill' => $confirmed->bill_document_id ? Document::findOrFail($confirmed->bill_document_id) : null, 'payment' => $confirmed->payment_document_id ? Document::findOrFail($confirmed->payment_document_id) : null, 'items' => GoodsReceipt::with('lines.item')->findOrFail($confirmed->receipt_document_id)->lines->pluck('item')];
            }
            $receiptKey = $this->key($data['idempotency_key'], 'receipt');
            $existingReceipt = Document::where('idempotency_key', $receiptKey)->first();
            $items = $existingReceipt
                ? GoodsReceipt::with('lines.item')->findOrFail($existingReceipt->id)->lines->pluck('item')->values()
                : collect($data['lines'])->map(fn ($line) => app(PurchaseMeasurement::class)->resolve($actor, $line));
            if ($items->count() !== count($data['lines'])) {
                InventoryGuard::fail('This confirmation key belongs to a different set of purchase lines.', 'lines');
            }
            foreach ($data['lines'] as $index => $line) {
                if ((! empty($line['item_id']) && (int) $line['item_id'] !== $items[$index]->id && (! $items[$index]->textile_definition_id || Item::findOrFail($line['item_id'])->textile_definition_id !== $items[$index]->textile_definition_id))
                    || (! empty($line['new_item']['sku']) && $line['new_item']['sku'] !== $items[$index]->sku)) {
                    InventoryGuard::fail('This confirmation key belongs to different purchase-line material.', 'lines');
                }
            }
            $measurements = collect($data['lines'])->values()->map(fn ($line, $index) => app(PurchaseMeasurement::class)->amounts($line, $items[$index]));
            $location = WarehouseLocation::with('warehouse')->findOrFail($data['warehouse_location_id']);
            $receipt = $this->purchasing->confirm($actor, [
                'kind' => 'receipt', 'direct_receipt' => true,
                'direct_receipt_reason' => 'Fabric or material received directly without a purchase order.',
                'idempotency_key' => $receiptKey, 'document_date' => $data['document_date'],
                'supplier_party_role_id' => $data['supplier_party_role_id'], 'warehouse_id' => $location->warehouse_id,
                'notes' => $data['notes'] ?? null,
                'lines' => collect($data['lines'])->values()->map(fn ($line, $index) => [
                    'item_id' => $items[$index]->id, 'unit_id' => $items[$index]->base_unit_id,
                    'warehouse_location_id' => $location->id,
                    'rejected_quantity' => '0', 'unit_cost' => $measurements[$index]['unit_cost'],
                    'lot_number' => $line['lot_number'], 'roll_number' => $line['roll_number'] ?? null,
                    'accepted_quantity' => $line['quantity'], 'quality_status' => 'released',
                    'inspection_notes' => 'Purchased as ready-to-use material.',
                ])->all(),
            ]);
            $receiptLines = GoodsReceipt::with('lines')->findOrFail($receipt->id)->lines;
            foreach ($receiptLines->values() as $index => $receiptLine) {
                if (! DB::table('receipt_measurements')->where('goods_receipt_line_id', $receiptLine->id)->exists()) {
                    DB::table('receipt_measurements')->insert(['goods_receipt_line_id' => $receiptLine->id] + $measurements[$index]['snapshot'] + ['created_at' => now(), 'updated_at' => now()]);
                }
            }
            $billKey = $this->key($data['idempotency_key'], 'bill');
            $bill = $hasInvoice ? $this->purchasing->confirm($actor, [
                'kind' => 'bill', 'idempotency_key' => $billKey, 'document_date' => $data['document_date'],
                'supplier_party_role_id' => $data['supplier_party_role_id'],
                'supplier_invoice_number' => $data['supplier_invoice_number'],
                'supplier_invoice_date' => $data['supplier_invoice_date'], 'due_on' => $data['due_on'],
                'notes' => $data['notes'] ?? null,
                'lines' => $receiptLines->map(fn ($line) => [
                    'goods_receipt_line_id' => $line->id, 'quantity' => $line->accepted_base_quantity,
                    'unit_price' => $line->provisional_unit_cost, 'discount_amount' => '0',
                    'description' => $line->item->name,
                ])->all(),
            ]) : null;
            $payment = null;
            if (($data['payment_status'] ?? 'unpaid') !== 'unpaid') {
                $total = (string) PurchaseInvoice::findOrFail($bill->id)->gross_amount;
                $amount = (string) $data['payment_amount'];
                if (($data['payment_status'] === 'paid' && bccomp($amount, $total, 4) !== 0)
                    || ($data['payment_status'] === 'partial' && bccomp($amount, $total, 4) >= 0)) {
                    InventoryGuard::fail('Paid in full must equal the bill total; a partial payment must be less than it.', 'payment_amount');
                }
                $openItem = PartyOpenItem::whereHas('journalLine', fn ($q) => $q->where('journal_entry_id', $bill->id))->firstOrFail();
                $payment = $this->finance->confirm($actor, [
                    'kind' => 'payment', 'idempotency_key' => $this->key($data['idempotency_key'], 'payment'),
                    'document_date' => $data['document_date'], 'description' => 'Payment for direct fabric purchase '.$bill->number,
                    'reference' => $data['payment_reference'] ?? $data['supplier_invoice_number'],
                    'party_role_id' => $data['supplier_party_role_id'], 'bank_account_id' => $data['bank_account_id'],
                    'settlement_account_id' => PostingAccount::where('purpose_code', 'supplier_payable')->value('ledger_account_id'),
                    'amount' => $amount, 'method' => $data['payment_method'],
                    'apply_to_open_item_id' => $openItem->id, 'apply_amount' => $amount,
                ]);
            }

            DB::table('direct_purchase_confirmations')->insert(['request_key' => $data['idempotency_key'], 'payload_hash' => $payloadHash, 'receipt_document_id' => $receipt->id, 'bill_document_id' => $bill?->id, 'payment_document_id' => $payment?->id, 'created_at' => now(), 'updated_at' => now()]);

            return compact('receipt', 'bill', 'payment', 'items');
        }, 3);
    }

    private function key(string $root, string $part): string
    {
        return (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'textile:direct-fabric:'.$root.':'.$part);
    }
}
