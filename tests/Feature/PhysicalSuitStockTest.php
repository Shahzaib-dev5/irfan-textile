<?php

namespace Tests\Feature;

use App\Models\AccessRole;
use App\Models\BillOfMaterial;
use App\Models\Document;
use App\Models\Permission;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\User;
use App\Modules\Accounting\Services\FinanceReports;
use App\Modules\Inventory\Services\PhysicalSuitStock;
use App\Modules\Inventory\Services\StockLedger;
use Database\Seeders\SalesSetupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\ProductionFixtures;
use Tests\TestCase;

class PhysicalSuitStockTest extends TestCase
{
    use ProductionFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->productionFixtures();
    }

    private function data(): array
    {
        return ['request_key' => (string) Str::uuid(), 'source' => 'opening', 'document_date' => $this->date, 'product_variant_id' => $this->variant->id, 'quantity' => '20', 'warehouse_location_id' => $this->destination->id, 'batch_number' => 'READY-001', 'volume_reference' => 'VOL-12', 'quality_status' => 'released', 'remarks' => 'Verified physical count and cost evidence.', 'reference' => 'Signed opening schedule', 'unit_cost' => '250'];
    }

    public function test_opening_suits_post_once_with_cost_and_render_stock(): void
    {
        $d = $this->data();
        $service = app(PhysicalSuitStock::class);
        $doc = $service->confirm($this->admin->id, $d);
        $this->assertSame($doc->id, $service->confirm($this->admin->id, $d)->id);
        $this->assertSame(1, StockMovement::count());
        $this->assertSame('VOL-12', StockLot::where('lot_number', 'READY-001')->value('external_lot_reference'));
        $this->get(route('physical-stock.index'))->assertOk()->assertSee('VOL-12')->assertSee('5000.0000');
        $this->get(route('physical-stock.create'))->assertOk()->assertSee('Add ready suits');
        $this->get(route('physical-stock.fabric'))->assertOk();
        $this->assertSame('0.0000', app(FinanceReports::class)->integrity($this->date)['inventory_difference']);
        $d['quantity'] = '21';
        $this->expectException(ValidationException::class);
        $service->confirm($this->admin->id, $d);
    }

    public function test_conversion_consumes_material_and_preserves_value(): void
    {
        $position = $this->position($this->opening('200', '10'));
        $d = $this->data();
        $d['source'] = 'made';
        unset($d['reference'], $d['unit_cost']);
        $d['materials'] = [['stock_position_id' => $position, 'quantity' => '120']];
        $service = app(PhysicalSuitStock::class);
        $doc = $service->confirm($this->admin->id, $d);
        $this->assertSame('posted', $doc->status);
        $this->assertSame('80.000000', app(StockLedger::class)->onHand($position));
        $this->assertSame(3, StockMovement::count());
        $this->assertSame($doc->id, $service->confirm($this->admin->id, $d)->id);
        $this->get(route('physical-stock.index'))->assertOk()->assertSee('1200.0000');
        $this->assertSame('0.0000', app(FinanceReports::class)->integrity($this->date)['inventory_difference']);
    }

    public function test_insufficient_material_rolls_back_all_created_records(): void
    {
        $position = $this->position($this->opening('10', '10'));
        $d = $this->data();
        $d['source'] = 'made';
        $d['materials'] = [['stock_position_id' => $position, 'quantity' => '120']];
        $before = Document::count();
        $recipes = BillOfMaterial::count();
        try {
            app(PhysicalSuitStock::class)->confirm($this->admin->id, $d);
            $this->fail('Expected stock rejection');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('available', $e->getMessage());
        }
        $this->assertSame($before, Document::count());
        $this->assertSame($recipes, BillOfMaterial::count());
        $this->assertSame(1, StockMovement::count());
    }

    public function test_wholesale_cannot_create_physical_stock(): void
    {
        $this->seed(SalesSetupSeeder::class);
        $user = User::factory()->create();
        $role = AccessRole::where('code', 'wholesale')->firstOrFail();
        $user->accessRoles()->attach($role->id, ['assigned_by' => $this->admin->id]);
        $this->actingAs($user)->get(route('physical-stock.create'))->assertForbidden();
        $this->post(route('physical-stock.store'), $this->data())->assertForbidden();
        $this->assertSame(0, StockMovement::count());
    }

    public function test_sidebar_dropdown_shows_physical_stock_actions_and_current_page(): void
    {
        $response = $this->get(route('physical-stock.fabric'))->assertOk();
        $response->assertSee('physical-stock-sidebar-links')
            ->assertSee('open: true', false)
            ->assertSee(route('physical-stock.index'))
            ->assertSee(route('physical-stock.create'))
            ->assertSee(route('inventory.documents.create', ['kind' => 'transfer']))
            ->assertSee(route('inventory.documents.create', ['kind' => 'adjustment']))
            ->assertSee(route('inventory.movements'))
            ->assertSee(route('finished-goods.inventory'))
            ->assertSee(route('sales.overview'));
        $this->assertMatchesRegularExpression('/href="'.preg_quote(route('physical-stock.fabric'), '/').'"\s+aria-current="page"/', $response->getContent());
    }

    public function test_sidebar_respects_view_only_and_no_inventory_permissions(): void
    {
        $viewer = User::factory()->create();
        $role = AccessRole::create(['code' => 'stock-viewer', 'name' => 'Stock viewer', 'is_active' => true]);
        $role->permissions()->attach(Permission::where('code', 'inventory.view')->value('id'));
        $viewer->accessRoles()->attach($role->id, ['assigned_by' => $this->admin->id]);

        $this->actingAs($viewer)->get(route('physical-stock.index'))->assertOk()
            ->assertSee('physical-stock-sidebar-links')
            ->assertSee(route('physical-stock.fabric'))
            ->assertDontSee(route('physical-stock.create'))
            ->assertDontSee(route('inventory.documents.create', ['kind' => 'transfer']))
            ->assertDontSee(route('finished-goods.inventory'))
            ->assertDontSee(route('sales.overview'));
        $this->get(route('physical-stock.create'))->assertForbidden();

        $this->actingAs(User::factory()->create())->get(route('dashboard'))->assertOk()
            ->assertDontSee('physical-stock-sidebar-links');
    }

    public function test_http_confirmation_then_same_batch_under_another_key_is_rejected(): void
    {
        $d = $this->data();
        $this->post(route('physical-stock.store'), $d)->assertRedirect(route('physical-stock.index'));
        $this->post(route('physical-stock.store'), $d)->assertRedirect(route('physical-stock.index'));
        $d['request_key'] = (string) Str::uuid();
        $this->post(route('physical-stock.store'), $d)->assertSessionHasErrors();
        $this->assertSame(1, StockMovement::count());
    }

    public function test_non_whole_suit_count_and_held_material_cannot_create_output(): void
    {
        $d = $this->data();
        $d['quantity'] = '2.5';
        $this->post(route('physical-stock.store'), $d)->assertSessionHasErrors('quantity');
        $position = $this->position($this->opening('200', '10', true, null, ['quality_status' => 'held']));
        $d = $this->data();
        $d['source'] = 'made';
        $d['materials'] = [['stock_position_id' => $position, 'quantity' => '120']];
        $this->post(route('physical-stock.store'), $d)->assertSessionHasErrors();
        $this->assertFalse(StockLot::where('lot_number', 'READY-001')->exists());
        $this->assertSame(1, StockMovement::count());
    }
}
