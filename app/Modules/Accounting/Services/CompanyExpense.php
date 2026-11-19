<?php

namespace App\Modules\Accounting\Services;

use App\Models\BankAccount;
use App\Models\Document;
use App\Models\LedgerAccount;
use App\Modules\Inventory\Services\InventoryGuard;
use Illuminate\Support\Facades\DB;

class CompanyExpense
{
    public function __construct(private FinanceAccounts $accounts, private FinanceService $finance) {}

    public function confirm(int $actor, array $input): Document
    {
        $data = validator($input, [
            'idempotency_key' => 'required|uuid',
            'document_date' => 'required|date_format:Y-m-d',
            'bank_account_id' => 'required|integer|exists:bank_accounts,id',
            'expense_account_id' => 'required_without:new_expense_code|nullable|integer|exists:ledger_accounts,id',
            'new_expense_code' => 'required_without:expense_account_id|nullable|string|max:30',
            'new_expense_name' => 'required_with:new_expense_code|nullable|string|max:150',
            'amount' => 'required|decimal:0,4|gt:0|lt:10000000000000000',
            'reference' => 'required|string|max:100',
            'description' => 'required|string|min:5|max:500',
        ])->validate();

        return DB::transaction(function () use ($actor, $data) {
            InventoryGuard::company();
            InventoryGuard::actor($actor, 'accounting.finance.post');
            $source = BankAccount::with('ledgerAccount')->lockForUpdate()->findOrFail($data['bank_account_id']);
            $cashAccount = $source->ledgerAccount;
            if (! $source->is_active || ! $cashAccount->is_active || ! $cashAccount->is_postable
                || $cashAccount->account_type !== 'asset' || $cashAccount->control_type !== 'bank_cash') {
                InventoryGuard::fail('Select an active company cashbox or bank account.');
            }

            if (! empty($data['new_expense_code'])) {
                InventoryGuard::actor($actor, 'accounting.finance.configure');
                $existing = LedgerAccount::where('code', $data['new_expense_code'])->first();
                $retry = Document::where('idempotency_key', $data['idempotency_key'])->exists();
                if ($existing && (! $retry || $existing->name !== $data['new_expense_name'])) {
                    InventoryGuard::fail('Expense GL code already exists. Select that account or use another code.');
                }
                $expense = $existing ?? $this->accounts->save($actor, [
                    'code' => $data['new_expense_code'],
                    'name' => $data['new_expense_name'],
                    'account_type' => 'expense',
                    'normal_balance' => 'debit',
                    'control_type' => 'none',
                    'is_postable' => true,
                    'is_active' => true,
                ]);
            } else {
                $expense = LedgerAccount::lockForUpdate()->findOrFail($data['expense_account_id']);
            }
            if (! $expense->is_active || ! $expense->is_postable || $expense->account_type !== 'expense' || $expense->control_type !== 'none') {
                InventoryGuard::fail('Select an active posting expense account.');
            }

            return $this->finance->confirm($actor, [
                'kind' => 'cash_journal',
                'idempotency_key' => $data['idempotency_key'],
                'document_date' => $data['document_date'],
                'reference' => $data['reference'],
                'description' => $data['description'],
                'lines' => [
                    ['ledger_account_id' => $expense->id, 'debit' => $data['amount'], 'credit' => '0'],
                    ['ledger_account_id' => $cashAccount->id, 'debit' => '0', 'credit' => $data['amount']],
                ],
            ]);
        }, 3);
    }
}
