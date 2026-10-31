<?php

namespace App\Modules\Purchasing\Services;

use App\Models\AccountingPeriod;
use App\Models\Document;
use App\Models\DocumentSequence;
use App\Models\DocumentStatusEvent;
use App\Models\FinancialYear;
use App\Models\LedgerAccount;
use App\Models\PostingAccount;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Inventory\Services\InventoryGuard as Guard;

class PurchaseDocuments
{
    public function find(int $id): Document
    {
        $doc = Document::with('type')->lockForUpdate()->findOrFail($id);
        abort_unless($doc->type->module_code === 'purchasing', 404);

        return $doc;
    }

    public function calendar(string $date): array
    {
        validator(['date' => $date], ['date' => 'required|date_format:Y-m-d'])->validate();
        if ($date > Guard::businessDate()) {
            Guard::fail('Future accounting business dates are not allowed.');
        }
        $year = FinancialYear::whereDate('starts_on', '<=', $date)->whereDate('ends_on', '>=', $date)->lockForUpdate()->first();
        $period = $year ? AccountingPeriod::where('financial_year_id', $year->id)->whereDate('starts_on', '<=', $date)->whereDate('ends_on', '>=', $date)->lockForUpdate()->first() : null;
        if (! $year || ! $period || $year->is_closed || $period->status !== 'open') {
            Guard::fail('An open year and period must cover this date.');
        }

        return [$year, $period];
    }

    public function account(string $purpose): int
    {
        $id = PostingAccount::where('purpose_code', $purpose)->value('ledger_account_id');
        $account = $id ? LedgerAccount::find($id) : null;
        if (! $account || ! $account->is_active || ! $account->is_postable) {
            Guard::fail('Configure active posting account: '.$purpose);
        }
        $expected = match ($purpose) {
            'inventory' => 'inventory','supplier_payable' => 'party_payable',default => 'none'
        };
        if ($account->control_type !== $expected) {
            Guard::fail('Wrong control classification for '.$purpose.'.');
        }

        return (int) $id;
    }

    public function event(Document $doc, string $action, int $actor, string $key, ?string $from = null, ?string $to = null, ?string $reason = null): void
    {
        DocumentStatusEvent::create(['document_id' => $doc->id, 'event_no' => 1 + (int) $doc->events()->max('event_no'), 'from_status' => $from, 'to_status' => $to ?? $doc->status, 'actor_id' => $actor, 'request_key' => $key, 'reason' => $reason ?? $action]);
        app(AuditLogger::class)->record('documents', $doc->id, $action, null, ['reason' => $reason, 'request_key' => $key], $actor, $doc->id);
    }

    public function finish(Document $doc, int $actor, string $key, string $hash): void
    {
        $this->calendar($doc->document_date->format('Y-m-d'));
        $type = $doc->type;
        if (! $type->is_active) {
            Guard::fail('This document type is inactive.');
        }
        $seq = DocumentSequence::firstOrCreate(['document_type_id' => $type->id, 'financial_year_id' => $doc->financial_year_id], ['next_value' => 1]);
        $seq = DocumentSequence::lockForUpdate()->findOrFail($seq->id);
        $number = $type->prefix.'-'.$doc->financial_year_id.'-'.str_pad((string) $seq->next_value, $type->numbering_width, '0', STR_PAD_LEFT);
        if (strlen($number) > 64) {
            Guard::fail('Document number capacity exceeded.');
        }
        $seq->increment('next_value');
        $doc->update(['status' => 'posted', 'number' => $number, 'posting_key' => $key, 'payload_hash' => $hash, 'posted_by' => $actor, 'posted_at' => now(), 'lock_version' => $doc->lock_version + 1]);
        $this->event($doc, 'posted', $actor, $key, 'draft', 'posted');
    }
}
