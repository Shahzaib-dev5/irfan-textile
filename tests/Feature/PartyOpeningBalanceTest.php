<?php

namespace Tests\Feature;

use App\Models\AccessRole;
use App\Models\AccountingPeriod;
use App\Models\AuditEvent;
use App\Models\Document;
use App\Models\JournalEntry;
use App\Models\Party;
use App\Models\PartyOpenItem;
use App\Models\PartyRole;
use App\Models\PaymentTerm;
use App\Models\PostingAccount;
use App\Models\ProcessingService;
use App\Models\StockMovement;
use App\Models\User;
use Database\Seeders\SalesSetupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\FinanceFixtures;
use Tests\TestCase;

class PartyOpeningBalanceTest extends TestCase
{
    use FinanceFixtures, RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        if (! app()->environment('testing') || config('database.connections.mysql.database') !== 'irfan_textile_phase3_test') {
            throw new \LogicException('Party opening tests require the isolated test database.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->financeFixtures();
    }

    public function test_customer_old_balance_creates_receivable_and_accepts_partial_receipt(): void
    {
        $form = $this->get(route('parties.create'))->assertOk()->assertSee('Old unpaid balance');
        if (getenv('PARTY_OPENING_BROWSER_FIXTURES')) {
            $folder = storage_path('framework/testing/party-opening-browser');
            if (! is_dir($folder)) {
                mkdir($folder, 0777, true);
            }
            file_put_contents($folder.'/create.html', $form->getContent());
        }
        $this->post(route('parties.store'), $this->payload('customer', 'receive'))->assertRedirect(route('parties.index'))->assertSessionHasNoErrors();
        $item = $this->itemFor('OLD-CUSTOMER');
        $this->assertSame('1000.0000', $item->journalLine->debit);
        $this->assertSame('party_receivable', $item->journalLine->account->control_type);
        $this->assertSame('1000.0000', $item->original_amount);
        $this->assertSame('2026-01-05', $item->due_on->format('Y-m-d'));
        $this->assertSame('posted', $item->journalLine->document->status);
        $this->assertSame('finance_opening', $item->journalLine->document->type->code);
        $this->assertSame('1000.0000', $item->journalLine->document->journal->total_credit);
        $this->assertDatabaseHas('journal_lines', ['journal_entry_id' => $item->journalLine->journal_entry_id, 'ledger_account_id' => PostingAccount::where('purpose_code', 'opening_equity')->value('ledger_account_id'), 'credit' => '1000.0000', 'party_id' => null]);
        $this->get(route('finance.report', ['report' => 'open']))->assertOk()->assertSee('Old customer');
        $this->get(route('parties.ledger', ['party' => $item->journalLine->party_id]))->assertOk()->assertSee('OLD-INV-001');
        $guide = $this->get(route('finance.create', ['kind' => 'receipt']))->assertOk()->viewData('guide');
        $this->assertTrue($guide['items']->contains(fn ($row) => $row['id'] === (string) $item->id && $row['amount'] === '1000.0000'));
        $this->post(route('finance.store'), $this->financeData('receipt', [
            'party_role_id' => $item->journalLine->party_role_id, 'settlement_account_id' => $item->journalLine->ledger_account_id,
            'bank_account_id' => $this->bank->id, 'amount' => '400', 'method' => 'cash',
            'apply_to_open_item_id' => $item->id, 'apply_amount' => '400',
        ]))->assertSessionHasNoErrors();
        $this->assertSame('600.0000', $this->finance->outstanding($item->id));
        $this->assertSame(0, StockMovement::count());
        $this->assertDatabaseCount('sales_invoices', 0);
    }

    public function test_supplier_and_vendor_openings_accept_partial_payments(): void
    {
        $this->cashOpening();
        foreach (['supplier', 'vendor'] as $role) {
            $this->post(route('parties.store'), $this->payload($role, 'pay'))->assertSessionHasNoErrors()->assertRedirect(route('parties.index'));
            $item = $this->itemFor('OLD-'.strtoupper($role));
            $this->assertSame('1000.0000', $item->journalLine->credit);
            $this->assertSame('party_payable', $item->journalLine->account->control_type);
            $this->assertSame($role, PartyRole::findOrFail($item->journalLine->party_role_id)->type->code);
            $this->post(route('finance.store'), $this->financeData('payment', [
                'party_role_id' => $item->journalLine->party_role_id, 'settlement_account_id' => $item->journalLine->ledger_account_id,
                'bank_account_id' => $this->bank->id, 'amount' => '300', 'method' => 'cash',
                'apply_to_open_item_id' => $item->id, 'apply_amount' => '300',
            ]))->assertSessionHasNoErrors();
            $this->assertSame('700.0000', $this->finance->outstanding($item->id));
        }
        $this->assertDatabaseCount('purchase_invoices', 0);
        $this->assertSame(0, StockMovement::count());
    }

    public function test_multi_role_party_posts_only_to_the_selected_vendor_role(): void
    {
        $data = $this->payload('vendor', 'pay');
        $data['roles'] = ['supplier', 'vendor', 'customer'];
        $data['profiles']['supplier'] = $data['profiles']['vendor'];
        $data['profiles']['customer'] = ['payment_term_id' => PaymentTerm::firstOrFail()->id, 'credit_limit' => '5000', 'credit_hold' => '0'];
        $this->post(route('parties.store'), $data)->assertSessionHasNoErrors();
        $party = Party::where('code', 'OLD-VENDOR')->firstOrFail();
        $this->assertSame(3, $party->roles()->count());
        $this->assertSame(1, PartyOpenItem::count());
        $this->assertSame('vendor', PartyRole::findOrFail($this->itemFor('OLD-VENDOR')->journalLine->party_role_id)->type->code);
    }

    public function test_no_old_balance_preserves_normal_party_creation_without_posting(): void
    {
        $data = $this->payload('customer', 'receive');
        $data['opening']['direction'] = 'none';
        $this->post(route('parties.store'), $data)->assertSessionHasNoErrors()->assertRedirect(route('parties.index'));
        $this->assertDatabaseHas('parties', ['code' => 'OLD-CUSTOMER']);
        $this->assertSame(0, Document::count());
        $this->assertSame(0, JournalEntry::count());
    }

    public function test_identical_submit_replays_and_changed_request_key_reuse_is_rejected(): void
    {
        $data = $this->payload('customer', 'receive');
        $this->post(route('parties.store'), $data)->assertSessionHasNoErrors();
        $auditCount = AuditEvent::count();
        $this->post(route('parties.store'), $data)->assertSessionHasNoErrors()->assertRedirect(route('parties.index'));
        $this->assertSame(1, Party::where('code', 'OLD-CUSTOMER')->count());
        $this->assertSame(1, Document::count());
        $this->assertSame(1, PartyOpenItem::count());
        $this->assertSame($auditCount, AuditEvent::count());
        $data['opening']['amount'] = '2000';
        $this->post(route('parties.store'), $data)->assertSessionHasErrors('opening.idempotency_key');
        $data['party']['code'] = 'ANOTHER-PARTY';
        $this->post(route('parties.store'), $data)->assertSessionHasErrors('opening.idempotency_key');
        $this->assertDatabaseMissing('parties', ['code' => 'ANOTHER-PARTY']);
        $this->assertSame(1, Document::count());
    }

    public function test_invalid_openings_do_not_leave_partial_parties_or_financial_records(): void
    {
        $cases = [
            ['amount', '0'], ['amount', '-5'], ['amount', '1.00001'], ['amount', '1e3'],
            ['reference', ''], ['due_on', 'not-a-date'], ['document_date', today()->addDay()->format('Y-m-d')],
            ['role', 'supplier'], ['direction', 'pay'],
        ];
        $before = AuditEvent::count();
        foreach ($cases as [$field, $value]) {
            $data = $this->payload('customer', 'receive');
            $data['opening'][$field] = $value;
            $this->post(route('parties.store'), $data)->assertSessionHasErrors();
            $this->assertDatabaseMissing('parties', ['code' => 'OLD-CUSTOMER']);
            $this->assertSame(0, Document::count());
            $this->assertSame($before, AuditEvent::count());
        }
        AccountingPeriod::query()->update(['status' => 'closed', 'closed_at' => now(), 'closed_by' => $this->admin->id]);
        $this->post(route('parties.store'), $this->payload('customer', 'receive'))->assertSessionHasErrors();
        $this->assertDatabaseMissing('parties', ['code' => 'OLD-CUSTOMER']);
        $this->assertSame($before, AuditEvent::count());
    }

    public function test_missing_posting_account_rolls_back_party_roles_address_and_audit(): void
    {
        PostingAccount::where('purpose_code', 'opening_equity')->delete();
        $before = AuditEvent::count();
        $this->post(route('parties.store'), $this->payload('customer', 'receive'))->assertSessionHasErrors('opening.amount');
        $this->assertDatabaseMissing('parties', ['code' => 'OLD-CUSTOMER']);
        $this->assertDatabaseCount('customer_profiles', 0);
        $this->assertDatabaseCount('party_addresses', 0);
        $this->assertSame(0, Document::count());
        $this->assertSame($before, AuditEvent::count());
    }

    public function test_wholesale_cannot_post_an_opening_but_can_create_a_customer(): void
    {
        $this->seed(SalesSetupSeeder::class);
        $user = User::factory()->create();
        $user->accessRoles()->attach(AccessRole::where('code', 'wholesale')->value('id'), ['assigned_by' => $this->admin->id]);
        $this->actingAs($user)->get(route('parties.create'))->assertOk()->assertDontSee('data-party-opening', false);
        $data = $this->payload('customer', 'receive');
        $this->post(route('parties.store'), $data)->assertForbidden();
        $this->assertDatabaseMissing('parties', ['code' => 'OLD-CUSTOMER']);
        $data['opening']['direction'] = 'none';
        $data['profiles']['customer']['credit_limit'] = '0';
        $this->post(route('parties.store'), $data)->assertSessionHasNoErrors()->assertRedirect(route('parties.index'));
        $this->assertSame(0, Document::count());
    }

    public function test_party_edit_cannot_repost_or_change_the_opening(): void
    {
        $data = $this->payload('customer', 'receive');
        $this->post(route('parties.store'), $data)->assertSessionHasNoErrors();
        $party = Party::where('code', 'OLD-CUSTOMER')->firstOrFail();
        $this->get(route('parties.edit', $party))->assertOk()->assertDontSee('data-party-opening', false);
        $this->put(route('parties.update', $party), $data)->assertSessionHasErrors('opening');
        unset($data['opening']);
        $data['party']['legal_name'] = 'Updated customer';
        $this->put(route('parties.update', $party), $data)->assertSessionHasNoErrors()->assertRedirect(route('parties.index'));
        $this->assertSame(1, Document::count());
        $this->assertSame('1000.0000', $this->finance->outstanding($this->itemFor('OLD-CUSTOMER')->id));
    }

    private function itemFor(string $code): PartyOpenItem
    {
        return PartyOpenItem::whereHas('journalLine.party', fn ($query) => $query->where('code', $code))->firstOrFail();
    }

    private function payload(string $role, string $direction): array
    {
        return [
            'party' => ['code' => 'OLD-'.strtoupper($role), 'legal_name' => 'Old '.$role, 'party_kind' => 'organization', 'is_active' => '1', 'address' => 'Verified address'],
            'roles' => [$role],
            'profiles' => [$role => ['payment_term_id' => PaymentTerm::firstOrFail()->id, ...($role === 'customer' ? ['credit_limit' => '5000', 'credit_hold' => '0'] : []), ...($role === 'vendor' ? ['service_ids' => [ProcessingService::where('code', 'dyeing')->value('id')]] : [])]],
            'opening' => ['direction' => $direction, 'role' => $role, 'amount' => '1000', 'reference' => 'OLD-INV-001', 'document_date' => $this->date, 'due_on' => '2026-01-05', 'idempotency_key' => (string) Str::uuid()],
        ];
    }
}
