<?php

namespace App\Modules\Accounting\Services;

use App\Models\BankAccount;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\PartyOpenItem;
use App\Models\PartyRole;
use App\Models\Payment;
use App\Models\Receipt;
use App\Models\SettlementAllocation;
use App\Models\SettlementBatch;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Inventory\Services\InventoryGuard as G;
use App\Modules\Purchasing\Services\PurchaseDocuments;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FinanceService
{
    public function __construct(private PurchaseDocuments $documents, private AuditLogger $audit) {}

    public static function rules(): array
    {
        return [
            'kind' => 'required|in:journal,cash_journal,opening,payment,receipt,settlement,reversal', 'document_date' => 'required|date_format:Y-m-d',
            'idempotency_key' => 'required|uuid', 'description' => 'required|string|min:5|max:500', 'reference' => 'nullable|string|max:100',
            'party_role_id' => 'required_if:kind,payment,receipt|nullable|integer|exists:party_roles,id',
            'bank_account_id' => 'required_if:kind,payment,receipt|nullable|integer|exists:bank_accounts,id',
            'settlement_account_id' => 'required_if:kind,payment,receipt|nullable|integer|exists:ledger_accounts,id',
            'amount' => 'required_if:kind,payment,receipt|nullable|decimal:0,4|gt:0|lt:10000000000000000',
            'method' => 'required_if:kind,payment,receipt|nullable|in:cash,bank_transfer,cheque,other',
            'source_document_id' => 'required_if:kind,reversal|nullable|integer|exists:documents,id',
            'lines' => 'required_if:kind,journal,cash_journal,opening|array|max:100', 'lines.*.ledger_account_id' => 'required|integer|exists:ledger_accounts,id',
            'lines.*.debit' => 'required|decimal:0,4|gte:0|lt:10000000000000000', 'lines.*.credit' => 'required|decimal:0,4|gte:0|lt:10000000000000000',
            'lines.*.party_role_id' => 'nullable|integer|exists:party_roles,id', 'lines.*.due_on' => 'nullable|date_format:Y-m-d',
            'lines.*' => 'required|array:ledger_account_id,debit,credit,party_role_id,due_on',
            'allocations' => 'required_if:kind,settlement|array|max:100', 'allocations.*.debit_open_item_id' => 'required|integer|exists:party_open_items,id',
            'allocations.*.credit_open_item_id' => 'required|integer|exists:party_open_items,id', 'allocations.*.amount' => 'required|decimal:0,4|gt:0|lt:10000000000000000',
            'allocations.*' => 'required|array:debit_open_item_id,credit_open_item_id,amount',
            'apply_to_open_item_id' => 'nullable|integer|exists:party_open_items,id', 'apply_amount' => 'required_with:apply_to_open_item_id|nullable|decimal:0,4|gt:0|lt:10000000000000000',
        ];
    }

    /** Serializes every financial writer with existing inventory/source writers. */
    public function execute(int $actor, array $data, callable $callback, string $permission = 'accounting.finance.post'): Document
    {
        return DB::transaction(function () use ($actor, $data, $callback, $permission) {
            G::company();
            G::actor($actor, $permission);
            $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR), true);
            $existing = Document::where('idempotency_key', $data['idempotency_key'])->lockForUpdate()->first();
            if ($existing) {
                if ($existing->type->module_code !== 'accounting' || $existing->status !== 'posted' || ! hash_equals($existing->payload_hash ?? '', $hash)) {
                    G::fail('This request key already belongs to a different transaction.');
                }

                return $existing;
            }
            $doc = $this->newDocument($actor, $data['kind'], $data['document_date'], $data['idempotency_key']);
            $callback($doc);
            $this->documents->finish($doc, $actor, (string) Str::uuid(), $hash);
            $this->audit->record('documents', $doc->id, 'finance_confirmed', null, ['kind' => $data['kind'], 'description' => $data['description'], 'reference' => $data['reference'] ?? null], $actor, $doc->id);

            return $doc->fresh();
        }, 3);
    }

    public function newDocument(int $actor, string $kind, string $date, ?string $key = null, ?int $reversal = null, ?string $reason = null): Document
    {
        [$year,$period] = $this->documents->calendar($date);
        $type = DocumentType::where('code', 'finance_'.$kind)->where('is_active', true)->firstOrFail();

        return Document::create(['document_type_id' => $type->id, 'document_date' => $date, 'financial_year_id' => $year->id, 'accounting_period_id' => $period->id, 'idempotency_key' => $key ?? (string) Str::uuid(), 'created_by' => $actor, 'status' => 'draft', 'lock_version' => 0, 'reversal_of_document_id' => $reversal, 'reason' => $reason]);
    }

    public function confirm(int $actor, array $input): Document
    {
        $data = validator($input, self::rules())->validate();
        $permission = match ($data['kind']) {
            'opening' => 'accounting.finance.opening','reversal' => 'accounting.finance.reverse',default => 'accounting.finance.post'
        };

        return $this->execute($actor, $data, function (Document $doc) use ($actor, $data) {
            switch ($data['kind']) {
                case 'journal': case 'cash_journal': case 'opening': $this->manual($doc, $data);
                    break;
                case 'payment': case 'receipt': $this->cash($actor, $doc, $data);
                    break;
                case 'settlement': $this->allocate($doc, $data['allocations'], $data['description']);
                    break;
                case 'reversal': $this->reverse($actor, $doc, $data);
                    break;
            }
        }, $permission);
    }

    public function role(int $id): PartyRole
    {
        $role = PartyRole::with('party', 'type', 'supplierProfile', 'vendorProfile', 'customerProfile')->lockForUpdate()->findOrFail($id);
        $profile = match ($role->type->code) {
            'supplier' => $role->supplierProfile,'vendor' => $role->vendorProfile,'customer' => $role->customerProfile,default => null
        };
        if (! $role->is_active || ! $role->party->is_active || ! $role->type->is_active || ! $profile) {
            G::fail('Choose an active supplier, contractor or customer role with a profile.');
        }

        return $role;
    }

    private function manual(Document $doc, array $data): void
    {
        if (count($data['lines']) < 2) {
            G::fail('Enter at least two journal lines.');
        }
        $lines = [];
        foreach ($data['lines'] as $row) {
            $account = LedgerAccount::lockForUpdate()->findOrFail($row['ledger_account_id']);
            if ($account->control_type !== 'none' && $data['kind'] !== 'opening' && ! ($data['kind'] === 'cash_journal' && $account->control_type === 'bank_cash')) {
                G::fail('Manual journals cannot bypass inventory, party or bank subledgers.');
            }
            if ($account->control_type === 'inventory') {
                G::fail('Use the inventory opening document for stock and its valuation.');
            }
            $line = ['ledger_account_id' => $account->id, 'debit' => $row['debit'], 'credit' => $row['credit'], 'description' => $data['description']];
            if (in_array($account->control_type, ['party_payable', 'party_receivable'])) {
                if (empty($row['party_role_id']) || empty($row['due_on'])) {
                    G::fail('Opening party balances require a role and due date.');
                }
                $role = $this->role($row['party_role_id']);
                if (($account->control_type === 'party_receivable') !== ($role->type->code === 'customer')) {
                    G::fail('The party role does not match this control account.');
                }
                $line += ['party_id' => $role->party_id, 'party_role_id' => $role->id, 'due_on' => $row['due_on']];
            } elseif (! empty($row['party_role_id'])) {
                G::fail('Party opening dimensions belong only on control accounts.');
            }
            if ($account->control_type === 'bank_cash' && ! BankAccount::where('ledger_account_id', $account->id)->where('is_active', true)->exists()) {
                G::fail('Configure this cash or bank account first.');
            }
            if (in_array($data['kind'], ['opening', 'cash_journal']) && trim($data['reference'] ?? '') === '') {
                G::fail('Opening and cash/bank journals require a signed evidence reference.');
            }
            $lines[] = $line;
        }
        if ($data['kind'] === 'cash_journal' && ! collect($lines)->contains(fn ($l) => LedgerAccount::findOrFail($l['ledger_account_id'])->control_type === 'bank_cash')) {
            G::fail('A cash/bank journal must include a configured cash or bank account.');
        }
        $this->writeJournal($doc, $lines, $data['kind'] === 'opening' ? 'opening' : 'manual', mb_substr((! empty($data['reference']) ? '['.$data['reference'].'] ' : '').$data['description'], 0, 500));
    }

    public function writeJournal(Document $doc, array $lines, string $kind, string $description): void
    {
        if (! DB::transactionLevel() || $doc->status !== 'draft') {
            throw new \LogicException('Financial posting requires a draft inside a transaction.');
        }
        $debit = '0';
        $credit = '0';
        foreach ($lines as $line) {
            if (! ((bccomp($line['debit'], '0', 4) > 0 && bccomp($line['credit'], '0', 4) === 0) || (bccomp($line['credit'], '0', 4) > 0 && bccomp($line['debit'], '0', 4) === 0))) {
                G::fail('Every journal line needs one positive debit or credit.');
            }
            $a = LedgerAccount::lockForUpdate()->findOrFail($line['ledger_account_id']);
            if (! $a->is_active || ! $a->is_postable) {
                G::fail('Choose active posting accounts, not account groups.');
            }
            if ($a->control_type === 'bank_cash') {
                $bank = BankAccount::where('ledger_account_id', $a->id)->where('is_active', true)->first();
                if (! $bank) {
                    G::fail('Configure an active cash / bank subledger for this account.');
                }
                $closed = DB::table('bank_reconciliations as r')->join('documents as d', 'd.id', '=', 'r.document_id')->where('r.bank_account_id', $bank->id)->where('d.status', 'posted')->whereNull('d.reversal_of_document_id')->max('r.as_of');
                if ($closed && $doc->document_date->format('Y-m-d') <= $closed) {
                    G::fail('This cash / bank date is reconciled. Reverse the reconciliation before adding backdated entries.');
                }
            }
            if (in_array($a->control_type, ['party_payable', 'party_receivable']) && (empty($line['party_id']) || empty($line['party_role_id']) || empty($line['due_on']))) {
                G::fail('Party controls require party, role and due date.');
            }
            $debit = bcadd($debit, $line['debit'], 4);
            $credit = bcadd($credit, $line['credit'], 4);
        }
        if (count($lines) < 2 || bccomp($debit, $credit, 4) !== 0 || bccomp($debit, '10000000000000000', 4) >= 0) {
            G::fail('Journal debits and credits must balance exactly.');
        }
        JournalEntry::create(['document_id' => $doc->id, 'entry_kind' => $kind, 'description' => mb_substr($description, 0, 500), 'total_debit' => $debit, 'total_credit' => $credit]);
        foreach ($lines as $i => $row) {
            $line = JournalLine::create($row + ['journal_entry_id' => $doc->id, 'line_no' => $i + 1, 'description' => $description]);
            if (in_array(LedgerAccount::findOrFail($line->ledger_account_id)->control_type, ['party_payable', 'party_receivable'])) {
                PartyOpenItem::create(['journal_line_id' => $line->id, 'original_amount' => bcadd($line->debit, $line->credit, 4), 'due_on' => $line->due_on]);
            }
        }
    }

    private function cash(int $actor, Document $doc, array $data): void
    {
        $role = $this->role($data['party_role_id']);
        $bank = BankAccount::lockForUpdate()->findOrFail($data['bank_account_id']);
        $bankGL = LedgerAccount::findOrFail($bank->ledger_account_id);
        if (! $bank->is_active || $bankGL->control_type !== 'bank_cash' || $bankGL->account_type !== 'asset') {
            G::fail('Select an active configured cash or bank account.');
        }
        if (($bank->account_kind === 'cash') !== ($data['method'] === 'cash')) {
            G::fail('Cash requires a cash box; other methods require a bank account.');
        }
        $account = LedgerAccount::lockForUpdate()->findOrFail($data['settlement_account_id']);
        $customer = $role->type->code === 'customer';
        if ($account->control_type !== ($customer ? 'party_receivable' : 'party_payable')) {
            G::fail('Select the matching party control account.');
        }
        $payment = $data['kind'] === 'payment';
        $header = ['document_id' => $doc->id, 'party_id' => $role->party_id, 'party_role_id' => $role->id, 'bank_account_id' => $bank->id, 'settlement_account_id' => $account->id, 'method' => $data['method'], 'gross_amount' => $data['amount'], 'bank_amount' => $data['amount'], 'description' => $data['description'], 'reference' => $data['reference'] ?? null];
        ($payment ? Payment::class : Receipt::class)::create($header);
        $this->writeJournal($doc, [
            ['ledger_account_id' => $account->id, 'party_id' => $role->party_id, 'party_role_id' => $role->id, 'due_on' => $data['document_date'], 'debit' => $payment ? $data['amount'] : '0', 'credit' => $payment ? '0' : $data['amount']],
            ['ledger_account_id' => $bank->ledger_account_id, 'debit' => $payment ? '0' : $data['amount'], 'credit' => $payment ? $data['amount'] : '0'],
        ], 'operational', $data['description']);
        if (! empty($data['apply_to_open_item_id'])) {
            $own = PartyOpenItem::whereHas('journalLine', fn ($q) => $q->where('journal_entry_id', $doc->id)->where('ledger_account_id', $account->id))->firstOrFail();
            $target = PartyOpenItem::with('journalLine')->findOrFail($data['apply_to_open_item_id']);
            if ($target->journalLine->party_role_id !== $role->id) {
                G::fail('Select a bill or invoice belonging to this party role.');
            }
            $settlement = $this->newDocument($actor, 'settlement', $data['document_date']);
            $this->allocate($settlement, [['debit_open_item_id' => $payment ? $own->id : $target->id, 'credit_open_item_id' => $payment ? $target->id : $own->id, 'amount' => $data['apply_amount']]], 'Applied with voucher #'.$doc->id, $doc->id);
            $this->documents->finish($settlement, $actor, (string) Str::uuid(), hash('sha256', 'cash-allocation-'.$doc->id, true));
        }
    }

    public function outstanding(int $id, ?string $asOf = null): string
    {
        $item = PartyOpenItem::findOrFail($id);
        $rows = DB::table('settlement_allocations as a')->join('documents as d', 'd.id', '=', 'a.document_id')->whereIn('d.status', ['posted', 'reversed'])->where(fn ($q) => $q->where('a.debit_open_item_id', $id)->orWhere('a.credit_open_item_id', $id));
        if ($asOf) {
            $rows->whereDate('d.document_date', '<=', $asOf);
        }
        $used = (string) $rows->selectRaw('COALESCE(SUM(CASE WHEN a.reverses_allocation_id IS NULL THEN a.amount ELSE -a.amount END),0) as total')->value('total');

        return bcsub($item->original_amount, $used, 4);
    }

    private function allocate(Document $doc, array $rows, string $description, ?int $pendingSource = null): void
    {
        if (! $rows) {
            G::fail('Select at least one debit and credit open item.');
        }
        $ids = array_unique(array_merge(array_column($rows, 'debit_open_item_id'), array_column($rows, 'credit_open_item_id')));
        sort($ids);
        $items = PartyOpenItem::with('journalLine.document')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $first = $items[$rows[0]['debit_open_item_id']]->journalLine;
        SettlementBatch::create(['document_id' => $doc->id, 'party_id' => $first->party_id, 'ledger_account_id' => $first->ledger_account_id, 'settlement_kind' => 'apply', 'description' => $description]);
        $used = [];
        foreach ($rows as $i => $row) {
            $debit = $items[$row['debit_open_item_id']];
            $credit = $items[$row['credit_open_item_id']];
            $a = $debit->journalLine;
            $b = $credit->journalLine;
            foreach ([$a, $b] as $line) {
                if ($line->party_id !== $first->party_id || $line->ledger_account_id !== $first->ledger_account_id || $line->employee_id !== $first->employee_id || ($line->document->status !== 'posted' && $line->journal_entry_id !== $pendingSource) || $line->document->document_date->format('Y-m-d') > $doc->document_date->format('Y-m-d')) {
                    G::fail('Allocations require posted opposite entries for the same party and control account, dated no later than this settlement.');
                }
            }
            if (bccomp($a->debit, '0', 4) <= 0 || bccomp($b->credit, '0', 4) <= 0) {
                G::fail('Choose a debit open item and a credit open item.');
            }
            foreach ([$debit, $credit] as $item) {
                $last = DB::table('settlement_allocations as a')->join('documents as d', 'd.id', '=', 'a.document_id')->whereIn('d.status', ['posted', 'reversed'])->where(fn ($q) => $q->where('a.debit_open_item_id', $item->id)->orWhere('a.credit_open_item_id', $item->id))->max('d.document_date');
                if ($last && $last > $doc->document_date->format('Y-m-d')) {
                    G::fail('Do not backdate an allocation before existing settlement history for these items.');
                }
                $used[$item->id] = bcadd($used[$item->id] ?? '0', $row['amount'], 4);
                if (bccomp($used[$item->id], $this->outstanding($item->id), 4) > 0) {
                    G::fail('Allocation exceeds the outstanding amount.');
                }
            }
            SettlementAllocation::create($row + ['document_id' => $doc->id, 'line_no' => $i + 1]);
        }
    }

    private function reverse(int $actor, Document $doc, array $data): void
    {
        $original = Document::with('type', 'journal.lines')->lockForUpdate()->findOrFail($data['source_document_id']);
        if ($original->status !== 'posted' || $original->type->module_code !== 'accounting' || $original->reversal_of_document_id || ! in_array($original->type->code, ['finance_journal', 'finance_cash_journal', 'finance_opening', 'finance_payment', 'finance_receipt', 'finance_settlement', 'finance_supplier_credit'])) {
            G::fail('Reverse a posted finance voucher once. Operational stock/sales corrections use their source workflows.');
        }
        if ($original->type->code === 'finance_settlement' && SettlementBatch::findOrFail($original->id)->settlement_kind !== 'apply') {
            G::fail('A reversal allocation cannot itself be reversed; create a new application.');
        }
        if ($data['document_date'] < $original->document_date->format('Y-m-d')) {
            G::fail('Reversal date cannot precede its source.');
        }
        $doc->update(['reversal_of_document_id' => $original->id, 'reason' => $data['description']]);
        $itemIds = PartyOpenItem::whereHas('journalLine', fn ($q) => $q->where('journal_entry_id', $original->id))->pluck('id');
        $allocations = SettlementAllocation::whereNull('reverses_allocation_id')->where(function ($q) use ($original, $itemIds) {
            $q->where('document_id', $original->id)->orWhereIn('debit_open_item_id', $itemIds)->orWhereIn('credit_open_item_id', $itemIds);
        })->whereNotIn('id', SettlementAllocation::whereNotNull('reverses_allocation_id')->select('reverses_allocation_id'))->orderBy('id')->lockForUpdate()->get();
        foreach ($allocations->groupBy('document_id') as $group) {
            $parent = SettlementBatch::findOrFail($group->first()->document_id);
            if (Document::findOrFail($parent->document_id)->document_date->format('Y-m-d') > $data['document_date']) {
                G::fail('Reversal must be dated on or after every affected settlement.');
            }
            $undo = $this->newDocument($actor, 'settlement', $data['document_date']);
            SettlementBatch::create(['document_id' => $undo->id, 'party_id' => $parent->party_id, 'ledger_account_id' => $parent->ledger_account_id, 'settlement_kind' => 'reverse', 'description' => 'Unwind for reversal #'.$doc->id]);
            foreach ($group->values() as $i => $a) {
                SettlementAllocation::create(['document_id' => $undo->id, 'line_no' => $i + 1, 'debit_open_item_id' => $a->debit_open_item_id, 'credit_open_item_id' => $a->credit_open_item_id, 'amount' => $a->amount, 'reverses_allocation_id' => $a->id]);
            }
            $this->documents->finish($undo, $actor, (string) Str::uuid(), hash('sha256', 'unwind-'.$undo->id, true));
        }
        if ($original->journal) {
            if (DB::table('bank_reconciliation_matches')->whereIn('journal_line_id', $original->journal->lines->pluck('id'))->whereNull('reverses_match_id')->whereNotIn('id', DB::table('bank_reconciliation_matches')->whereNotNull('reverses_match_id')->select('reverses_match_id'))->exists()) {
                G::fail('Reverse the bank reconciliation before reversing this voucher.');
            }
            $lines = $original->journal->lines->map(fn ($l) => ['ledger_account_id' => $l->ledger_account_id, 'party_id' => $l->party_id, 'party_role_id' => $l->party_role_id, 'due_on' => $l->due_on, 'cost_centre_id' => $l->cost_centre_id, 'description' => $data['description'], 'debit' => $l->credit, 'credit' => $l->debit])->all();
            $this->writeJournal($doc, $lines, 'reversal', $data['description']);
            // Close the original and its exact opposite; unrelated bills stay reopened.
            foreach ($itemIds as $id) {
                $old = PartyOpenItem::with('journalLine')->findOrFail($id);
                $newLine = JournalLine::where('journal_entry_id', $doc->id)->where('line_no', $old->journalLine->line_no)->firstOrFail();
                $new = PartyOpenItem::where('journal_line_id', $newLine->id)->firstOrFail();
                $close = $this->newDocument($actor, 'settlement', $data['document_date']);
                $positive = bccomp($old->journalLine->debit, '0', 4) > 0;
                $this->allocate($close, [['debit_open_item_id' => $positive ? $old->id : $new->id, 'credit_open_item_id' => $positive ? $new->id : $old->id, 'amount' => $old->original_amount]], 'Close reversed voucher #'.$original->id, $doc->id);
                $this->documents->finish($close, $actor, (string) Str::uuid(), hash('sha256', 'reversal-close-'.$close->id, true));
            }
        }
        $original->update(['status' => 'reversed', 'reason' => $data['description'], 'reversed_by' => $actor, 'reversed_at' => now(), 'lock_version' => $original->lock_version + 1]);
        $this->documents->event($original, 'finance_reversed', $actor, (string) Str::uuid(), 'posted', 'reversed', $data['description']);
    }
}
