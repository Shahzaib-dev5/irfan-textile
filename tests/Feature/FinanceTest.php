<?php

namespace Tests\Feature;

use App\Models\AccessRole;
use App\Models\AccountingPeriod;
use App\Models\BankStatementLine;
use App\Models\Document;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\PartyOpenItem;
use App\Models\Payment;
use App\Models\PurchaseInvoiceLine;
use App\Models\PurchaseInvoiceReceiptMatch;
use App\Models\PurchaseReturnLine;
use App\Models\SettlementAllocation;
use App\Models\StockMovement;
use App\Models\User;
use App\Modules\Accounting\Services\BankReconciliationService;
use App\Modules\Accounting\Services\FinanceAccounts;
use App\Modules\Accounting\Services\FinanceReports;
use App\Modules\Accounting\Services\SupplierCreditService;
use Database\Seeders\SalesSetupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\FinanceFixtures;
use Tests\TestCase;

class FinanceTest extends TestCase
{
    public function test_guided_party_forms_show_existing_advance_and_keep_advanced_form_available(): void
    {
        $bill = $this->billItem();
        $this->finance->confirm($this->admin->id, $this->paymentData('300'));
        foreach (['payment', 'receipt', 'settlement'] as $kind) {
            $response = $this->get('/accounting/finance/create?kind='.$kind)
                ->assertOk()->assertSee('Who is this for?')->assertSee('Available advance / existing credit');
            $items = $response->viewData('guide')['items'];
            if ($kind === 'payment') {
                $this->fixture('guided-payment', $response);
            }
            $this->assertTrue($items->contains(fn ($i) => $i['id'] === (string) $bill->id && $i['amount'] === '1000.0000'));
            $this->assertTrue($items->contains(fn ($i) => $i['role'] === (string) $this->supplier->id && $i['debit'] && $i['amount'] === '300.0000'));
        }
        $this->get('/accounting/finance/create?kind=settlement&advanced=1')->assertOk()->assertSee('Debit open item');
    }

    public function test_zero_activity_statement_reconciles_without_invented_transactions(): void
    {
        $bank = app(BankReconciliationService::class);
        $statement = $bank->statement($this->admin->id, ['bank_account_id' => $this->bank->id, 'statement_reference' => 'NO-ACTIVITY', 'starts_on' => $this->date, 'ends_on' => $this->date, 'opening_balance' => '0', 'closing_balance' => '0', 'lines' => []]);
        $r = $bank->confirm($this->admin->id, ['idempotency_key' => (string) Str::uuid(), 'document_date' => $this->date, 'description' => 'Statement confirms zero activity', 'bank_statement_id' => $statement->id, 'unpresented_outflow' => '0', 'uncredited_inflow' => '0', 'matches' => []]);
        $this->assertSame('posted', $r->status);
        $this->assertSame(0, JournalLine::count());
    }

    public function test_existing_payment_can_be_split_applied_then_unapplied_without_a_second_cash_posting(): void
    {
        $bill = $this->billItem();
        $payment = $this->finance->confirm($this->admin->id, $this->paymentData('1000'));
        $debit = PartyOpenItem::whereHas('journalLine', fn ($q) => $q->where('journal_entry_id', $payment->id))->firstOrFail();
        $count = JournalLine::count();
        $data = $this->financeData('settlement', ['allocations' => [['debit_open_item_id' => $debit->id, 'credit_open_item_id' => $bill->id, 'amount' => '300'], ['debit_open_item_id' => $debit->id, 'credit_open_item_id' => $bill->id, 'amount' => '200']]]);
        $allocation = $this->finance->confirm($this->admin->id, $data);
        $this->assertSame('500.0000', $this->finance->outstanding($bill->id));
        $this->assertSame($count, JournalLine::count());
        $this->finance->confirm($this->admin->id, $this->financeData('reversal', ['source_document_id' => $allocation->id]));
        $this->assertSame('1000.0000', $this->finance->outstanding($bill->id));
        $this->assertSame($count, JournalLine::count());
        $data['idempotency_key'] = (string) Str::uuid();
        $data['allocations'][1]['amount'] = '800';
        $this->invalid(fn () => $this->finance->confirm($this->admin->id, $data));
    }

    public function test_unvalidated_line_parent_and_reversal_fields_cannot_be_injected(): void
    {
        $bill = $this->billItem();
        $payment = $this->finance->confirm($this->admin->id, $this->paymentData());
        $debit = PartyOpenItem::whereHas('journalLine', fn ($q) => $q->where('journal_entry_id', $payment->id))->firstOrFail();
        $data = $this->financeData('settlement', ['allocations' => [['debit_open_item_id' => $debit->id, 'credit_open_item_id' => $bill->id, 'amount' => '400', 'document_id' => $bill->journalLine->journal_entry_id, 'reverses_allocation_id' => 1]]]);
        $this->invalid(fn () => $this->finance->confirm($this->admin->id, $data));
        $this->assertSame(0, SettlementAllocation::count());
    }

    public function test_source_reconciliation_matches_inventory_and_party_ledgers_after_payment(): void
    {
        $bill = $this->billItem();
        $this->finance->confirm($this->admin->id, $this->paymentData('100', $bill->id));
        $report = app(FinanceReports::class)->integrity($this->date);
        $this->assertSame('0.0000', $report['inventory_difference']);
        $this->assertSame([], $report['party_differences']);
        $trial = app(FinanceReports::class)->trial($this->date);
        $this->assertSame($trial['assets'], bcadd(bcadd($trial['liabilities'], $trial['equity'], 4), $trial['profit'], 4));
    }

    public function test_billed_purchase_return_credit_clears_payable_without_stock_duplicate(): void
    {
        $receipt = $this->goods($this->order());
        $bill = $this->supplierBill($receipt);
        $return = $this->supplierReturn($receipt, '20');
        $r = PurchaseReturnLine::where('document_id', $return->id)->firstOrFail();
        $match = PurchaseInvoiceReceiptMatch::whereIn('purchase_invoice_line_id', $bill->type->module_code === 'purchasing' ? PurchaseInvoiceLine::where('document_id', $bill->id)->pluck('id') : [])->firstOrFail();
        $data = ['idempotency_key' => (string) Str::uuid(), 'document_date' => $this->date, 'description' => 'Supplier accepts actual fabric return', 'purchase_invoice_id' => $bill->id, 'supplier_credit_number' => 'CR-001', 'lines' => [['purchase_return_line_id' => $r->id, 'purchase_invoice_receipt_match_id' => $match->id, 'credited_quantity' => '20']]];
        $count = StockMovement::count();
        $s = app(SupplierCreditService::class);
        $doc = $s->confirm($this->admin->id, $data);
        $this->assertSame('200.0000', $doc->journal->total_debit);
        $this->assertSame($count, StockMovement::count());
        $this->assertSame($doc->id, $s->confirm($this->admin->id, $data)->id);
        $data['idempotency_key'] = (string) Str::uuid();
        $data['supplier_credit_number'] = 'CR-002';
        $this->invalid(fn () => $s->confirm($this->admin->id, $data));
        $this->get('/accounting/finance/supplier-credits?invoice='.$bill->id)->assertOk();
        $this->finance->confirm($this->admin->id, $this->financeData('reversal', ['source_document_id' => $doc->id]));
        $this->assertSame('200.0000', $s->confirm($this->admin->id, $data)->journal->total_debit);
    }

    public function test_bank_fee_journal_is_evidenced_and_balanced(): void
    {
        $expense = LedgerAccount::create(['code' => '5900', 'name' => 'Bank charges', 'account_type' => 'expense', 'normal_balance' => 'debit', 'control_type' => 'none']);
        $d = $this->financeData('cash_journal', ['lines' => [['ledger_account_id' => $expense->id, 'debit' => '12.5000', 'credit' => '0'], ['ledger_account_id' => $this->bank->ledger_account_id, 'debit' => '0', 'credit' => '12.5000']]]);
        $doc = $this->finance->confirm($this->admin->id, $d);
        $this->assertSame('12.5000', $doc->journal->total_credit);
        $this->get('/accounting/finance/create?kind=cash_journal')->assertOk();
    }

    use FinanceFixtures,RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->financeFixtures();
    }

    private function invalid(callable $action): void
    {
        try {
            $action();
            $this->fail('Expected validation rejection.');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }
    }

    public function test_partial_supplier_payment_settles_without_reposting_the_bill_and_retries_once(): void
    {
        $this->cashOpening();
        $item = $this->billItem();
        $data = $this->paymentData('400', $item->id);
        $doc = $this->finance->confirm($this->admin->id, $data);
        $this->assertSame($doc->id, $this->finance->confirm($this->admin->id, $data)->id);
        $this->assertSame('600.0000', $this->finance->outstanding($item->id));
        $this->assertSame(1, Payment::count());
        $this->assertSame('0.0000', app(FinanceReports::class)->integrity($this->date)['trial_difference']);
        $data['amount'] = '401';
        $this->invalid(fn () => $this->finance->confirm($this->admin->id, $data));
        $this->assertSame('1000.0000', $item->fresh()->original_amount);
    }

    public function test_overallocation_rolls_back_cash_journal_and_document_atomically(): void
    {
        $item = $this->billItem();
        $before = Document::count();
        $this->invalid(fn () => $this->finance->confirm($this->admin->id, $this->paymentData('1001', $item->id)));
        $this->assertSame($before, Document::count());
        $this->assertSame(0, Payment::count());
        $this->assertSame('1000.0000', $this->finance->outstanding($item->id));
    }

    public function test_payment_reversal_unwinds_settlement_reopens_bill_and_preserves_history(): void
    {
        $item = $this->billItem();
        $payment = $this->finance->confirm($this->admin->id, $this->paymentData('400', $item->id));
        $oldLines = JournalLine::where('journal_entry_id', $payment->id)->get()->toArray();
        $data = $this->financeData('reversal', ['source_document_id' => $payment->id]);
        $reverse = $this->finance->confirm($this->admin->id, $data);
        $this->assertSame($reverse->id, $this->finance->confirm($this->admin->id, $data)->id);
        $this->assertSame('reversed', $payment->fresh()->status);
        $this->assertSame('1000.0000', $this->finance->outstanding($item->id));
        $this->assertSame($oldLines, JournalLine::where('journal_entry_id', $payment->id)->get()->toArray());
        $this->assertSame(3, SettlementAllocation::count());
        $this->invalid(fn () => $this->finance->confirm($this->admin->id, $this->financeData('reversal', ['source_document_id' => $payment->id])));
    }

    public function test_manual_journals_balance_and_cannot_bypass_control_accounts(): void
    {
        $expense = LedgerAccount::create(['code' => '5999', 'name' => 'Office costs', 'account_type' => 'expense', 'normal_balance' => 'debit', 'control_type' => 'none']);
        $d = $this->financeData('journal', ['lines' => [['ledger_account_id' => $expense->id, 'debit' => '123.4567', 'credit' => '0'], ['ledger_account_id' => $this->equity->id, 'debit' => '0', 'credit' => '123.4567']]]);
        $doc = $this->finance->confirm($this->admin->id, $d);
        $this->assertSame('123.4567', $doc->journal->total_debit);
        $d['idempotency_key'] = (string) Str::uuid();
        $d['lines'][1]['credit'] = '123.4566';
        $this->invalid(fn () => $this->finance->confirm($this->admin->id, $d));
        $d['lines'][1]['credit'] = '123.4567';
        $d['lines'][0]['ledger_account_id'] = $this->bank->ledger_account_id;
        $this->invalid(fn () => $this->finance->confirm($this->admin->id, $d));
        $this->assertSame('-123.4567', app(FinanceReports::class)->profit($this->date, $this->date)['profit']);
    }

    public function test_closed_period_rejects_posting_and_reversal(): void
    {
        $doc = $this->cashOpening();
        $period = AccountingPeriod::first();
        $period->update(['status' => 'closed', 'closed_at' => now(), 'closed_by' => $this->admin->id]);
        $this->invalid(fn () => $this->finance->confirm($this->admin->id, $this->paymentData()));
        $this->invalid(fn () => $this->finance->confirm($this->admin->id, $this->financeData('reversal', ['source_document_id' => $doc->id])));
    }

    public function test_bank_statement_matching_and_reversal_leave_the_gl_unchanged(): void
    {
        $opening = $this->cashOpening('1000');
        $line = JournalLine::where('journal_entry_id', $opening->id)->where('ledger_account_id', $this->bank->ledger_account_id)->firstOrFail();
        $bank = app(BankReconciliationService::class);
        $input = ['bank_account_id' => $this->bank->id, 'statement_reference' => 'ST-001', 'starts_on' => $this->date, 'ends_on' => $this->date, 'opening_balance' => '0', 'closing_balance' => '1000', 'lines' => [['transaction_on' => $this->date, 'description' => 'Opening transfer evidenced', 'amount' => '1000']]];
        $statement = $bank->statement($this->admin->id, $input);
        $this->assertSame($statement->id, $bank->statement($this->admin->id, $input)->id);
        $s = BankStatementLine::where('bank_statement_id', $statement->id)->firstOrFail();
        $d = ['idempotency_key' => (string) Str::uuid(), 'document_date' => $this->date, 'description' => 'Matched signed bank evidence', 'bank_statement_id' => $statement->id, 'unpresented_outflow' => '0', 'uncredited_inflow' => '0', 'matches' => [['bank_statement_line_id' => $s->id, 'journal_line_id' => $line->id, 'matched_amount' => '1000']]];
        $r = $bank->confirm($this->admin->id, $d);
        $this->assertSame($r->id, $bank->confirm($this->admin->id, $d)->id);
        $this->invalid(fn () => $this->finance->confirm($this->admin->id, $this->financeData('reversal', ['source_document_id' => $opening->id])));
        $d['idempotency_key'] = (string) Str::uuid();
        $this->invalid(fn () => $bank->confirm($this->admin->id, $d));
        $count = JournalLine::count();
        $bank->reverse($this->admin->id, ['idempotency_key' => (string) Str::uuid(), 'document_date' => $this->date, 'description' => 'Correct statement reconciliation', 'source_document_id' => $r->id]);
        $this->assertSame($count, JournalLine::count());
        $this->assertSame('reversed', $r->fresh()->status);
    }

    public function test_account_groups_and_referenced_classifications_are_protected(): void
    {
        $accounts = app(FinanceAccounts::class);
        $this->cashOpening();
        $d = ['code' => $this->equity->code, 'name' => 'Renamed equity', 'account_type' => 'expense', 'normal_balance' => 'credit', 'control_type' => 'none', 'is_postable' => 1, 'is_active' => 1];
        $this->invalid(fn () => $accounts->save($this->admin->id, $d, $this->equity->id));
        $group = $accounts->save($this->admin->id, ['code' => 'A-GROUP', 'name' => 'Assets group', 'account_type' => 'asset', 'normal_balance' => 'debit', 'control_type' => 'none', 'is_postable' => 0, 'is_active' => 1]);
        $this->invalid(fn () => $accounts->save($this->admin->id, ['code' => $group->code, 'name' => $group->name, 'parent_id' => $group->id, 'account_type' => 'asset', 'normal_balance' => 'debit', 'control_type' => 'none', 'is_postable' => 0, 'is_active' => 1], $group->id));
    }

    public function test_finance_screens_render_and_wholesale_and_calendar_roles_cannot_post(): void
    {
        $doc = $this->cashOpening();
        foreach (['overview' => '/accounting/finance', 'accounts' => '/accounting/finance/accounts', 'bank' => '/accounting/finance/bank-reconciliation', 'show' => '/accounting/finance/documents/'.$doc->id, 'supplier-credit' => '/accounting/finance/supplier-credits'] as $name => $url) {
            $this->fixture($name, $this->get($url)->assertOk());
        }
        foreach (['journal', 'cash_journal', 'opening', 'payment', 'receipt', 'settlement', 'reversal'] as $kind) {
            $this->fixture($kind, $this->get('/accounting/finance/create?kind='.$kind)->assertOk());
        }
        foreach (['trial', 'profit', 'balance', 'ledger', 'party', 'open', 'reconciliation'] as $report) {
            $this->fixture($report, $this->get('/accounting/finance/reports?report='.$report)->assertOk());
        }
        $pdf = $this->get('/accounting/finance/documents/'.$doc->id.'/pdf')->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
        if (getenv('FINANCE_BROWSER_FIXTURES')) {
            file_put_contents(storage_path('framework/testing/finance-browser/opening.pdf'), $pdf->getContent());
        }
        $u = User::factory()->create();
        $u->accessRoles()->attach(AccessRole::where('code', 'calendar-admin')->value('id'), ['assigned_by' => $this->admin->id]);
        $this->actingAs($u)->get('/accounting/finance')->assertForbidden();
        $this->post('/accounting/finance/documents', $this->paymentData())->assertForbidden();
        $this->seed(SalesSetupSeeder::class);
        $wholesale = User::factory()->create();
        $wholesale->accessRoles()->attach(AccessRole::where('code', 'wholesale')->value('id'), ['assigned_by' => $this->admin->id]);
        $this->actingAs($wholesale)->get('/accounting/finance')->assertForbidden();
        $this->get('/accounting/finance/documents/'.$doc->id.'/pdf')->assertForbidden();
    }

    private function fixture(string $name, $response): void
    {
        if (getenv('FINANCE_BROWSER_FIXTURES')) {
            $dir = storage_path('framework/testing/finance-browser');
            if (! is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            file_put_contents($dir.'/'.$name.'.html', $response->getContent());
        }
    }

    public function test_operational_sources_cannot_be_reversed_as_finance_only(): void
    {
        $item = $this->billItem();
        $this->invalid(fn () => $this->finance->confirm($this->admin->id, $this->financeData('reversal', ['source_document_id' => $item->journalLine->journal_entry_id])));
        $integrity = app(FinanceReports::class)->integrity($this->date);
        $this->assertCount(0, $integrity['unbalanced']);
        $this->assertSame(0, $integrity['invalid_open_items']);
    }
}
