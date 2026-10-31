<?php

namespace App\Modules\Accounting\Services;

use App\Models\Document;
use App\Models\InventoryCostLink;
use App\Models\InventoryLayerAllocation;
use App\Models\JournalLine;
use App\Models\PostingAccount;
use App\Models\PurchaseCreditNote;
use App\Models\PurchaseCreditNoteLine;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceLine;
use App\Models\PurchaseInvoiceReceiptMatch;
use App\Models\PurchaseReturnLine;
use App\Modules\Inventory\Services\InventoryGuard as G;
use App\Modules\Inventory\Support\Decimal as D;

class SupplierCreditService
{
    public function confirm(int $actor, array $input): Document
    {
        $data = validator($input, ['idempotency_key' => 'required|uuid', 'document_date' => 'required|date_format:Y-m-d', 'description' => 'required|string|min:5|max:500', 'purchase_invoice_id' => 'required|integer|exists:purchase_invoices,document_id', 'supplier_credit_number' => 'required|string|max:100', 'lines' => 'required|array|min:1|max:100', 'lines.*' => 'required|array:purchase_return_line_id,purchase_invoice_receipt_match_id,credited_quantity', 'lines.*.purchase_return_line_id' => 'required|integer|exists:purchase_return_lines,id', 'lines.*.purchase_invoice_receipt_match_id' => 'required|integer|exists:purchase_invoice_receipt_matches,id', 'lines.*.credited_quantity' => 'required|decimal:0,6|gt:0'])->validate();
        $data['supplier_credit_number'] = mb_strtoupper(trim($data['supplier_credit_number']));
        $data['kind'] = 'supplier_credit';

        return app(FinanceService::class)->execute($actor, $data, function (Document $doc) use ($data) {
            $invoice = PurchaseInvoice::with('document', 'supplier')->lockForUpdate()->findOrFail($data['purchase_invoice_id']);
            if ($invoice->document->status !== 'posted' || $invoice->document->document_date->format('Y-m-d') > $data['document_date']) {
                G::fail('Choose a posted supplier invoice dated before this credit.');
            }
            $role = app(FinanceService::class)->role($invoice->payee_party_role_id);
            if (PurchaseCreditNote::join('purchase_invoices as pi', 'pi.document_id', '=', 'purchase_credit_notes.purchase_invoice_id')->join('party_roles as pr', 'pr.id', '=', 'pi.payee_party_role_id')->where('pr.party_id', $role->party_id)->where('supplier_credit_number', $data['supplier_credit_number'])->exists()) {
                G::fail('This supplier credit reference is already recorded for the shared party.');
            }
            $clearing = PostingAccount::where('purpose_code', 'supplier_return_clearing')->value('ledger_account_id');
            $ap = JournalLine::where('journal_entry_id', $invoice->document_id)->whereHas('account', fn ($q) => $q->where('control_type', 'party_payable'))->firstOrFail()->ledger_account_id;
            $prepared = [];
            $total = '0';
            $usedReturn = [];
            $usedMatch = [];
            $usedReturnValue = [];
            foreach ($data['lines'] as $i => $row) {
                $return = PurchaseReturnLine::with('document')->lockForUpdate()->findOrFail($row['purchase_return_line_id']);
                $match = PurchaseInvoiceReceiptMatch::lockForUpdate()->findOrFail($row['purchase_invoice_receipt_match_id']);
                $line = PurchaseInvoiceLine::findOrFail($match->purchase_invoice_line_id);
                if ($line->document_id !== $invoice->document_id || $match->goods_receipt_line_id !== $return->goods_receipt_line_id || $return->document->status !== 'posted' || $return->document->document_date->format('Y-m-d') > $data['document_date']) {
                    G::fail('Match the physical return to the original invoiced receipt.');
                }
                $oldReturn = (string) PurchaseCreditNoteLine::whereHas('document', fn ($q) => $q->where('status', 'posted'))->where('purchase_return_line_id', $return->id)->sum('credited_quantity');
                $oldMatch = (string) PurchaseCreditNoteLine::whereHas('document', fn ($q) => $q->where('status', 'posted'))->where('purchase_invoice_receipt_match_id', $match->id)->sum('credited_quantity');
                $usedReturn[$return->id] = bcadd($usedReturn[$return->id] ?? '0', $row['credited_quantity'], 6);
                $usedMatch[$match->id] = bcadd($usedMatch[$match->id] ?? '0', $row['credited_quantity'], 6);
                if (bccomp(bcadd($oldReturn, $usedReturn[$return->id], 6), $return->base_quantity, 6) > 0 || bccomp(bcadd($oldMatch, $usedMatch[$match->id], 6), $match->matched_base_quantity, 6) > 0) {
                    G::fail('Credit exceeds physical returned or invoiced quantity.');
                }
                $allocationIds = InventoryLayerAllocation::where('stock_movement_id', $return->stock_movement_id)->pluck('id');
                $value = (string) InventoryCostLink::whereIn('source_layer_allocation_id', $allocationIds)->where('expense_account_id', $clearing)->sum('allocated_cost_pkr');
                if (bccomp($value, '0', 4) <= 0) {
                    G::fail('This return did not create supplier-return clearing; an unbilled return needs no supplier credit.');
                }
                $oldValue = (string) PurchaseCreditNoteLine::whereHas('document', fn ($q) => $q->where('status', 'posted'))->where('purchase_return_line_id', $return->id)->sum('net_amount');
                $cumulative = bcadd($oldReturn, $usedReturn[$return->id], 6);
                $target = bccomp($cumulative, $return->base_quantity, 6) === 0 ? $value : D::round(bcdiv(bcmul($value, $cumulative, 12), $return->base_quantity, 12));
                $amount = bcsub(bcsub($target, $oldValue, 4), $usedReturnValue[$return->id] ?? '0', 4);
                $usedReturnValue[$return->id] = bcadd($usedReturnValue[$return->id] ?? '0', $amount, 4);
                if (bccomp($amount, '0', 4) <= 0) {
                    G::fail('Credit value rounds to zero; combine the quantity.');
                }
                $prepared[] = $row + ['purchase_invoice_line_id' => $line->id, 'line_no' => $i + 1, 'net_amount' => $amount, 'gross_amount' => $amount, 'description' => mb_substr($data['description'], 0, 255)];
                $total = bcadd($total, $amount, 4);
            }
            $prior = (string) PurchaseCreditNote::whereHas('document', fn ($q) => $q->where('status', 'posted'))->where('purchase_invoice_id', $invoice->document_id)->sum('net_amount');
            if (bccomp(bcadd($prior, $total, 4), $invoice->net_amount, 4) > 0) {
                G::fail('Credit cannot exceed the original supplier invoice value.');
            }
            PurchaseCreditNote::create(['document_id' => $doc->id, 'purchase_invoice_id' => $invoice->document_id, 'supplier_credit_number' => $data['supplier_credit_number'], 'credit_kind' => 'physical_return', 'reason' => $data['description'], 'net_amount' => $total, 'gross_amount' => $total]);
            foreach ($prepared as $row) {
                PurchaseCreditNoteLine::create($row + ['document_id' => $doc->id]);
            }
            app(SourceJournal::class)->post($doc, [$ap => $total, $clearing => bcsub('0', $total, 4)], 'operational', $role->party_id, $role->id, $data['document_date']);
        });
    }
}
