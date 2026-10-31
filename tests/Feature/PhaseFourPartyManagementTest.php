<?php

namespace Tests\Feature;

use App\Models\AccessRole;
use App\Models\Party;
use App\Models\PartyRole;
use App\Models\PaymentTerm;
use App\Models\ProcessingService;
use App\Models\User;
use App\Models\VendorService;
use App\Modules\Parties\Services\PartyManagementService;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\IdentityAuthorizationSeeder;
use Database\Seeders\PartyConfigurationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PhaseFourPartyManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CurrencySeeder::class, PartyConfigurationSeeder::class, IdentityAuthorizationSeeder::class]);
    }

    public function test_supplier_can_become_vendor_through_same_party_identity(): void
    {
        $admin = $this->admin();
        $term = PaymentTerm::query()->where('code', 'net-30')->firstOrFail();
        $this->actingAs($admin)->post(route('supplier.store'), $this->payload('supplier', $term->id))->assertRedirect(route('supplier.index'));
        $this->get(route('supplier.index'))->assertOk()->assertSee('Irfan Textiles');
        $party = Party::query()->where('code', 'PTY-100')->firstOrFail();

        $service = ProcessingService::query()->where('code', 'dyeing')->firstOrFail();
        $vendorPayload = $this->payload('vendor', $term->id);
        $vendorPayload['existing_party_id'] = $party->id;
        $vendorPayload['profile']['service_ids'] = [$service->id];
        $this->post(route('vendor.store'), $vendorPayload)->assertRedirect(route('vendor.index'));

        $this->assertSame(1, Party::query()->count());
        $customerPayload = $this->payload('customer', $term->id);
        $customerPayload['party']['code'] = $party->code;
        $customerPayload['existing_party_id'] = $party->id;
        $this->post(route('customer.store'), $customerPayload)->assertRedirect(route('customer.index'));
        $this->get(route('customer.edit', $party))->assertOk();
        $this->assertSame(1, Party::query()->count());
        $this->assertSame(3, PartyRole::query()->where('party_id', $party->id)->count());
        $this->assertSame(1, $party->contacts()->count());
        $this->assertSame(1, $party->addresses()->count());
        $this->assertDatabaseHas('vendor_profiles', ['party_role_id' => PartyRole::query()->where('party_id', $party->id)->whereHas('type', fn ($query) => $query->where('code', 'vendor'))->value('id')]);
        $this->assertDatabaseHas('vendor_services', ['processing_service_id' => $service->id]);
        $vendorService = VendorService::query()->where('processing_service_id', $service->id)->firstOrFail();
        $this->get(route('vendor.rates', $vendorService))->assertOk()->assertSee('Dyeing');
        $this->delete(route('customer.deactivate', $party))->assertRedirect(route('customer.index'));
        $this->get(route('customer.index', ['status' => 'inactive']))->assertOk()->assertSee('Inactive');
        $this->assertDatabaseHas('party_roles', ['party_id' => $party->id, 'is_active' => false]);
    }

    public function test_vendor_rate_is_meter_pkrs_snapshot_ready_and_overlapping_ranges_are_rejected(): void
    {
        $admin = $this->admin();
        $term = PaymentTerm::query()->where('code', 'immediate')->firstOrFail();
        $service = ProcessingService::query()->where('code', 'dyeing')->firstOrFail();
        $party = app(PartyManagementService::class)->save('vendor', [
            'party' => ['code' => 'V-1', 'legal_name' => 'Dye House', 'party_kind' => 'organization', 'is_active' => true],
            'profile' => ['payment_term_id' => $term->id, 'service_ids' => [$service->id]],
        ], null, $admin);
        $vendorRole = $party->roles()->whereHas('type', fn ($query) => $query->where('code', 'vendor'))->firstOrFail();
        $vendorService = VendorService::query()->where('vendor_party_role_id', $vendorRole->id)->firstOrFail();
        $this->actingAs($admin)->get(route('vendor.rates', $vendorService))->assertOk()->assertSee('Publish effective rate');
        $rates = app(PartyManagementService::class);
        $first = $rates->publishRate($vendorService, ['effective_from' => '2026-01-01', 'effective_to' => null, 'billing_unit_id' => $service->billing_unit_id, 'unit_rate' => '12.50000000', 'rate_reason' => 'Approved yearly contract'], $admin);
        $second = $rates->publishRate($vendorService, ['effective_from' => '2027-01-01', 'effective_to' => null, 'billing_unit_id' => $service->billing_unit_id, 'unit_rate' => '14.25000000', 'rate_reason' => 'Prospective renewal'], $admin);

        $this->assertSame('2027-01-01', $first->fresh()->effective_to->toDateString());
        $this->assertSame($second->id, $rates->rateOn($vendorService, '2027-01-01')->id);
        $this->assertSame('PKR', $second->currency_code);
        $this->expectException(ValidationException::class);
        $rates->publishRate($vendorService, ['effective_from' => '2026-12-15', 'effective_to' => '2027-02-01', 'billing_unit_id' => $service->billing_unit_id, 'unit_rate' => '13', 'rate_reason' => 'Invalid overlap'], $admin);
    }

    public function test_role_screens_and_mutations_require_module_permissions(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('supplier.index'))->assertForbidden();
        $this->actingAs($user)->post(route('supplier.store'), [])->assertForbidden();
    }

    /** @return array<string,mixed> */
    private function payload(string $role, int $paymentTermId): array
    {
        return [
            'party' => ['code' => 'PTY-100', 'legal_name' => 'Irfan Textiles', 'party_kind' => 'organization', 'phone' => '03001234567', 'email' => 'party@example.test', 'is_active' => '1'],
            'profile' => ['payment_term_id' => $paymentTermId, 'purchase_notes' => 'Purchasing terms', 'contractor_notes' => 'Handle carefully', 'sales_notes' => 'Wholesale customer', 'credit_limit' => '0.0000', 'credit_hold' => '0', 'service_ids' => []],
            'contacts' => [['name' => 'Accounts', 'designation' => 'Accounts', 'phone' => '03001112222', 'email' => 'accounts@example.test']],
            'addresses' => [['label' => 'Head office', 'address_type' => 'business', 'address_line_1' => 'Textile Market', 'city' => 'Karachi', 'country_code' => 'PK']],
        ];
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $role = AccessRole::query()->where('code', 'super-admin')->firstOrFail();
        $admin->accessRoles()->attach($role->id, ['assigned_by' => $admin->id]);

        return $admin;
    }
}
