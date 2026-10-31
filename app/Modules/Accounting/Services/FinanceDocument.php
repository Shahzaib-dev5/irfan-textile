<?php

namespace App\Modules\Accounting\Services;

use App\Models\BankAccount;
use App\Models\BankReconciliation;
use App\Models\BankReconciliationMatch;
use App\Models\Company;
use App\Models\Document;
use App\Models\LedgerAccount;
use App\Models\Party;
use App\Models\Payment;
use App\Models\Receipt;
use App\Models\SettlementAllocation;
use App\Models\SettlementBatch;
use App\Modules\Core\Services\TransactionDocument;
use App\Policies\FinancePolicy;

class FinanceDocument
{
    public function data(Document $document): array
    {
        abort_unless(app(FinancePolicy::class)->view(auth()->user(), $document), 403);
        $document->load('journal.lines.account', 'journal.lines.party', 'type');
        $columns = ['Account', 'Party / description', 'Debit PKR', 'Credit PKR'];
        $rows = [];
        $total = null;
        $meta = ['Document date' => $document->document_date->format('d M Y'), 'Source ID' => '#'.$document->id, 'Reversal of' => $document->reversal_of_document_id ? '#'.$document->reversal_of_document_id : null];
        $cash = Payment::find($document->id) ?? Receipt::find($document->id);
        if ($cash) {
            $meta += ['Party' => Party::findOrFail($cash->party_id)->legal_name, 'Cash / bank' => BankAccount::findOrFail($cash->bank_account_id)->name, 'Method' => str_replace('_', ' ', $cash->method), 'Reference' => $cash->reference, 'Money moved PKR' => $cash->bank_amount];
        }
        $batch = SettlementBatch::find($document->id);
        if ($batch) {
            $meta += ['Party' => Party::findOrFail($batch->party_id)->legal_name, 'Control account' => LedgerAccount::findOrFail($batch->ledger_account_id)->name, 'Description' => $batch->description];
        }
        $reconciliation = BankReconciliation::find($document->id);
        if ($reconciliation) {
            $meta += ['Cash / bank' => BankAccount::findOrFail($reconciliation->bank_account_id)->name, 'Statement closing PKR' => $reconciliation->statement_closing_balance, 'Book closing PKR' => $reconciliation->book_closing_balance, 'Unpresented outflows PKR' => $reconciliation->unpresented_outflow, 'Uncredited inflows PKR' => $reconciliation->uncredited_inflow, 'Explanation' => $reconciliation->explanation];
        }
        if ($document->journal) {
            foreach ($document->journal->lines as $l) {
                $rows[] = [$l->account->code.' / '.$l->account->name, ($l->party?->legal_name ?? '')."\n".$l->description, $l->debit, $l->credit];
            }
            $total = $document->journal->total_debit;
        } elseif (BankReconciliation::find($document->id)) {
            $columns = ['Statement line', 'Journal line', 'Matched PKR', 'Reverses'];
            foreach (BankReconciliationMatch::where('document_id', $document->id)->get() as $m) {
                $rows[] = [(string) $m->bank_statement_line_id, (string) $m->journal_line_id, (string) $m->matched_amount, (string) ($m->reverses_match_id ?? '—')];
            }
        } else {
            $columns = ['Debit open item', 'Credit open item', 'Applied PKR', 'Reverses'];
            foreach (SettlementAllocation::where('document_id', $document->id)->get() as $a) {
                $rows[] = [(string) $a->debit_open_item_id, (string) $a->credit_open_item_id, (string) $a->amount, (string) ($a->reverses_allocation_id ?? '—')];
            }
        }

        return ['document' => $document, 'module' => 'accounting', 'kind' => 'finance', 'company' => Company::find(1), 'logo' => app(TransactionDocument::class)->logo(), 'title' => $document->type->name, 'meta' => $meta, 'columns' => $columns, 'rows' => $rows, 'total' => $total, 'notes' => ($document->journal?->description ?? $document->reason ?? 'Allocation / reconciliation evidence.').' | Journal debit total equals credit total. No payment is implied by an invoice alone.'];
    }
}
