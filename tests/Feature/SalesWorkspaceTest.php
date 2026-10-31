<?php

namespace Tests\Feature;

use App\Models\AccessRole;
use App\Models\Document;
use App\Models\PartyRole;
use App\Models\PartyRoleType;
use App\Models\PaymentTerm;
use App\Models\Permission;
use App\Models\SalesDispatch;
use App\Models\SalesInvoice;
use App\Models\SalesReturn;
use App\Models\SupplierProfile;
use App\Models\User;
use Database\Seeders\SalesSetupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\Support\SalesFixtures;
use Tests\TestCase;

class SalesWorkspaceTest extends TestCase
{
    use RefreshDatabase,SalesFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->salesFixtures();
    }

    private function fixture(string $name, $r): void
    {
        if (getenv('SALES_BROWSER_FIXTURES')) {
            $dir = storage_path('framework/testing/sales-browser');
            if (! is_dir($dir)) {
                mkdir($dir, 0777, true);
            }file_put_contents($dir.'/'.$name.'.html', $r->getContent());
        }
    }

    public function test_all_guided_forms_real_confirmation_history_and_a4_pdfs_work(): void
    {
        $this->fixture('overview', $this->get('/sales')->assertOk()->assertSee('sales-sidebar-links'));
        $this->fixture('order', $this->get(route('sales.create', ['kind' => 'order', 'customer' => $this->customer->id]))->assertOk()->assertSee('Search items'));
        $order = $this->salesOrder('40');
        $this->fixture('show', $this->get(route('sales.show', $order))->assertOk()->assertSee('Reserve actual stock'));
        $this->fixture('dispatch', $this->get(route('sales.create', ['kind' => 'dispatch', 'customer' => $this->customer->id]))->assertOk()->assertSee('Choose actual stock lot'));
        $data = $this->dispatchData($order);
        $data['intent'] = 'confirm';
        $this->post(route('sales.store'), $data)->assertRedirect()->assertSessionHasNoErrors();
        $dispatch = Document::where('idempotency_key', $data['idempotency_key'])->firstOrFail();
        $this->fixture('invoice', $this->get(route('sales.create', ['kind' => 'invoice', 'customer' => $this->customer->id]))->assertOk()->assertSee('preview'));
        $invoice = $this->sales->confirm($this->admin->id, $this->invoiceData($dispatch));
        $this->fixture('return', $this->get(route('sales.create', ['kind' => 'return', 'customer' => $this->customer->id]))->assertOk()->assertSee('New return lot number'));
        $return = $this->sales->confirm($this->admin->id, $this->salesData('return', [['sales_dispatch_line_id' => SalesDispatch::find($dispatch->id)->lines->first()->id, 'base_quantity' => '2', 'warehouse_location_id' => $this->destination->id, 'lot_number' => 'HTTP-RETURN', 'condition_code' => 'saleable']]));
        $this->fixture('credit', $this->get(route('sales.create', ['kind' => 'credit', 'customer' => $this->customer->id, 'invoice' => $invoice->id]))->assertOk()->assertSee('Original invoice match'));
        $match = SalesInvoice::find($invoice->id)->lines->first()->matches->first();
        $credit = $this->sales->confirm($this->admin->id, $this->salesData('credit', [['sales_invoice_dispatch_match_id' => $match->id, 'sales_return_line_id' => SalesReturn::find($return->id)->lines->first()->id, 'credited_base_quantity' => '2', 'net_amount' => '200']]) + ['sales_invoice_id' => $invoice->id, 'credit_kind' => 'physical_return']);
        $this->fixture('availability', $this->get(route('sales.availability'))->assertOk());
        $this->fixture('history', $this->get(route('sales.history', $this->customer))->assertOk()->assertSee('Current credit exposure'));
        $this->get(route('sales.index', ['kind' => 'invoice']))->assertOk();
        $this->get(route('sales.items', ['q' => $this->variant->item->sku]))->assertOk()->assertJsonFragment(['id' => $this->variant->item_id]);
        if (getenv('SALES_BROWSER_FIXTURES')) {
            file_put_contents(storage_path('framework/testing/sales-browser/items.json'), $this->get(route('sales.items'))->getContent());
        }
        foreach ([$order, $dispatch, $invoice, $return, $credit] as $doc) {
            $pdf = $this->get(route('sales.document', [$doc, 'pdf']))->assertOk()->assertHeader('Content-Type', 'application/pdf');
            $this->assertStringStartsWith('%PDF-', $pdf->getContent());
            $this->get(route('sales.document', [$doc, 'print']))->assertOk();
            if (getenv('TRANSACTION_PDF_FIXTURES')) {
                file_put_contents(storage_path('framework/testing/transaction-pdf/'.$doc->type->code.'.pdf'), $pdf->getContent());
            }
        }
    }

    public function test_wholesale_operator_can_sell_but_cannot_enter_other_modules_or_change_credit_settings(): void
    {
        $this->seed(SalesSetupSeeder::class);
        $u = User::factory()->create();
        $role = AccessRole::where('code', 'wholesale')->firstOrFail();
        $u->accessRoles()->attach($role->id, ['assigned_by' => $this->admin->id]);
        Auth::logout();
        $this->post('/login', ['email' => $u->email, 'password' => 'password'])->assertRedirect(route('sales.overview'));
        $this->get('/dashboard')->assertOk()->assertSee('Wholesale sales')->assertDontSee('href="'.route('purchasing.overview').'"', false);
        $this->actingAs($u);
        $this->get('/sales')->assertOk()->assertDontSee('href="'.route('production.overview').'"', false);
        foreach (['/purchasing', '/processing', '/production', '/finished-goods', '/identity/users', '/company/settings'] as $url) {
            $r = $this->get($url);
            $this->assertContains($r->status(), [403, 404]);
        }
        $this->get(route('sales.items'))->assertOk()->assertDontSee('cost_pkr');
        $this->get(route('customer.edit', $this->customer->party))->assertOk()->assertSee('Ask the administrator to change credit settings.');
        $data = ['party' => ['code' => $this->customer->party->code, 'legal_name' => 'Customer unchanged', 'party_kind' => 'organization', 'is_active' => 1], 'profile' => ['payment_term_id' => PaymentTerm::first()->id, 'credit_limit' => '99999999', 'credit_hold' => 0]];
        $this->put(route('customer.update', $this->customer->party), $data)->assertForbidden();
        $this->assertSame('1000000.0000', $this->customer->customerProfile->fresh()->credit_limit);
        $this->sales->confirm($u->id, $this->salesData('order', [['item_id' => $this->variant->item_id, 'quantity' => '1', 'unit_price' => '100']]));
    }

    public function test_backend_policies_prevent_read_only_posting_cross_module_documents_and_supplier_only_roles(): void
    {
        $o = $this->salesOrder('5');
        $u = User::factory()->create();
        $role = AccessRole::create(['code' => 'sales-viewer', 'name' => 'Sales viewer', 'is_active' => true]);
        $role->permissions()->attach(Permission::where('code', 'sales.documents.view')->value('id'));
        $u->accessRoles()->attach($role->id, ['assigned_by' => $this->admin->id]);
        $this->actingAs($u);
        $this->get(route('sales.show', $o))->assertOk()->assertDontSee('name="reason"', false);
        $this->post(route('sales.store'), $this->dispatchData($o, '1') + ['intent' => 'confirm'])->assertForbidden();
        $foreign = Document::whereHas('type', fn ($q) => $q->where('module_code', 'production'))->firstOrFail();
        $this->get(route('sales.show', $foreign))->assertForbidden();
        $this->get(route('sales.document', [$foreign, 'pdf']))->assertForbidden();
        $this->actingAs($this->admin);
        $supplier = PartyRole::create(['party_id' => $this->customer->party_id, 'party_role_type_id' => PartyRoleType::where('code', 'supplier')->value('id'), 'is_active' => true]);
        SupplierProfile::create(['party_role_id' => $supplier->id, 'payment_term_id' => PaymentTerm::first()->id]);
        $d = $this->salesData('order', [['item_id' => $this->variant->item_id, 'quantity' => '1', 'unit_price' => '100']]);
        $d['customer_party_role_id'] = $supplier->id;
        $this->post(route('sales.store'), $d + ['intent' => 'confirm'])->assertSessionHasErrors('customer_party_role_id');
    }
}
