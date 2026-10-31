<?php

namespace App\Modules\Accounting\Services;

use App\Models\BankAccount;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Inventory\Services\InventoryGuard as G;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class FinanceAccounts
{
    public function save(int $actor, array $input, ?int $id = null): LedgerAccount
    {
        return DB::transaction(function () use ($actor, $input, $id) {
            G::company();
            G::actor($actor, 'accounting.finance.configure');
            $data = validator($input, ['code' => ['required', 'string', 'max:30', Rule::unique('ledger_accounts')->ignore($id)], 'name' => 'required|string|max:150', 'parent_id' => 'nullable|integer|exists:ledger_accounts,id', 'account_type' => 'required|in:asset,liability,equity,income,expense', 'normal_balance' => 'required|in:debit,credit', 'control_type' => 'required|in:none,party_payable,party_receivable,bank_cash', 'is_postable' => 'required|boolean', 'is_active' => 'required|boolean'])->validate();
            $account = $id ? LedgerAccount::lockForUpdate()->findOrFail($id) : new LedgerAccount;
            $before = $account->exists ? $account->toArray() : null;
            if ($id && (JournalLine::where('ledger_account_id', $id)->exists() || DB::table('posting_accounts')->where('ledger_account_id', $id)->exists() || BankAccount::where('ledger_account_id', $id)->exists())) {
                foreach (['account_type', 'normal_balance', 'control_type', 'is_postable', 'parent_id'] as $field) {
                    if ((string) $account->$field !== (string) ($data[$field] ?? null)) {
                        G::fail('Referenced accounts retain their classification and hierarchy.');
                    }
                }
            }
            if ($data['control_type'] !== 'none' && ! $data['is_postable']) {
                G::fail('Control accounts must be postable.');
            }
            if (in_array($data['control_type'], ['party_receivable', 'bank_cash']) && $data['account_type'] !== 'asset' || $data['control_type'] === 'party_payable' && $data['account_type'] !== 'liability') {
                G::fail('Control account classification is incompatible.');
            }
            $parent = $data['parent_id'] ?? null;
            $seen = [$id];
            while ($parent) {
                if (in_array($parent, $seen)) {
                    G::fail('Account groups cannot contain a cycle.');
                }
                $seen[] = $parent;
                $p = LedgerAccount::lockForUpdate()->findOrFail($parent);
                if ($p->is_postable || ! $p->is_active || $p->account_type !== $data['account_type']) {
                    G::fail('Parent must be an active non-posting group of the same account type.');
                }
                $parent = $p->parent_id;
            }
            if ($id && LedgerAccount::where('parent_id', $id)->exists() && ($data['is_postable'] || ! $data['is_active'] || $data['account_type'] !== $account->account_type)) {
                G::fail('A group with children must keep its active group classification.');
            }
            $account->fill($data)->save();
            app(AuditLogger::class)->record('ledger_accounts', $account->id, 'finance_account_saved', $before, $account->toArray(), $actor);

            return $account;
        }, 3);
    }

    public function bank(int $actor, array $input): BankAccount
    {
        return DB::transaction(function () use ($actor, $input) {
            G::company();
            G::actor($actor, 'accounting.finance.configure');
            $data = validator($input, ['code' => 'required|string|max:30|unique:bank_accounts', 'name' => 'required|string|max:150', 'account_kind' => 'required|in:cash,bank', 'ledger_account_id' => 'required|integer|exists:ledger_accounts,id|unique:bank_accounts', 'bank_name' => 'nullable|string|max:150', 'branch_name' => 'nullable|string|max:150', 'account_reference' => 'nullable|string|max:100', 'iban' => 'nullable|string|max:34|unique:bank_accounts'])->validate();
            $a = LedgerAccount::lockForUpdate()->findOrFail($data['ledger_account_id']);
            if (! $a->is_active || ! $a->is_postable || $a->control_type !== 'bank_cash' || $a->account_type !== 'asset') {
                G::fail('Choose an active postable bank/cash asset account.');
            }
            if ($data['account_kind'] === 'cash' && (! empty($data['bank_name']) || ! empty($data['branch_name']) || ! empty($data['iban']))) {
                G::fail('Cash boxes do not have bank names, branches or IBANs.');
            }
            $bank = BankAccount::create($data);
            app(AuditLogger::class)->record('bank_accounts', $bank->id, 'finance_bank_created', null, ['code' => $bank->code, 'ledger_account_id' => $a->id], $actor);

            return $bank;
        }, 3);
    }
}
