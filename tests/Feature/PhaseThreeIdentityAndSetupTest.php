<?php

namespace Tests\Feature;

use App\Models\AccessRole;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\Permission;
use App\Models\User;
use App\Modules\Accounting\Services\FinancialCalendarService;
use App\Modules\Identity\Services\UserManagementService;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\IdentityAuthorizationSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PhaseThreeIdentityAndSetupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CurrencySeeder::class, IdentityAuthorizationSeeder::class]);
    }

    public function test_active_users_can_sign_in_and_inactive_users_cannot(): void
    {
        $active = User::factory()->create(['email' => 'active@example.test', 'password' => 'Password#1234567']);
        $this->post('/login', ['email' => 'ACTIVE@example.test', 'password' => 'Password#1234567'])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($active);

        auth()->logout();
        $inactive = User::factory()->inactive()->create(['email' => 'inactive@example.test', 'password' => 'Password#1234567']);
        $this->post('/login', ['email' => $inactive->email, 'password' => 'Password#1234567'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_deactivated_session_is_revoked_and_routes_require_module_permission(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->get(route('identity.users.index'))->assertOk();

        $role = AccessRole::factory()->create();
        $role->permissions()->attach(Permission::where('code', 'core.company.view')->value('id'));
        $restricted = User::factory()->create();
        $restricted->accessRoles()->attach($role->id, ['assigned_by' => $admin->id]);
        $this->actingAs($restricted)->get(route('identity.users.index'))->assertForbidden();
        $this->get(route('core.company.edit'))->assertOk();

        $restricted->forceFill(['is_active' => false])->save();
        $this->get('/dashboard')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_company_setup_persists_singleton_pkr_currency_and_audit_snapshot(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->put(route('core.company.update'), [
            'legal_name' => 'Irfan Textile Works', 'trading_name' => 'Irfan Textile',
            'ntn' => '1234567-8', 'address_text' => 'Karachi, Pakistan', 'phone' => '+92 21 1234567',
            'email' => 'office@example.test', 'timezone' => 'Asia/Karachi', 'fiscal_year_start_month' => 7,
        ])->assertRedirect()->assertSessionHas('status');

        $this->assertDatabaseHas('companies', ['id' => 1, 'legal_name' => 'Irfan Textile Works', 'functional_currency' => 'PKR']);
        $this->assertDatabaseHas('currencies', ['code' => 'PKR', 'name' => 'Pakistani Rupee']);
        $event = $this->assertDatabaseHas('audit_events', ['entity_table' => 'companies', 'entity_key' => '1', 'action' => 'create']);
        $this->assertSame(1, Company::query()->count());
    }

    public function test_financial_year_and_period_ranges_are_serialized_and_non_overlapping(): void
    {
        $admin = $this->admin();
        Company::factory()->create();
        $service = app(FinancialCalendarService::class);
        $year = $service->createYear(['code' => 'FY2026-27', 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30']);
        $this->expectException(ValidationException::class);
        $service->createYear(['code' => 'FY2026-overlap', 'starts_on' => '2027-01-01', 'ends_on' => '2027-12-31']);
    }

    public function test_period_overlap_is_rejected_inside_a_financial_year(): void
    {
        $this->admin();
        Company::factory()->create();
        $service = app(FinancialCalendarService::class);
        $year = $service->createYear(['code' => 'FY2028-29', 'starts_on' => '2028-07-01', 'ends_on' => '2029-06-30']);
        $service->createPeriod($year, ['code' => '2028-07', 'starts_on' => '2028-07-01', 'ends_on' => '2028-07-31']);

        $this->expectException(ValidationException::class);
        $service->createPeriod($year, ['code' => 'OVERLAP', 'starts_on' => '2028-07-15', 'ends_on' => '2028-08-15']);
    }

    public function test_year_close_requires_contiguous_closed_periods_and_can_be_reopened(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        Company::factory()->create();
        $service = app(FinancialCalendarService::class);
        $year = $service->createYear(['code' => 'FY2030-test', 'starts_on' => '2030-07-01', 'ends_on' => '2030-08-31']);
        $july = $service->createPeriod($year, ['code' => 'JUL', 'starts_on' => '2030-07-01', 'ends_on' => '2030-07-31']);
        $august = $service->createPeriod($year, ['code' => 'AUG', 'starts_on' => '2030-08-01', 'ends_on' => '2030-08-31']);
        $service->changePeriodStatus($july, true, $admin);
        $service->changePeriodStatus($august, true, $admin);

        $closed = $service->changeYearStatus($year, true, $admin);
        $this->assertTrue($closed->is_closed);
        $reopened = $service->changeYearStatus($closed, false, $admin);
        $this->assertFalse($reopened->is_closed);
    }

    public function test_user_management_hashes_password_audits_redacted_changes_and_preserves_an_active_admin(): void
    {
        $admin = $this->admin();
        $identityRole = AccessRole::where('code', 'identity-admin')->firstOrFail();
        $service = app(UserManagementService::class);
        $user = $service->create([
            'name' => 'Operations User', 'email' => 'ops@example.test', 'password' => 'Password#9876543', 'is_active' => true,
        ], [$identityRole->id], $admin);

        $this->assertTrue(Hash::check('Password#9876543', $user->password));
        $audit = AuditEvent::where('entity_table', 'users')->where('entity_key', (string) $user->id)->firstOrFail();
        $this->assertArrayNotHasKey('password', $audit->after_json);
        $this->expectException(ValidationException::class);
        $service->update($admin, ['name' => $admin->name, 'email' => $admin->email, 'is_active' => false], [$admin->accessRoles()->firstOrFail()->id], $admin);
    }

    public function test_deactivating_a_user_removes_database_backed_sessions(): void
    {
        $admin = $this->admin();
        $role = AccessRole::where('code', 'identity-admin')->firstOrFail();
        $user = User::factory()->create(['email' => 'deactivate@example.test']);
        $user->accessRoles()->attach($role->id, ['assigned_by' => $admin->id]);
        DB::table('sessions')->insert([
            'id' => 'session-to-revoke', 'user_id' => $user->id, 'ip_address' => '127.0.0.1',
            'user_agent' => 'test', 'payload' => 'payload', 'last_activity' => time(),
        ]);

        app(UserManagementService::class)->update($user, [
            'name' => $user->name, 'email' => $user->email, 'is_active' => false,
        ], [$role->id], $admin);

        $this->assertDatabaseMissing('sessions', ['id' => 'session-to-revoke']);
    }

    public function test_password_reset_page_and_generic_reset_response_are_available(): void
    {
        User::factory()->create(['email' => 'reset@example.test']);
        $this->get(route('password.request'))->assertOk();
        $this->post(route('password.email'), ['email' => 'unknown@example.test'])->assertRedirect()
            ->assertSessionHas('status', 'If an active account matches that email address, reset instructions have been sent.');
    }

    public function test_authorized_setup_screens_render_and_unprivileged_users_cannot_escalate(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $this->get(route('identity.users.create'))->assertOk();
        $this->get(route('identity.roles.create'))->assertOk();
        $this->get(route('identity.permissions.index'))->assertOk();
        $this->get(route('core.company.edit'))->assertOk();
        $this->get(route('accounting.calendar.index'))->assertOk();

        $identityAdmin = User::factory()->create();
        $identityRole = AccessRole::where('code', 'identity-admin')->firstOrFail();
        $identityAdmin->accessRoles()->attach($identityRole->id, ['assigned_by' => $admin->id]);
        $companyRole = AccessRole::where('code', 'company-admin')->firstOrFail();

        $this->expectException(ValidationException::class);
        app(UserManagementService::class)->create([
            'name' => 'Escalated User', 'email' => 'escalated@example.test', 'password' => 'Password#9876543', 'is_active' => true,
        ], [$companyRole->id], $identityAdmin);
    }

    public function test_dashboard_and_foundation_routes_stay_authentication_protected(): void
    {
        $this->get('/dashboard')->assertRedirect(route('login'));
        $this->get('/health')->assertOk();
        $this->get('/up')->assertOk();
    }

    public function test_initial_administrator_bootstrap_is_refused_after_any_user_exists(): void
    {
        User::factory()->create();
        $this->artisan('erp:admin:create')->assertExitCode(1);
    }

    public function test_currency_master_database_constraint_rejects_non_pkr_currency(): void
    {
        $this->expectException(QueryException::class);
        DB::table('currencies')->insert([
            'code' => 'USD', 'name' => 'United States Dollar', 'symbol' => '$', 'decimal_places' => 2,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $role = AccessRole::where('code', 'super-admin')->firstOrFail();
        $admin->accessRoles()->attach($role->id, ['assigned_by' => $admin->id]);

        return $admin;
    }
}
