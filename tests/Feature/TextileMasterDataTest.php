<?php

namespace Tests\Feature;

use App\Models\AccessRole;
use App\Models\Color;
use App\Models\Company;
use App\Models\Design;
use App\Models\FabricSpecification;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\ItemUnitConversion;
use App\Models\Permission;
use App\Models\ProductVariant;
use App\Models\Unit;
use App\Models\User;
use App\Modules\Catalog\Services\CatalogService;
use Database\Seeders\CatalogConfigurationSeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TextileMasterDataTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Company::factory()->create();
        $this->admin = User::factory()->create();
        $this->admin->accessRoles()->attach(AccessRole::query()->where('code', 'super-admin')->value('id'), ['assigned_by' => $this->admin->id]);
        $this->actingAs($this->admin);
    }

    public function test_fabric_create_edit_and_read_screens_persist_specs_without_stock_quantities(): void
    {
        $data = $this->itemData('grey_fabric');
        $this->post(route('catalog.store', ['master' => 'items']), $data)->assertSessionHasNoErrors()->assertRedirect();
        $item = Item::query()->where('sku', $data['sku'])->firstOrFail();
        $this->assertSame('100% cotton', $item->fabricSpecification->composition);
        $this->assertSame('M', $item->baseUnit->code);
        $this->get(route('catalog.show', ['master' => 'items', 'record' => $item->id]))->assertOk()->assertSee('100% cotton');
        $this->get(route('catalog.edit', ['master' => 'items', 'record' => $item->id]))->assertOk();
        $data['name'] = 'Updated kora fabric';
        $this->put(route('catalog.update', ['master' => 'items', 'record' => $item->id]), $data)->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('Updated kora fabric', $item->fresh()->name);
        $this->assertFalse(Schema::hasColumn('items', 'quantity'));
        $this->assertFalse(Schema::hasColumn('items', 'stock_quantity'));
        $this->assertFalse(Schema::hasColumn('items', 'on_hand_quantity'));
        $this->assertDatabaseHas('audit_events', ['entity_table' => 'fabric_specifications', 'entity_key' => (string) $item->id, 'actor_id' => $this->admin->id]);
    }

    public function test_materials_consumables_finished_and_nonstock_services_have_explicit_classifications(): void
    {
        foreach (['trim', 'packaging', 'finished', 'service', 'processed_fabric', 'scrap'] as $type) {
            $this->post(route('catalog.store', ['master' => 'items']), $this->itemData($type))->assertSessionHasNoErrors()->assertRedirect();
        }
        $this->get(route('catalog.index', ['master' => 'items', 'item_type' => 'finished']))->assertOk()->assertSee('Finished product')->assertDontSee('SKU-SERVICE');
        $bad = $this->itemData('grey_fabric');
        $bad['base_unit_id'] = Unit::where('code', 'SUIT')->value('id');
        $this->post(route('catalog.store', ['master' => 'items']), $bad)->assertSessionHasErrors('base_unit_id');
        $bad = $this->itemData('service');
        $bad['sku'] = 'SKU-INVALID-SERVICE';
        $bad['is_stocked'] = true;
        $this->post(route('catalog.store', ['master' => 'items']), $bad)->assertSessionHasErrors('is_stocked');
    }

    public function test_quantity_inputs_duplicate_skus_and_invalid_fabric_specs_are_rejected(): void
    {
        $data = $this->itemData('grey_fabric');
        $data['stock_quantity'] = '500';
        $this->post(route('catalog.store', ['master' => 'items']), $data)->assertSessionHasErrors('stock_quantity');
        unset($data['stock_quantity']);
        $data['fabric']['composition'] = '';
        $this->post(route('catalog.store', ['master' => 'items']), $data)->assertSessionHasErrors('fabric.composition');
        $data['fabric']['composition'] = 'Cotton';
        $data['fabric']['gsm'] = '-1';
        $this->post(route('catalog.store', ['master' => 'items']), $data)->assertSessionHasErrors('fabric.gsm');
        $data['fabric']['gsm'] = '125';
        $this->post(route('catalog.store', ['master' => 'items']), $data)->assertSessionHasNoErrors();
        $this->post(route('catalog.store', ['master' => 'items']), $data)->assertSessionHasErrors('sku');
    }

    public function test_exact_item_conversions_reject_incompatible_units_and_fractional_suits(): void
    {
        $item = app(CatalogService::class)->save('items', $this->itemData('grey_fabric'));
        $cm = Unit::where('code', 'CM')->firstOrFail();
        $this->post(route('catalog.conversions.store', $item), ['unit_id' => $cm->id, 'factor_to_base' => '0.0100000000'])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('1.250000', app(CatalogService::class)->convertToBase($item, $cm->id, '125'));
        $conversion = $item->conversions()->firstOrFail();
        $this->put(route('catalog.conversions.update', ['item' => $item->id, 'conversion' => $conversion->id]), ['unit_id' => $cm->id, 'factor_to_base' => '0.01'])->assertSessionHasNoErrors()->assertRedirect();
        foreach (['SUIT', 'KG', 'ROLL'] as $code) {
            $this->post(route('catalog.conversions.store', $item), ['unit_id' => Unit::where('code', $code)->value('id'), 'factor_to_base' => '1'])->assertSessionHasErrors('unit_id');
        }
        $finished = app(CatalogService::class)->save('items', $this->itemData('finished'));
        $this->post(route('catalog.conversions.store', $finished), ['unit_id' => Unit::where('code', 'PCS')->value('id'), 'factor_to_base' => '3'])->assertSessionHasErrors('unit_id');
        $this->post(route('catalog.conversions.store', $finished), ['unit_id' => Unit::where('code', 'SET')->value('id'), 'factor_to_base' => '0.5'])->assertSessionHasErrors('factor_to_base');
        $this->post(route('catalog.conversions.store', $finished), ['unit_id' => Unit::where('code', 'SET')->value('id'), 'factor_to_base' => '1'])->assertSessionHasNoErrors();
    }

    public function test_conversion_refuses_silent_loss_of_base_unit_precision(): void
    {
        $item = app(CatalogService::class)->save('items', $this->itemData('grey_fabric'));
        $cm = Unit::where('code', 'CM')->firstOrFail();
        app(CatalogService::class)->saveConversion($item, ['unit_id' => $cm->id, 'factor_to_base' => '0.01']);
        $this->expectException(ValidationException::class);
        app(CatalogService::class)->convertToBase($item, $cm->id, '0.001');
    }

    public function test_unit_definitions_and_referenced_item_bases_are_protected(): void
    {
        $meter = Unit::where('code', 'M')->firstOrFail();
        $this->put(route('catalog.update', ['master' => 'units', 'record' => $meter->id]), ['code' => 'M', 'name' => 'Meter', 'dimension' => 'mass', 'decimal_places' => 3, 'is_active' => true])->assertSessionHasErrors('code');
        $this->post(route('catalog.store', ['master' => 'units']), ['code' => 'BAD_COUNT', 'name' => 'Bad count', 'dimension' => 'count', 'decimal_places' => 2, 'is_active' => true])->assertSessionHasErrors('decimal_places');
        $data = $this->itemData('grey_fabric');
        $item = app(CatalogService::class)->save('items', $data);
        $data['track_rolls'] = false;
        $this->put(route('catalog.update', ['master' => 'items', 'record' => $item->id]), $data)->assertSessionHasErrors('track_rolls');
        $this->assertTrue($item->fresh()->track_rolls);
    }

    public function test_category_tree_cycles_and_archiving_with_active_children_are_rejected(): void
    {
        $a = ItemCategory::factory()->create();
        $b = ItemCategory::factory()->create(['parent_id' => $a->id]);
        $data = $a->only(['code', 'name', 'is_active']);
        $data['parent_id'] = $b->id;
        $this->put(route('catalog.update', ['master' => 'categories', 'record' => $a->id]), $data)->assertSessionHasErrors('parent_id');
        $data['parent_id'] = null;
        $data['is_active'] = false;
        $this->put(route('catalog.update', ['master' => 'categories', 'record' => $a->id]), $data)->assertSessionHasErrors('is_active');
    }

    public function test_finished_variants_and_collections_use_the_approved_design_color_configuration_key(): void
    {
        $item = app(CatalogService::class)->save('items', $this->itemData('finished'));
        $this->post(route('catalog.store', ['master' => 'designs']), ['code' => 'DSN-001', 'name' => 'Lawn bloom', 'season' => 'Summer 2027', 'description' => 'Floral', 'is_active' => true])->assertSessionHasNoErrors();
        $this->post(route('catalog.store', ['master' => 'colors']), ['code' => 'JADE', 'name' => 'Jade', 'hex_code' => '#176B58', 'is_active' => true])->assertSessionHasNoErrors();
        $data = ['item_id' => $item->id, 'design_id' => Design::where('code', 'DSN-001')->value('id'), 'color_id' => Color::where('code', 'JADE')->value('id'), 'configuration_code' => 'THREE_PIECE', 'piece_count' => 3, 'is_active' => true];
        $this->post(route('catalog.store', ['master' => 'variants']), $data)->assertSessionHasNoErrors();
        $variant = ProductVariant::query()->firstOrFail();
        $this->get(route('catalog.show', ['master' => 'variants', 'record' => $variant->id]))->assertOk()->assertSee('THREE_PIECE');
        $this->get(route('catalog.collections'))->assertOk()->assertSee('Summer 2027');
        $this->get(route('catalog.index', ['master' => 'designs', 'season' => 'Summer 2027']))->assertOk()->assertSee('Lawn bloom');
        $data['item_id'] = Item::factory()->finished()->create()->id;
        $this->post(route('catalog.store', ['master' => 'variants']), $data)->assertSessionHasErrors('configuration_code');
        $data['item_id'] = Item::factory()->create()->id;
        $data['configuration_code'] = 'TWO_PIECE';
        $this->post(route('catalog.store', ['master' => 'variants']), $data)->assertSessionHasErrors('item_id');
    }

    public function test_read_only_users_can_browse_but_cannot_manage_any_master_or_conversion(): void
    {
        $role = AccessRole::factory()->create();
        $role->permissions()->attach(Permission::where('code', 'catalog.masters.view')->value('id'));
        $viewer = User::factory()->create();
        $viewer->accessRoles()->attach($role->id, ['assigned_by' => $this->admin->id]);
        $item = Item::factory()->create();
        $this->actingAs($viewer);
        foreach (['units', 'categories', 'items', 'designs', 'colors', 'variants'] as $master) {
            $this->get(route('catalog.index', ['master' => $master]))->assertOk()->assertDontSee('Add record');
            $this->get(route('catalog.create', ['master' => $master]))->assertForbidden();
            $this->post(route('catalog.store', ['master' => $master]), [])->assertForbidden();
        }
        $this->get(route('catalog.show', ['master' => 'items', 'record' => $item->id]))->assertOk()->assertDontSee('Save record');
        $this->get(route('catalog.edit', ['master' => 'items', 'record' => $item->id]))->assertForbidden();
        $this->post(route('catalog.conversions.store', $item), [])->assertForbidden();
        $this->get('/catalog/not-a-master')->assertNotFound();
    }

    public function test_configuration_seeders_are_idempotent_and_factories_produce_relational_records(): void
    {
        $counts = [Unit::count(), ItemCategory::count()];
        $this->seed(CatalogConfigurationSeeder::class);
        $this->assertSame($counts, [Unit::count(), ItemCategory::count()]);
        $this->assertSame(0, Item::count());
        $spec = FabricSpecification::factory()->create();
        $variant = ProductVariant::factory()->create();
        $conversion = ItemUnitConversion::factory()->create();
        $this->assertSame('M', $spec->item->baseUnit->code);
        $this->assertSame('SUIT', $variant->item->baseUnit->code);
        $this->assertNotNull($conversion->unit);
    }

    public function test_database_rejects_invalid_piece_count(): void
    {
        $variant = ProductVariant::factory()->create();
        $this->expectException(QueryException::class);
        DB::table('product_variants')->where('id', $variant->id)->update(['piece_count' => 0]);
    }

    public function test_every_reusable_master_form_renders_for_new_existing_and_read_only_records(): void
    {
        $records = ['units' => Unit::query()->firstOrFail(), 'categories' => ItemCategory::factory()->create(), 'items' => Item::factory()->create(), 'designs' => Design::factory()->create(), 'colors' => Color::factory()->create(), 'variants' => ProductVariant::factory()->create()];
        foreach ($records as $master => $record) {
            $this->get(route('catalog.create', ['master' => $master]))->assertOk();
            $this->get(route('catalog.edit', ['master' => $master, 'record' => $record->id]))->assertOk();
            $this->get(route('catalog.show', ['master' => $master, 'record' => $record->id]))->assertOk();
        }
    }

    public function test_new_units_categories_archiving_and_pagination_work_through_authorized_routes(): void
    {
        $this->post(route('catalog.store', ['master' => 'units']), ['code' => 'M2', 'name' => 'Square meter', 'dimension' => 'area', 'decimal_places' => 3, 'is_active' => true])->assertSessionHasNoErrors()->assertRedirect();
        $this->post(route('catalog.store', ['master' => 'categories']), ['code' => 'LACES', 'name' => 'Laces', 'parent_id' => ItemCategory::where('code', 'materials')->value('id'), 'is_active' => true])->assertSessionHasNoErrors()->assertRedirect();
        $category = ItemCategory::where('code', 'LACES')->firstOrFail();
        $this->put(route('catalog.update', ['master' => 'categories', 'record' => $category->id]), ['code' => $category->code, 'name' => 'Archived laces', 'parent_id' => $category->parent_id, 'is_active' => false])->assertSessionHasNoErrors()->assertRedirect();
        $this->get(route('catalog.index', ['master' => 'categories', 'status' => 'inactive', 'q' => 'Archived']))->assertOk()->assertSee('Archived laces');
        $this->assertDatabaseHas('item_categories', ['id' => $category->id, 'is_active' => false]);
        $this->assertFalse($this->admin->can('delete', $category));
        Item::factory()->count(21)->create();
        $this->get(route('catalog.index', ['master' => 'items', 'page' => 2]))->assertOk()->assertViewHas('records', fn ($records) => $records->count() === 1 && $records->total() === 21);
    }

    public function test_database_restricts_invalid_item_category_references(): void
    {
        $item = Item::factory()->create();
        $this->expectException(QueryException::class);
        DB::table('items')->where('id', $item->id)->update(['category_id' => 99999999]);
    }

    public function test_database_rejects_nonpositive_conversion_factors(): void
    {
        $conversion = ItemUnitConversion::factory()->create();
        $this->expectException(QueryException::class);
        DB::table('item_unit_conversions')->where('id', $conversion->id)->update(['factor_to_base' => '0']);
    }

    private function itemData(string $type): array
    {
        return ['sku' => 'SKU-'.strtoupper($type), 'name' => ucwords(str_replace('_', ' ', $type)), 'category_id' => ItemCategory::where('code', match ($type) {
            'grey_fabric' => 'grey-fabric','processed_fabric' => 'processed-fabric','finished' => 'finished','packaging' => 'packaging','service' => 'services','scrap' => 'scrap',default => 'consumables'
        })->value('id'), 'item_type' => $type, 'base_unit_id' => Unit::where('code', match ($type) {
            'grey_fabric','processed_fabric' => 'M','finished' => 'SUIT','service' => 'SVC',default => 'PCS'
        })->value('id'), 'is_stocked' => $type !== 'service', 'track_rolls' => in_array($type, ['grey_fabric', 'processed_fabric']), 'is_active' => true, 'description' => null, 'fabric' => in_array($type, ['grey_fabric', 'processed_fabric']) ? ['composition' => '100% cotton', 'width_mm' => '1400.000', 'gsm' => '120.000', 'weave' => 'Plain'] : []];
    }
}
