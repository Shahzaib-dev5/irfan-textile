<?php

namespace App\Modules\Parties\Services;

use App\Models\AuditEvent;
use App\Models\Document;
use App\Models\LedgerAccount;
use App\Models\Party;
use App\Models\PostingAccount;
use App\Models\User;
use App\Modules\Accounting\Services\FinanceService;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Inventory\Services\InventoryGuard as G;
use Illuminate\Support\Facades\DB;

class PartyOpeningBalance
{
    public function __construct(private FinanceService $finance, private AuditLogger $audit) {}

    /** The company lock must cover replay, party creation and financial posting. */
    public function replay(array $opening, array $payload, User $actor): ?Party
    {
        if (! $this->enabled($opening)) {
            return null;
        }
        G::actor($actor->id, 'accounting.finance.opening');
        $document = Document::where('idempotency_key', $opening['idempotency_key'])->lockForUpdate()->first();
        if (! $document) {
            return null;
        }
        $event = AuditEvent::where('document_id', $document->id)->where('entity_table', 'parties')
            ->where('action', 'opening_balance_created')->first();
        if (! $event || $document->created_by !== $actor->id || $document->status !== 'posted'
            || ! hash_equals($event->after_json['creation_hash'] ?? '', $this->hash($payload))) {
            G::fail('This opening request was already used. Review its existing voucher before submitting again.', 'opening.idempotency_key');
        }

        return Party::findOrFail($event->entity_key);
    }

    public function post(Party $party, array $opening, array $payload, User $actor): ?Document
    {
        if (! $this->enabled($opening)) {
            return null;
        }
        if (! DB::transactionLevel()) {
            throw new \LogicException('Party and opening balance must be created in the same transaction.');
        }
        G::actor($actor->id, 'accounting.finance.opening');
        $receive = $opening['direction'] === 'receive';
        if ($receive ? $opening['role'] !== 'customer' : ! in_array($opening['role'], ['supplier', 'vendor'], true)) {
            G::fail('Choose Customer for money to receive, or Supplier / Vendor for money to pay.', 'opening.role');
        }
        $role = $party->roles()->whereHas('type', fn ($query) => $query->where('code', $opening['role']))->first();
        if (! $role) {
            G::fail('Choose a business role assigned to this party.', 'opening.role');
        }
        $control = $this->account($receive ? 'customer_receivable' : 'supplier_payable', $receive ? 'party_receivable' : 'party_payable');
        $equity = $this->account('opening_equity', 'none');
        if ($equity->account_type !== 'equity') {
            G::fail('Configure an equity account for opening balances.', 'opening.amount');
        }
        $amount = $opening['amount'];
        $document = $this->finance->confirm($actor->id, [
            'kind' => 'opening', 'idempotency_key' => $opening['idempotency_key'],
            'document_date' => $opening['document_date'], 'reference' => $opening['reference'],
            'description' => 'Old unpaid balance for '.$party->code.' ('.$opening['role'].') / '.$opening['reference'].' — '.($receive ? 'receive from party' : 'pay to party'),
            'lines' => [
                ['ledger_account_id' => $control->id, 'party_role_id' => $role->id, 'due_on' => $opening['due_on'], 'debit' => $receive ? $amount : '0', 'credit' => $receive ? '0' : $amount],
                ['ledger_account_id' => $equity->id, 'debit' => $receive ? '0' : $amount, 'credit' => $receive ? $amount : '0'],
            ],
        ]);
        $this->audit->record('parties', $party->id, 'opening_balance_created', null, [
            'creation_hash' => $this->hash($payload), 'party_role_id' => $role->id,
            'direction' => $opening['direction'], 'reference' => $opening['reference'],
        ], $actor->id, $document->id);

        return $document;
    }

    private function enabled(array $opening): bool
    {
        return in_array($opening['direction'] ?? null, ['receive', 'pay'], true);
    }

    private function hash(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function account(string $purpose, string $control): LedgerAccount
    {
        $id = PostingAccount::where('purpose_code', $purpose)->value('ledger_account_id');
        $account = $id ? LedgerAccount::lockForUpdate()->find($id) : null;
        if (! $account || ! $account->is_active || ! $account->is_postable || $account->control_type !== $control) {
            G::fail('Configure the active posting account for '.$purpose.' in Finance before adding an old balance.', 'opening.amount');
        }

        return $account;
    }
}
