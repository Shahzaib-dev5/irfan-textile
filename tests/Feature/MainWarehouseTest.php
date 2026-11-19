<?php

namespace Tests\Feature;

use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Modules\Warehouses\Services\MainWarehouse;
use App\Modules\Warehouses\Services\WarehouseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\InventoryFixtures;
use Tests\TestCase;

class MainWarehouseTest extends TestCase
{
    use InventoryFixtures, RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.connections.mysql.database') !== 'irfan_textile_phase3_test') {
            throw new \LogicException('Use the isolated test schema.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->inventoryFixtures();
        DB::table('warehouse_locations')->delete();
        DB::table('warehouses')->delete();
        app(MainWarehouse::class)->setup($this->admin->id);
        $this->source = app(MainWarehouse::class)->location();
    }

    public function test_multiple_warehouses_can_be_created_but_locations_cannot_be_managed(): void
    {
        $this->get(route('warehouses.index'))->assertOk()->assertSee('Main Warehouse')->assertDontSee('Add location')->assertSee('Add warehouse');
        $this->get(route('warehouses.create', ['location' => 1]))->assertNotFound();
        $this->post(route('warehouses.store', ['location' => 1]), ['code' => 'RACK', 'name' => 'Rack', 'is_active' => 1])->assertSessionHasErrors();
        $this->post(route('warehouses.store'), ['code' => 'SECOND', 'name' => 'Second Warehouse', 'is_active' => 1])->assertSessionHasNoErrors();
        $this->post(route('warehouses.store'), ['code' => 'SECOND', 'name' => 'Duplicate', 'is_active' => 1])->assertSessionHasErrors('code');
        $this->put(route('warehouses.update', $this->source->warehouse_id), ['code' => 'MAIN', 'name' => 'Main Warehouse', 'is_active' => 1, 'address' => 'Main shop'])->assertSessionHasNoErrors();
        $this->assertSame(2, Warehouse::count());
        $this->assertSame(2, WarehouseLocation::count());
        $this->assertSame('MAIN', Warehouse::where('code', 'SECOND')->firstOrFail()->locations->sole()->code);
    }

    public function test_opening_and_transfers_choose_warehouses_without_internal_locations(): void
    {
        $second = app(WarehouseService::class)->save($this->admin->id, ['code' => 'SECOND', 'name' => 'Second Warehouse', 'is_active' => 1]);
        $this->destination = $second->locations->sole();
        $form = $this->get(route('inventory.documents.create'))->assertOk()->assertSee('Main Warehouse')->assertDontSee('Storage location');
        $form->assertSee('Second Warehouse');
        if (getenv('MAIN_WAREHOUSE_BROWSER_FIXTURES')) {
            $directory = storage_path('framework/testing/main-warehouse-browser');
            if (! is_dir($directory)) {
                mkdir($directory, 0777, true);
            }
            file_put_contents($directory.'/create.html', $form->getContent());
        }
        $this->get(route('physical-stock.create'))->assertOk()->assertDontSee('Choose receiving location');
        $this->get(route('inventory.documents.create', ['kind' => 'transfer']))->assertOk()->assertSee('Second Warehouse')->assertDontSee('Destination location');
        $doc = $this->opening();
        $this->assertSame($this->source->id, $doc->adjustment->lines->first()->position->warehouse_location_id);
        $this->assertSame('100.000000', $doc->adjustment->lines->first()->position->balance->on_hand_quantity);
        $this->postDocument($this->transfer($this->position($doc), '20'));
        $this->assertDatabaseHas('stock_positions', ['warehouse_location_id' => $this->destination->id]);
        $this->assertSame('80.000000', $doc->adjustment->lines->first()->position->balance->fresh()->on_hand_quantity);
        $this->put(route('warehouses.update', $second), ['code' => 'SECOND', 'name' => 'Second Warehouse', 'is_active' => 0])->assertSessionHasErrors();
    }

    public function test_dummy_reset_defaults_to_preview_and_rejects_wrong_database(): void
    {
        $this->opening();
        $this->artisan('warehouse:reset-dummy', ['--database' => 'wrong'])->assertFailed();
        $this->artisan('warehouse:reset-dummy', ['--database' => 'irfan_textile_phase3_test'])->assertSuccessful();
        $this->assertDatabaseCount('stock_movements', 1);
        $this->artisan('warehouse:reset-dummy', ['--database' => 'irfan_textile_phase3_test', '--execute' => true, '--actor' => $this->admin->id])->assertFailed();
        $this->assertDatabaseCount('stock_movements', 1);
    }
}
