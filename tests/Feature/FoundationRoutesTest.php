<?php

namespace Tests\Feature;

use Tests\TestCase;

class FoundationRoutesTest extends TestCase
{
    public function test_framework_health_route_is_available(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_erp_health_page_checks_the_database_connection(): void
    {
        $this->withoutVite();

        $this->get('/health')->assertOk()->assertSee('Database')->assertSee('Connected');
    }

    public function test_not_found_responses_use_the_erp_error_layout(): void
    {
        $this->withoutVite();

        $this->get('/not-a-real-page')->assertNotFound()->assertSee('We could not find that page.');
    }

    public function test_login_page_and_authenticated_dashboard_route_are_available(): void
    {
        $this->withoutVite();

        $this->get('/login')->assertOk()->assertSee('Sign in to your company workspace.');
        $this->get('/dashboard')->assertRedirect(route('login'));
    }

    public function test_all_foundation_modules_are_registered(): void
    {
        $this->assertSame([
            'core', 'identity', 'parties', 'catalog', 'purchasing', 'inventory',
            'warehouses', 'processing', 'production', 'finished-goods', 'sales',
            'accounting', 'hr-payroll', 'reporting',
        ], array_keys(config('modules')));
    }
}
