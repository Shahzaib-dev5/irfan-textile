<?php

namespace App\Modules\Accounting\Services;

use App\Models\LedgerAccount;
use App\Models\PartyOpenItem;
use Illuminate\Support\Facades\DB;

class FinanceReports
{
    public function availableItems()
    {
        $applications = DB::table('settlement_allocations as a')->join('documents as d', 'd.id', '=', 'a.document_id')->whereIn('d.status', ['posted', 'reversed'])->selectRaw('a.debit_open_item_id as item_id, CASE WHEN a.reverses_allocation_id IS NULL THEN a.amount ELSE -a.amount END as applied');
        $credits = DB::table('settlement_allocations as a')->join('documents as d', 'd.id', '=', 'a.document_id')->whereIn('d.status', ['posted', 'reversed'])->selectRaw('a.credit_open_item_id as item_id, CASE WHEN a.reverses_allocation_id IS NULL THEN a.amount ELSE -a.amount END as applied');
        $totals = DB::query()->fromSub($applications->unionAll($credits), 'effects')->selectRaw('item_id,SUM(applied) as used')->groupBy('item_id');

        return PartyOpenItem::with('journalLine.document', 'journalLine.party', 'journalLine.account')->leftJoinSub($totals, 'allocated', fn ($join) => $join->on('allocated.item_id', '=', 'party_open_items.id'))->whereHas('journalLine.document', fn ($q) => $q->where('status', 'posted'))->whereRaw('party_open_items.original_amount>COALESCE(allocated.used,0)')->select('party_open_items.*')->selectRaw('party_open_items.original_amount-COALESCE(allocated.used,0) as outstanding')->orderByDesc('party_open_items.id')->get();
    }

    public function lines(string $asOf)
    {
        return DB::table('journal_lines as l')->join('documents as d', 'd.id', '=', 'l.journal_entry_id')->join('ledger_accounts as a', 'a.id', '=', 'l.ledger_account_id')->whereIn('d.status', ['posted', 'reversed'])->whereDate('d.document_date', '<=', $asOf);
    }

    public function trial(string $asOf): array
    {
        $sums = $this->lines($asOf)->selectRaw('l.ledger_account_id, SUM(l.debit) as debit, SUM(l.credit) as credit')->groupBy('l.ledger_account_id')->get()->keyBy('ledger_account_id');
        $rows = [];
        $debit = '0';
        $credit = '0';
        $profit = '0';
        $assets = '0';
        $liabilities = '0';
        $equity = '0';
        foreach (LedgerAccount::orderBy('code')->get() as $account) {
            $s = $sums[$account->id] ?? null;
            $net = bcsub($s->debit ?? '0', $s->credit ?? '0', 4);
            $dr = bccomp($net, '0', 4) > 0 ? $net : '0.0000';
            $cr = bccomp($net, '0', 4) < 0 ? bcsub('0', $net, 4) : '0.0000';
            $debit = bcadd($debit, $dr, 4);
            $credit = bcadd($credit, $cr, 4);
            $rows[] = ['account' => $account, 'debit' => $dr, 'credit' => $cr, 'net' => $net];
            switch ($account->account_type) {
                case 'asset':$assets = bcadd($assets, $net, 4);
                    break;
                case 'liability':$liabilities = bcsub($liabilities, $net, 4);
                    break;
                case 'equity':$equity = bcsub($equity, $net, 4);
                    break;
                default:$profit = bcsub($profit, $net, 4);
            }
        }

        return compact('rows', 'debit', 'credit', 'profit', 'assets', 'liabilities', 'equity');
    }

    public function profit(string $from, string $to): array
    {
        $rows = $this->lines($to)->whereDate('d.document_date', '>=', $from)->whereIn('a.account_type', ['income', 'expense'])->selectRaw('a.id,a.code,a.name,a.account_type,SUM(l.debit-l.credit) as net')->groupBy('a.id', 'a.code', 'a.name', 'a.account_type')->orderBy('a.code')->get();
        $profit = '0';
        foreach ($rows as $r) {
            $profit = bcsub($profit, $r->net, 4);
        }

        return compact('rows', 'profit');
    }

    public function ledger(string $from, string $to, ?int $account = null, ?int $party = null): array
    {
        $q = $this->lines($to);
        if ($account) {
            $q->where('l.ledger_account_id', $account);
        }
        if ($party) {
            $q->where('l.party_id', $party)->whereIn('a.control_type', ['party_payable', 'party_receivable']);
        }
        $opening = (string) (clone $q)->whereDate('d.document_date', '<', $from)->selectRaw('COALESCE(SUM(l.debit-l.credit),0) as amount')->value('amount');
        $rows = $q->whereDate('d.document_date', '>=', $from)->select('l.*', 'd.number', 'd.document_date', 'a.code', 'a.name', 'a.control_type')->orderBy('d.document_date')->orderBy('d.id')->orderBy('l.line_no')->paginate(100)->withQueryString();
        // Running balance includes all preceding pages, not just the visible page.
        $before = $this->lines($to);
        if ($account) {
            $before->where('l.ledger_account_id', $account);
        }if ($party) {
            $before->where('l.party_id', $party)->whereIn('a.control_type', ['party_payable', 'party_receivable']);
        }
        $preceding = $before->whereDate('d.document_date', '>=', $from)->orderBy('d.document_date')->orderBy('d.id')->orderBy('l.line_no')->limit(($rows->currentPage() - 1) * 100)->get(['l.debit', 'l.credit']);
        $running = $opening;
        foreach ($preceding as $l) {
            $running = bcadd($running, bcsub($l->debit, $l->credit, 4), 4);
        }
        foreach ($rows as $l) {
            $running = bcadd($running, bcsub($l->debit, $l->credit, 4), 4);
            $l->running = $running;
        }

        return compact('opening', 'rows');
    }

    public function openItems(string $asOf, ?int $party = null, ?string $control = null)
    {
        $q = PartyOpenItem::with('journalLine.account', 'journalLine.party', 'journalLine.document')->whereHas('journalLine.document', fn ($q) => $q->whereIn('status', ['posted', 'reversed'])->whereDate('document_date', '<=', $asOf));
        if ($party) {
            $q->whereHas('journalLine', fn ($q) => $q->where('party_id', $party));
        }
        if ($control) {
            $q->whereHas('journalLine.account', fn ($q) => $q->where('control_type', $control));
        }
        $items = $q->orderBy('due_on')->paginate(100)->withQueryString();
        foreach ($items as $i) {
            $i->outstanding = app(FinanceService::class)->outstanding($i->id, $asOf);
        }

        return $items;
    }

    public function integrity(string $asOf): array
    {
        $trial = $this->trial($asOf);
        $unbalanced = DB::table('journal_entries as e')->join('documents as d', 'd.id', '=', 'e.document_id')->leftJoin('journal_lines as l', 'l.journal_entry_id', '=', 'e.document_id')->whereIn('d.status', ['posted', 'reversed'])->whereDate('d.document_date', '<=', $asOf)->groupBy('e.document_id', 'e.total_debit', 'e.total_credit')->havingRaw('COUNT(l.id)<2 OR SUM(l.debit)<>e.total_debit OR SUM(l.credit)<>e.total_credit')->pluck('e.document_id');
        $controls = $this->lines($asOf)->whereIn('a.control_type', ['party_payable', 'party_receivable'])->leftJoin('party_open_items as o', 'o.journal_line_id', '=', 'l.id')->where(fn ($q) => $q->whereNull('o.id')->orWhereRaw('o.original_amount<>l.debit+l.credit'))->count();

        $inventoryGl = (string) $this->lines($asOf)->where('a.control_type', 'inventory')->selectRaw('COALESCE(SUM(l.debit-l.credit),0) as amount')->value('amount');
        $inventoryValue = (string) DB::table('inventory_value_entries as v')->join('documents as d', 'd.id', '=', 'v.document_id')->whereIn('d.status', ['posted', 'reversed'])->whereDate('d.document_date', '<=', $asOf)->sum('v.amount_pkr');
        $partyDifferences = [];
        $groups = $this->lines($asOf)->whereIn('a.control_type', ['party_payable', 'party_receivable'])->selectRaw('l.party_id,l.ledger_account_id,SUM(l.debit-l.credit) as net')->groupBy('l.party_id', 'l.ledger_account_id')->get();
        foreach ($groups as $group) {
            $open = '0';
            foreach (PartyOpenItem::with('journalLine')->whereHas('journalLine', fn ($q) => $q->where('party_id', $group->party_id)->where('ledger_account_id', $group->ledger_account_id)->whereHas('document', fn ($d) => $d->whereIn('status', ['posted', 'reversed'])->whereDate('document_date', '<=', $asOf)))->get() as $item) {
                $left = app(FinanceService::class)->outstanding($item->id, $asOf);
                $open = bccomp($item->journalLine->debit, '0', 4) > 0 ? bcadd($open, $left, 4) : bcsub($open, $left, 4);
            }
            $difference = bcsub($group->net, $open, 4);
            if (bccomp($difference, '0', 4) !== 0) {
                $partyDifferences[] = ['party' => $group->party_id, 'account' => $group->ledger_account_id, 'difference' => $difference];
            }
        }

        return ['trial_difference' => bcsub($trial['debit'], $trial['credit'], 4), 'unbalanced' => $unbalanced, 'invalid_open_items' => $controls, 'inventory_difference' => bcsub($inventoryGl, $inventoryValue, 4), 'party_differences' => $partyDifferences];
    }
}
