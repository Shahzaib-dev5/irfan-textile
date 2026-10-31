<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\PartyOpenItem;
use App\Models\PostingAccount;
use App\Modules\Accounting\Services\FinanceService;
use App\Modules\Sales\Services\SalesWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\SalesFixtures;
use Tests\TestCase;

class FinanceSalesIntegrationTest extends TestCase
{
    use RefreshDatabase,SalesFixtures;

    public function test_customer_partial_receipt_reduces_credit_exposure_without_reposting_revenue(): void
    {
        $this->salesFixtures();
        $dispatch = $this->salesDispatch($this->salesOrder('40'));
        $invoice = $this->sales->confirm($this->admin->id, $this->invoiceData($dispatch));
        $this->get(route('sales.show', $invoice))->assertOk()->assertSee('Matches confirmed dispatch')->assertDontSee('Physical stock only')->assertDontSee('@elseif');
        $item = PartyOpenItem::whereHas('journalLine', fn ($q) => $q->where('journal_entry_id', $invoice->id))->firstOrFail();
        $a = LedgerAccount::create(['code' => '1002', 'name' => 'Test customer bank', 'account_type' => 'asset', 'normal_balance' => 'debit', 'control_type' => 'bank_cash']);
        $b = BankAccount::create(['code' => 'BANK', 'name' => 'Customer deposits bank', 'account_kind' => 'bank', 'ledger_account_id' => $a->id]);
        $before = JournalLine::where('ledger_account_id', PostingAccount::where('purpose_code', 'sales_revenue')->value('ledger_account_id'))->sum('credit');
        $data = ['kind' => 'receipt', 'document_date' => $this->date, 'idempotency_key' => (string) Str::uuid(), 'description' => 'Customer bank receipt against invoice', 'party_role_id' => $this->customer->id, 'bank_account_id' => $b->id, 'settlement_account_id' => PostingAccount::where('purpose_code', 'customer_receivable')->value('ledger_account_id'), 'amount' => '1500', 'method' => 'bank_transfer', 'apply_to_open_item_id' => $item->id, 'apply_amount' => '1500'];
        $service = app(FinanceService::class);
        $receipt = $service->confirm($this->admin->id, $data);
        $this->assertSame('2500.0000', $service->outstanding($item->id));
        $this->assertSame('2500.0000', $this->sales->exposure($this->customer->id));
        $history = app(SalesWorkspace::class)->history($this->customer);
        $this->assertSame('2500.0000', $history['customerBalance']);
        $this->assertCount(2, $history['customerLedger']);
        $this->assertCount(1, $history['deliveries']);
        $this->get(route('sales.history', $this->customer))->assertOk()->assertSee('2500.0000')->assertSee('Actual suits / packs delivered');
        $this->assertEquals($before, JournalLine::where('ledger_account_id', PostingAccount::where('purpose_code', 'sales_revenue')->value('ledger_account_id'))->sum('credit'));
        $this->get('/accounting/finance/documents/'.$receipt->id.'/pdf')->assertOk()->assertHeader('content-type', 'application/pdf');
    }
}
