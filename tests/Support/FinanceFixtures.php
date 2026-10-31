<?php

namespace Tests\Support;

use App\Models\BankAccount;
use App\Models\LedgerAccount;
use App\Models\PartyOpenItem;
use App\Models\PostingAccount;
use App\Modules\Accounting\Services\FinanceAccounts;
use App\Modules\Accounting\Services\FinanceService;
use Illuminate\Support\Str;

trait FinanceFixtures
{
    use PurchasingFixtures;

    protected FinanceService $finance;

    protected BankAccount $bank;

    protected LedgerAccount $equity;

    protected int $payable;

    protected function financeFixtures(): void
    {
        $this->purchasingFixtures();
        $this->finance = app(FinanceService::class);
        $a = app(FinanceAccounts::class)->save($this->admin->id, ['code' => '1001', 'name' => 'Test cash', 'account_type' => 'asset', 'normal_balance' => 'debit', 'control_type' => 'bank_cash', 'is_postable' => 1, 'is_active' => 1]);
        $this->bank = app(FinanceAccounts::class)->bank($this->admin->id, ['code' => 'CASH', 'name' => 'Actual cash box', 'account_kind' => 'cash', 'ledger_account_id' => $a->id]);
        $this->equity = LedgerAccount::create(['code' => '3999', 'name' => 'Test equity', 'account_type' => 'equity', 'normal_balance' => 'credit', 'control_type' => 'none']);
        $this->payable = PostingAccount::where('purpose_code', 'supplier_payable')->value('ledger_account_id');
    }

    protected function financeData(string $kind, array $extra = []): array
    {
        return array_replace(['kind' => $kind, 'document_date' => $this->date, 'idempotency_key' => (string) Str::uuid(), 'description' => 'Actual signed financial evidence', 'reference' => 'Signed schedule TEST-001'], $extra);
    }

    protected function paymentData(string $amount = '400', ?int $item = null): array
    {
        $data = $this->financeData('payment', ['party_role_id' => $this->supplier->id, 'bank_account_id' => $this->bank->id, 'settlement_account_id' => $this->payable, 'amount' => $amount, 'method' => 'cash']);
        if ($item) {
            $data += ['apply_to_open_item_id' => $item, 'apply_amount' => $amount];
        }

        return $data;
    }

    protected function billItem(): PartyOpenItem
    {
        $bill = $this->supplierBill($this->goods($this->order()));

        return PartyOpenItem::whereHas('journalLine', fn ($q) => $q->where('journal_entry_id', $bill->id))->firstOrFail();
    }

    protected function cashOpening(string $amount = '2000')
    {
        return $this->finance->confirm($this->admin->id, $this->financeData('opening', ['lines' => [['ledger_account_id' => $this->bank->ledger_account_id, 'debit' => $amount, 'credit' => '0'], ['ledger_account_id' => $this->equity->id, 'debit' => '0', 'credit' => $amount]]]));
    }
}
