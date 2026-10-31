<?php

namespace App\Modules\Accounting\Services;

use App\Models\BankAccount;
use App\Models\BankReconciliation;
use App\Models\BankReconciliationMatch;
use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Models\Document;
use App\Models\JournalLine;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Inventory\Services\InventoryGuard as G;
use App\Modules\Purchasing\Services\PurchaseDocuments;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BankReconciliationService
{
    public function statement(int $actor, array $input): BankStatement
    {
        $data = validator($input, ['bank_account_id' => 'required|integer|exists:bank_accounts,id', 'statement_reference' => 'required|string|max:100', 'starts_on' => 'required|date_format:Y-m-d', 'ends_on' => 'required|date_format:Y-m-d|after_or_equal:starts_on', 'opening_balance' => 'required|decimal:0,4', 'closing_balance' => 'required|decimal:0,4', 'lines' => 'sometimes|array|max:500', 'lines.*' => 'required|array:transaction_on,reference,description,amount', 'lines.*.transaction_on' => 'required|date_format:Y-m-d', 'lines.*.reference' => 'nullable|string|max:150', 'lines.*.description' => 'required|string|max:500', 'lines.*.amount' => 'required|decimal:0,4|not_in:0'])->validate();

        $data['lines'] ??= [];

        return DB::transaction(function () use ($actor, $data) {
            G::company();
            G::actor($actor, 'accounting.finance.reconcile');
            $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
            $existing = BankStatement::where('bank_account_id', $data['bank_account_id'])->where('statement_reference', $data['statement_reference'])->first();
            if ($existing) {
                if ($existing->import_sha256 !== $hash) {
                    G::fail('Statement reference already exists with different contents.');
                }

                return $existing;
            }
            $bank = BankAccount::findOrFail($data['bank_account_id']);
            if (! $bank->is_active) {
                G::fail('This bank or cash account is inactive.');
            }
            $sum = $data['opening_balance'];
            foreach ($data['lines'] as $l) {
                if (bccomp($l['amount'], '0', 4) === 0 || $l['transaction_on'] < $data['starts_on'] || $l['transaction_on'] > $data['ends_on']) {
                    G::fail('Statement lines must be nonzero and within the evidence date range.');
                }
                $sum = bcadd($sum, $l['amount'], 4);
            }
            if (bccomp($sum, $data['closing_balance'], 4) !== 0) {
                G::fail('Statement opening plus signed transactions must equal its closing balance.');
            }
            $s = BankStatement::create(collect($data)->except('lines')->all() + ['imported_by' => $actor, 'confirmed_at' => now(), 'import_sha256' => $hash]);
            foreach ($data['lines'] as $i => $l) {
                BankStatementLine::create($l + ['bank_statement_id' => $s->id, 'line_no' => $i + 1]);
            }
            app(AuditLogger::class)->record('bank_statements', $s->id, 'statement_confirmed', null, ['reference' => $s->statement_reference, 'sha256' => $hash], $actor);

            return $s;
        }, 3);
    }

    public function confirm(int $actor, array $input): Document
    {
        $data = validator($input, ['idempotency_key' => 'required|uuid', 'document_date' => 'required|date_format:Y-m-d', 'description' => 'required|string|min:5|max:500', 'bank_statement_id' => 'required|integer|exists:bank_statements,id', 'unpresented_outflow' => 'required|decimal:0,4|gte:0', 'uncredited_inflow' => 'required|decimal:0,4|gte:0', 'matches' => 'sometimes|array|max:500', 'matches.*' => 'required|array:bank_statement_line_id,journal_line_id,matched_amount', 'matches.*.bank_statement_line_id' => 'required|integer|exists:bank_statement_lines,id', 'matches.*.journal_line_id' => 'required|integer|exists:journal_lines,id', 'matches.*.matched_amount' => 'required|decimal:0,4|gt:0'])->validate();
        $data['matches'] ??= [];
        $data['kind'] = 'reconciliation';

        return app(FinanceService::class)->execute($actor, $data, function (Document $doc) use ($data) {
            $statement = BankStatement::lockForUpdate()->findOrFail($data['bank_statement_id']);
            if (! $statement->confirmed_at || $statement->ends_on !== $data['document_date']) {
                G::fail('Use the confirmed statement closing date as reconciliation date.');
            }
            $bank = BankAccount::findOrFail($statement->bank_account_id);
            $latest = DB::table('bank_reconciliations as r')->join('documents as d', 'd.id', '=', 'r.document_id')->where('r.bank_account_id', $bank->id)->whereIn('d.status', ['posted', 'reversed'])->max('d.document_date');
            if ($latest && $latest > $data['document_date']) {
                G::fail('Reconciliation cannot precede existing reconciliation history for this bank.');
            }
            // Capture this before creating the current reconciliation. A first
            // statement can contain matches, so match rows cannot determine
            // whether an opening-balance corroboration is required.
            $hasPriorReconciliation = DB::table('bank_reconciliations as r')
                ->join('documents as d', 'd.id', '=', 'r.document_id')
                ->where('r.bank_account_id', $bank->id)
                ->whereIn('d.status', ['posted', 'reversed'])
                ->exists();
            $book = (string) app(FinanceReports::class)->lines($data['document_date'])->where('l.ledger_account_id', $bank->ledger_account_id)->selectRaw('COALESCE(SUM(l.debit-l.credit),0) as balance')->value('balance');
            if (bccomp($book, bcadd(bcsub($statement->closing_balance, $data['unpresented_outflow'], 4), $data['uncredited_inflow'], 4), 4) !== 0) {
                G::fail('Book balance must reconcile to statement balance less unpresented outflows plus uncredited inflows. Post missing book entries first.');
            }
            $r = BankReconciliation::create(['document_id' => $doc->id, 'bank_account_id' => $bank->id, 'as_of' => $data['document_date'], 'statement_closing_balance' => $statement->closing_balance, 'book_closing_balance' => $book, 'unpresented_outflow' => $data['unpresented_outflow'], 'uncredited_inflow' => $data['uncredited_inflow'], 'other_reconciling_amount' => '0', 'explanation' => $data['description']]);
            $usedStatement = [];
            $usedJournal = [];
            foreach ($data['matches'] as $i => $m) {
                $s = BankStatementLine::lockForUpdate()->findOrFail($m['bank_statement_line_id']);
                $j = JournalLine::with('document')->lockForUpdate()->findOrFail($m['journal_line_id']);
                $signed = bcsub($j->debit, $j->credit, 4);
                if ($s->bank_statement_id !== $statement->id || $j->ledger_account_id !== $bank->ledger_account_id || $j->document->status !== 'posted' || $j->document->document_date->format('Y-m-d') > $data['document_date'] || (bccomp($s->amount, '0', 4) > 0) !== (bccomp($signed, '0', 4) > 0)) {
                    G::fail('Match posted bank entries to statement lines with the same bank and direction.');
                }
                foreach ([['bank_statement_line_id', $s->id, $s->amount], ['journal_line_id', $j->id, $signed]] as [$column,$id,$value]) {
                    $used = &$usedStatement;
                    if ($column === 'journal_line_id') {
                        $used = &$usedJournal;
                    }
                    $used[$id] = bcadd($used[$id] ?? '0', $m['matched_amount'], 4);
                    $already = (string) BankReconciliationMatch::where($column, $id)->selectRaw('COALESCE(SUM(CASE WHEN reverses_match_id IS NULL THEN matched_amount ELSE -matched_amount END),0) as amount')->value('amount');
                    // Earlier rows in this draft are already in the table; compare each new increment once.
                    $absolute = bccomp($value, '0', 4) < 0 ? bcsub('0', $value, 4) : $value;
                    if (bccomp(bcadd($already, $m['matched_amount'], 4), $absolute, 4) > 0) {
                        G::fail('Matching would exceed a statement or journal line amount.');
                    }
                }
                BankReconciliationMatch::create($m + ['document_id' => $r->document_id, 'line_no' => $i + 1]);
            }
            foreach (BankStatementLine::where('bank_statement_id', $statement->id)->get() as $evidence) {
                $matched = (string) BankReconciliationMatch::where('bank_statement_line_id', $evidence->id)->selectRaw('COALESCE(SUM(CASE WHEN reverses_match_id IS NULL THEN matched_amount ELSE -matched_amount END),0) as amount')->value('amount');
                $absolute = bccomp($evidence->amount, '0', 4) < 0 ? bcsub('0', $evidence->amount, 4) : $evidence->amount;
                if (bccomp($matched, $absolute, 4) !== 0) {
                    G::fail('Every statement transaction must be fully matched to the books before confirmation.');
                }
            }
            // Initial statement opening is corroborated by the pre-statement GL.
            // Subsequent outstanding items remain supported by earlier GL lines.
            $firstStart = DB::table('bank_reconciliation_matches as m')->join('documents as d', 'd.id', '=', 'm.document_id')->join('bank_statement_lines as l', 'l.id', '=', 'm.bank_statement_line_id')->join('bank_statements as s', 's.id', '=', 'l.bank_statement_id')->where('s.bank_account_id', $bank->id)->whereIn('d.status', ['posted', 'reversed'])->min('s.starts_on');
            $cutoff = $firstStart ?? $statement->starts_on;
            if (! $hasPriorReconciliation) {
                $opening = (string) app(FinanceReports::class)->lines($data['document_date'])->where('l.ledger_account_id', $bank->ledger_account_id)->whereDate('d.document_date', '<', $cutoff)->selectRaw('COALESCE(SUM(l.debit-l.credit),0) as amount')->value('amount');
                if (bccomp($opening, $statement->opening_balance, 4) !== 0) {
                    G::fail('For the first statement, its opening must agree to the prior books. Start earlier to include outstanding opening transactions.');
                }
            }
            $pendingIn = '0';
            $pendingOut = '0';
            $bookLines = JournalLine::with('document')->where('ledger_account_id', $bank->ledger_account_id)->whereHas('document', function ($q) use ($data, $cutoff) {
                $q->where('status', 'posted')->whereNull('reversal_of_document_id')->whereDate('document_date', '<=', $data['document_date']);
                if ($cutoff) {
                    $q->whereDate('document_date', '>=', $cutoff);
                }
            })->get();
            foreach ($bookLines as $line) {
                // Opposite finance reversal lines cancel in the unmatched total.
                $matched = (string) BankReconciliationMatch::where('journal_line_id', $line->id)->selectRaw('COALESCE(SUM(CASE WHEN reverses_match_id IS NULL THEN matched_amount ELSE -matched_amount END),0) as amount')->value('amount');
                $left = bcsub(bcadd($line->debit, $line->credit, 4), $matched, 4);
                if (bccomp($line->debit, '0', 4) > 0) {
                    $pendingIn = bcadd($pendingIn, $left, 4);
                } else {
                    $pendingOut = bcadd($pendingOut, $left, 4);
                }
            }
            // Require explicit supported timing differences; do not accept arbitrary plugs.
            if (bccomp($pendingIn, $data['uncredited_inflow'], 4) !== 0 || bccomp($pendingOut, $data['unpresented_outflow'], 4) !== 0) {
                G::fail('Timing differences must equal the unmatched book inflows and outflows. Review ledger lines before confirming.');
            }
        }, 'accounting.finance.reconcile');
    }

    public function reverse(int $actor, array $input): Document
    {
        $data = validator($input, ['idempotency_key' => 'required|uuid', 'document_date' => 'required|date_format:Y-m-d', 'description' => 'required|string|min:5|max:500', 'source_document_id' => 'required|integer|exists:bank_reconciliations,document_id'])->validate();
        $data['matches'] ??= [];
        $data['kind'] = 'reconciliation';

        return app(FinanceService::class)->execute($actor, $data, function (Document $doc) use ($actor, $data) {
            $old = Document::lockForUpdate()->findOrFail($data['source_document_id']);
            if ($old->status !== 'posted' || $old->reversal_of_document_id || $data['document_date'] < $old->document_date->format('Y-m-d')) {
                G::fail('Choose an unreversed reconciliation and a valid later reversal date.');
            }
            $r = BankReconciliation::findOrFail($old->id);
            $later = DB::table('bank_reconciliations as r')->join('documents as d', 'd.id', '=', 'r.document_id')->where('r.bank_account_id', $r->bank_account_id)->where('d.status', 'posted')->whereNull('d.reversal_of_document_id')->where('d.id', '>', $old->id)->exists();
            $latestDate = DB::table('bank_reconciliations as r')->join('documents as d', 'd.id', '=', 'r.document_id')->where('r.bank_account_id', $r->bank_account_id)->whereIn('d.status', ['posted', 'reversed'])->max('d.document_date');
            if ($later || ($latestDate && $data['document_date'] < $latestDate)) {
                G::fail('Reverse newer reconciliations first and use a date no earlier than the bank reconciliation history.');
            }
            $doc->update(['reversal_of_document_id' => $old->id, 'reason' => $data['description']]);
            BankReconciliation::create(collect($r->getAttributes())->except(['document_id', 'created_at', 'updated_at'])->all() + ['document_id' => $doc->id]);
            foreach (BankReconciliationMatch::where('document_id', $old->id)->orderBy('line_no')->get() as $m) {
                BankReconciliationMatch::create(['document_id' => $doc->id, 'line_no' => $m->line_no, 'bank_statement_line_id' => $m->bank_statement_line_id, 'journal_line_id' => $m->journal_line_id, 'matched_amount' => $m->matched_amount, 'reverses_match_id' => $m->id]);
            }
            $old->update(['status' => 'reversed', 'reversed_by' => $actor, 'reversed_at' => now(), 'reason' => $data['description'], 'lock_version' => $old->lock_version + 1]);
            app(PurchaseDocuments::class)->event($old, 'reconciliation_reversed', $actor, (string) Str::uuid(), 'posted', 'reversed', $data['description']);
        }, 'accounting.finance.reverse');
    }
}
