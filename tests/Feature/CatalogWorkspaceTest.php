<?php

namespace Tests\Feature;

use App\Models\AccessRole;
use App\Models\Item;
use App\Models\Permission;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Modules\Catalog\Services\CatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\PurchasingFixtures;
use Tests\TestCase;

class CatalogWorkspaceTest extends TestCase
{
    use PurchasingFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->purchasingFixtures();
    }

    private function data(string $type = 'grey_fabric'): array
    {
        return ['sku' => 'UI-'.Str::upper(Str::random(8)), 'name' => 'Cotton definition', 'item_type' => $type, 'category_id' => $this->item->category_id, 'base_unit_id' => Unit::where('code', match ($type) {
            'finished' => 'SUIT','service' => 'SVC',default => 'M'
        })->value('id'), 'is_stocked' => $type !== 'service', 'track_rolls' => false, 'is_active' => true] + (in_array($type, ['grey_fabric', 'processed_fabric']) ? ['fabric' => ['composition' => '100% cotton', 'weave' => null, 'width_mm' => null, 'gsm' => null]] : []);
    }

    private function userWith(array $permissions): User
    {
        $role = AccessRole::create(['code' => 'catalog-ui-'.Str::random(8), 'name' => 'Catalog operator']);
        $role->permissions()->attach(Permission::whereIn('code', $permissions)->pluck('id'));
        $user = User::factory()->create();
        $user->accessRoles()->attach($role->id, ['assigned_by' => $this->admin->id]);

        return $user;
    }

    private function fixture(string $name, $response): void
    {
        if (! getenv('CATALOG_BROWSER_FIXTURES')) {
            return;
        }
        $path = storage_path('framework/testing/catalog-browser');
        if (! is_dir($path)) {
            mkdir($path, 0777, true);
        }
        file_put_contents($path.'/'.$name.'.html', $response->getContent());
    }

    public function test_overview_and_sidebar_explain_categories_items_and_actual_stock_sources(): void
    {
        $this->fixture('overview', $this->get('/catalog')->assertOk()->assertSee('Category or textile item?')->assertSee('catalog-sidebar-links')->assertSee('Saving an item creates no stock.'));
        foreach (['items', 'categories', 'units', 'designs', 'colors', 'variants'] as $master) {
            $this->get(route('catalog.index', ['master' => $master]))->assertOk();
        }
        $this->get(route('catalog.collections'))->assertOk();
    }

    public function test_create_presets_are_guidance_and_do_not_write_items_or_stock(): void
    {
        $before = Item::count();
        foreach (['grey_fabric' => 'M', 'processed_fabric' => 'M', 'finished' => 'SUIT', 'service' => 'SVC'] as $type => $code) {
            $this->get(route('catalog.create', ['master' => 'items', 'item_type' => $type]))->assertOk()->assertViewHas('itemConfig', fn ($c) => $c['type'] === $type && $c['unit'] === Unit::where('code', $code)->value('id') && $c['stocked'] === ($type !== 'service') && ! $c['rolls']);
        }
        $this->get(route('catalog.create', ['master' => 'items']))->assertViewHas('itemConfig', fn ($c) => $c['type'] === '');
        $this->assertSame($before, Item::count());
        $this->assertSame(0, StockMovement::count());
    }

    public function test_guided_form_saves_composition_without_inventory_quantity_and_has_separate_conversions(): void
    {
        $this->fixture('item', $this->get(route('catalog.create', ['master' => 'items']))->assertOk()->assertSee('Track individual fabric rolls')->assertDontSee('name="stock_quantity"', false));
        $data = $this->data();
        $this->post(route('catalog.store', ['master' => 'items']), $data)->assertSessionHasNoErrors();
        $item = Item::where('sku', $data['sku'])->firstOrFail();
        $this->assertSame('100% cotton', $item->fabricSpecification->composition);
        $this->assertSame(0, StockMovement::count());
        $this->fixture('edit', $this->get(route('catalog.edit', ['master' => 'items', 'record' => $item->id]))->assertOk()->assertSee('measurement settings are protected')->assertSee(route('catalog.conversions.index', $item)));
        $this->fixture('view', $this->get(route('catalog.show', ['master' => 'items', 'record' => $item->id]))->assertOk()->assertDontSee('Save item'));
        $this->fixture('conversions', $this->get(route('catalog.conversions.index', $item))->assertOk()->assertSee('1 CM = 0.01 M'));
    }

    public function test_missing_category_or_unit_has_no_fabricated_fallback(): void
    {
        Unit::where('code', 'M')->update(['is_active' => false]);
        $this->get(route('catalog.create', ['master' => 'items', 'item_type' => 'grey_fabric']))->assertOk()->assertViewHas('itemConfig', fn ($c) => $c['unit'] === '');
        $this->get(route('catalog.create', ['master' => 'items', 'category_id' => 999999]))->assertSessionHasErrors('category_id');
    }

    public function test_edit_load_preserves_measurements_and_protects_stock_referenced_identity(): void
    {
        $data = $this->data();
        $item = app(CatalogService::class)->save('items', $data);
        $this->item = $item;
        $this->order('100', '10', false);
        $this->get(route('catalog.edit', ['master' => 'items', 'record' => $item->id, 'item_type' => 'service']))->assertOk()->assertViewHas('itemConfig', fn ($c) => $c['type'] === 'grey_fabric' && $c['stockReferenced'] && $c['identityLocked'] && ! $c['rolls']);
        $data['sku'] = 'ILLEGAL-CHANGE';
        $this->put(route('catalog.update', ['master' => 'items', 'record' => $item->id]), $data)->assertSessionHasErrors('sku');
        $this->assertSame($item->sku, $item->fresh()->sku);
    }

    public function test_validation_preserves_user_input_and_roll_choice(): void
    {
        $data = $this->data();
        $data['fabric']['composition'] = '';
        $data['track_rolls'] = true;
        $url = route('catalog.create', ['master' => 'items', 'item_type' => 'grey_fabric']);
        $this->from($url)->post(route('catalog.store', ['master' => 'items']), $data)->assertSessionHasErrors('fabric.composition')->assertRedirect($url);
        $this->get($url)->assertOk()->assertViewHas('itemConfig', fn ($c) => $c['sku'] === $data['sku'] && $c['rolls'] && $c['fabric']['composition'] === null);
        $this->assertDatabaseMissing('items', ['sku' => $data['sku']]);
    }

    public function test_alternate_units_follow_existing_dimension_suit_and_duplicate_rules(): void
    {
        $item = app(CatalogService::class)->save('items', $this->data());
        $cm = Unit::where('code', 'CM')->first();
        $this->post(route('catalog.conversions.store', $item), ['unit_id' => $cm->id, 'factor_to_base' => '0.01'])->assertSessionHasNoErrors()->assertRedirect(route('catalog.conversions.index', $item));
        $this->get(route('catalog.conversions.index', $item))->assertOk()->assertViewHas('units', fn ($units) => ! $units->contains('code', 'CM') && ! $units->contains('code', 'KG') && ! $units->contains('code', 'SUIT') && ! $units->contains('code', 'ROLL'))->assertSee('0.0100000000');
        $finished = app(CatalogService::class)->save('items', $this->data('finished'));
        $this->get(route('catalog.conversions.index', $finished))->assertViewHas('units', fn ($units) => $units->pluck('code')->all() === ['SET']);
        $this->assertSame(0, StockMovement::count());
    }

    public function test_viewer_manager_and_unauthorized_conversion_access_remain_distinct(): void
    {
        $item = app(CatalogService::class)->save('items', $this->data());
        $this->actingAs($this->userWith(['catalog.masters.view']))->get('/catalog')->assertOk()->assertDontSee('Create this item');
        $this->get(route('catalog.conversions.index', $item))->assertOk()->assertViewHas('readOnly', true)->assertDontSee('Add conversion');
        $this->get(route('catalog.create', ['master' => 'items']))->assertForbidden();
        $this->post(route('catalog.conversions.store', $item), [])->assertForbidden();
        $this->actingAs($this->userWith(['catalog.masters.manage']))->get(route('catalog.conversions.index', $item))->assertOk()->assertViewHas('readOnly', false);
        $this->actingAs(User::factory()->create())->get('/catalog')->assertForbidden();
        $this->get(route('catalog.conversions.index', $item))->assertForbidden();
    }

    public function test_existing_unit_identity_fields_are_displayed_as_protected(): void
    {
        $unit = Unit::where('code', 'M')->first();
        $this->fixture('unit', $this->get(route('catalog.edit', ['master' => 'units', 'record' => $unit->id]))->assertOk()->assertSee('name="code" value="M"', false));
        $this->fixture('category', $this->get(route('catalog.create', ['master' => 'categories']))->assertOk()->assertSee('category is not a stock item'));
    }
}
