<?php

namespace Tests\Feature;

use App\Models\AccessRole;
use App\Models\AuditEvent;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Permission;
use App\Models\StockMovement;
use App\Models\User;
use Database\Seeders\ProductionSetupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InventoryFixtures;
use Tests\TestCase;

class ProductionSetupTest extends TestCase
{
    use InventoryFixtures, RefreshDatabase;

    public function test_setup_is_repeatable_and_only_extends_existing_admin_production_grants(): void
    {
        $this->inventoryFixtures();
        $admin = AccessRole::where('code', 'super-admin')->firstOrFail();
        $admin->permissions()->detach(Permission::where('module_code', 'production')->pluck('id'));
        $before = $admin->permissions()->orderBy('permissions.id')->pluck('permissions.id')->all();
        $wholesale = AccessRole::create(['code' => 'wholesale-operator', 'name' => 'Wholesale operator', 'is_active' => true]);
        $wholesale->permissions()->attach(Permission::where('module_code', 'sales')->firstOrFail()->id);
        $wholesaleBefore = $wholesale->permissions()->pluck('permissions.id')->all();
        $usersBefore = User::count();

        $this->seed(ProductionSetupSeeder::class);
        $this->seed(ProductionSetupSeeder::class);

        $this->assertSame(5, $admin->permissions()->where('module_code', 'production')->count());
        $this->assertSame(count($before) + 5, $admin->permissions()->count());
        $this->assertSame($before, $admin->permissions()->where('module_code', '!=', 'production')->orderBy('permissions.id')->pluck('permissions.id')->all());
        $this->assertSame($wholesaleBefore, $wholesale->permissions()->pluck('permissions.id')->all());
        $this->assertSame($usersBefore, User::count());
        $this->assertSame(0, Document::count());
        $this->assertSame(0, StockMovement::count());
        $this->assertSame(2, DocumentType::where('module_code', 'production')->count());
        $this->assertSame(1, AuditEvent::where('action', 'production_permissions_granted')->count());
    }
}
