<?php

namespace Tests\Feature;

use App\Models\AccessRole;
use App\Models\AuditEvent;
use App\Models\Document;
use App\Models\StockMovement;
use App\Models\User;
use Database\Seeders\SalesSetupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InventoryFixtures;
use Tests\TestCase;

class SalesSetupTest extends TestCase
{
    use InventoryFixtures,RefreshDatabase;

    public function test_scoped_setup_is_repeatable_preserves_other_roles_and_does_not_create_users_or_stock(): void
    {
        $this->inventoryFixtures();
        $other = AccessRole::where('code', 'identity-admin')->firstOrFail();
        $before = $other->permissions()->orderBy('permissions.id')->pluck('permissions.id')->all();
        $users = User::count();
        $this->seed(SalesSetupSeeder::class);
        $this->seed(SalesSetupSeeder::class);
        $w = AccessRole::where('code', 'wholesale')->firstOrFail();
        $this->assertSame(6, $w->permissions()->count());
        $this->assertFalse($w->permissions()->where('code', 'sales.credit.manage')->exists());
        $this->assertSame($before, $other->permissions()->orderBy('permissions.id')->pluck('permissions.id')->all());
        $this->assertSame($users, User::count());
        $this->assertSame(0, Document::count());
        $this->assertSame(0, StockMovement::count());
        $this->assertSame(1, AuditEvent::where('action', 'sales_permissions_granted')->count());
    }
}
