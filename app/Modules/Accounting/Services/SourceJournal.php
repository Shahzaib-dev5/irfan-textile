<?php

namespace App\Modules\Accounting\Services;

use App\Models\Document;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\PartyOpenItem;
use App\Modules\Inventory\Services\InventoryGuard as Guard;
use App\Modules\Inventory\Support\Decimal as D;
use Illuminate\Support\Facades\DB;

/** One journal sidecar for one source document; no secondary financial document. */
class SourceJournal
{
    public function post(Document $doc, array $amounts, string $kind = 'operational', ?int $party = null, ?int $role = null, ?string $due = null): void
    {
        if (! DB::transactionLevel() || $doc->status !== 'draft') {
            throw new \LogicException('Journal posting requires a draft source inside its posting transaction.');
        }
        $amounts = array_filter($amounts, fn ($v) => bccomp($v, '0', 4) !== 0);
        ksort($amounts);
        if (! $amounts) {
            return;
        }
        $debit = '0';
        $credit = '0';
        foreach ($amounts as $amount) {
            if (bccomp($amount, '0', 4) > 0) {
                $debit = bcadd($debit, $amount, 4);
            } else {
                $credit = bcsub($credit, $amount, 4);
            }
        }
        if (count($amounts) < 2 || bccomp($debit, $credit, 4) || bccomp($debit, '10000000000000000', 4) >= 0) {
            Guard::fail('Source journal must balance within supported precision.');
        }
        JournalEntry::create(['document_id' => $doc->id, 'entry_kind' => $kind, 'description' => 'Source document #'.$doc->id, 'total_debit' => $debit, 'total_credit' => $credit]);
        $index = 0;
        foreach ($amounts as $id => $amount) {
            $account = LedgerAccount::findOrFail($id);
            if (! $account->is_active || ! $account->is_postable) {
                Guard::fail('Posting accounts must be active and postable.');
            }
            $control = in_array($account->control_type, ['party_payable', 'party_receivable'], true);
            if ($control && (! $party || ! $role || ! $due)) {
                Guard::fail('A party control entry requires party, role and due date.');
            }
            $line = JournalLine::create(['journal_entry_id' => $doc->id, 'line_no' => ++$index, 'ledger_account_id' => $id, 'party_id' => $party, 'party_role_id' => $role, 'due_on' => $due, 'description' => 'Source document #'.$doc->id, 'debit' => bccomp($amount, '0', 4) > 0 ? $amount : '0', 'credit' => bccomp($amount, '0', 4) < 0 ? D::negative($amount, 4) : '0']);
            if ($control) {
                PartyOpenItem::create(['journal_line_id' => $line->id, 'original_amount' => bccomp($amount, '0', 4) > 0 ? $amount : D::negative($amount, 4), 'due_on' => $due]);
            }
        }
    }
}
