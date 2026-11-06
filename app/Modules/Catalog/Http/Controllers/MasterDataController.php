<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Color;
use App\Models\Design;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Unit;
use App\Modules\Catalog\CatalogRegistry;
use App\Modules\Catalog\Http\Requests\SaveConversionRequest;
use App\Modules\Catalog\Http\Requests\SaveMasterRequest;
use App\Modules\Catalog\Services\CatalogService;
use App\Modules\Catalog\Services\CatalogWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class MasterDataController extends Controller
{
    public function overview(): View
    {
        $this->authorize('viewAny', Item::class);

        return view('module.catalog::masters.overview');
    }

    public function conversions(Request $request, Item $item, CatalogWorkspace $workspace): View
    {
        $readOnly = ! $request->user()->can('update', $item);
        $this->authorize($readOnly ? 'view' : 'update', $item);
        $item->load('baseUnit', 'conversions.unit');

        return view('module.catalog::masters.conversions', ['master' => 'items', 'record' => $item, 'readOnly' => $readOnly, 'units' => $workspace->alternateUnits($item)]);
    }

    public function index(Request $request, string $master): View
    {
        $model = CatalogRegistry::model($master);
        $this->authorize('viewAny', $model);
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::in(['active', 'inactive'])], 'item_type' => ['nullable', Rule::in(array_keys(Item::TYPES))], 'category_id' => ['nullable', 'integer', 'exists:item_categories,id'], 'season' => ['nullable', 'string', 'max:60']]);
        $query = $model::query()->with($this->relations($master));
        if (filled($filters['q'] ?? null)) {
            $term = '%'.$filters['q'].'%';
            $query->where(function ($q) use ($master, $term): void {
                if ($master === 'variants') {
                    $q->where('configuration_code', 'like', $term)->orWhereHas('item', fn ($item) => $item->where('sku', 'like', $term)->orWhere('name', 'like', $term));
                } else {
                    $q->where($master === 'items' ? 'sku' : 'code', 'like', $term)->orWhere('name', 'like', $term);
                }
            });
        }
        if (filled($filters['status'] ?? null)) {
            $query->where('is_active', $filters['status'] === 'active');
        }
        if ($master === 'items') {
            foreach (['item_type', 'category_id'] as $field) {
                if (filled($filters[$field] ?? null)) {
                    $query->where($field, $filters[$field]);
                }
            }
        }
        if ($master === 'designs' && filled($filters['season'] ?? null)) {
            $query->where('season', $filters['season']);
        }

        return view('module.catalog::masters.index', ['master' => $master, 'label' => CatalogRegistry::LABELS[$master], 'records' => $query->orderBy($master === 'variants' ? 'configuration_code' : 'name')->orderBy('id')->paginate($master === 'items' ? 5 : 20)->withQueryString(), 'categories' => ItemCategory::query()->orderBy('name')->get(), 'collections' => Design::query()->whereNotNull('season')->distinct()->orderBy('season')->pluck('season')]);
    }

    public function create(Request $request, string $master): View
    {
        $model = CatalogRegistry::model($master);
        $this->authorize('create', $model);

        $record = new $model(['is_active' => true, 'is_stocked' => true, 'track_rolls' => false, 'decimal_places' => 0]);
        if ($master === 'items') {
            $preset = $request->validate(['item_type' => ['nullable', Rule::in(array_keys(Item::TYPES))], 'category_id' => ['nullable', 'integer', 'exists:item_categories,id']]);
            $type = $preset['item_type'] ?? null;
            if ($type) {
                $record->item_type = $type;
                $record->is_stocked = $type !== 'service';
                $code = match ($type) {
                    'grey_fabric','processed_fabric' => 'M','finished' => 'SUIT','service' => 'SVC',default => null
                };
                if ($code) {
                    $record->base_unit_id = Unit::where('code', $code)->where('is_active', true)->value('id');
                }
            }
            if (isset($preset['category_id'])) {
                $record->category_id = $preset['category_id'];
            }
        }

        return $this->form($master, $record, false);
    }

    public function show(string $master, int $record): View
    {
        $model = CatalogRegistry::model($master);
        $record = $model::query()->findOrFail($record);
        $this->authorize('view', $record);

        return $this->form($master, $record, true);
    }

    public function edit(string $master, int $record): View
    {
        $model = CatalogRegistry::model($master);
        $record = $model::query()->findOrFail($record);
        $this->authorize('update', $record);

        return $this->form($master, $record, false);
    }

    public function store(SaveMasterRequest $request, string $master, CatalogService $service): RedirectResponse
    {
        $record = $service->save($master, $request->validated());

        return to_route('catalog.edit', ['master' => $master, 'record' => $record->id])->with('status', 'Master record created.');
    }

    public function update(SaveMasterRequest $request, string $master, int $record, CatalogService $service): RedirectResponse
    {
        $service->save($master, $request->validated(), $record);

        return back()->with('status', 'Master record updated.');
    }

    public function saveConversion(SaveConversionRequest $request, Item $item, CatalogService $service, ?int $conversion = null): RedirectResponse
    {
        $service->saveConversion($item, $request->validated(), $conversion);

        return to_route('catalog.conversions.index', $item)->with('status', 'Alternate unit saved. It affects future document choices; existing transaction snapshots are preserved.');
    }

    public function collections(Request $request): View
    {
        $this->authorize('viewAny', Design::class);
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:60']]);
        $rows = Design::query()->whereNotNull('season')->where('season', '!=', '')->when(filled($filters['q'] ?? null), fn ($q) => $q->where('season', 'like', '%'.$filters['q'].'%'))->select('season')->selectRaw('COUNT(*) AS design_count')->groupBy('season')->orderBy('season')->paginate(20)->withQueryString();

        return view('module.catalog::masters.collections', ['collections' => $rows]);
    }

    private function relations(string $master): array
    {
        return match ($master) {
            'items' => ['category', 'baseUnit', 'fabricSpecification', 'conversions.unit'], 'categories' => ['parent'], 'variants' => ['item', 'design', 'color'], default => []
        };
    }

    private function form(string $master, $record, bool $readOnly): View
    {
        $record->load($this->relations($master));
        $options = fn ($rows, $label = 'name') => $rows->mapWithKeys(fn ($row) => [$row->id => ($row->code ?? $row->sku).' · '.$row->$label.($row->is_active ? '' : ' (inactive)')])->all();

        return view('module.catalog::masters.form', [
            'master' => $master, 'label' => CatalogRegistry::LABELS[$master], 'record' => $record, 'readOnly' => $readOnly, 'fields' => CatalogRegistry::fields($master),
            'choices' => ['dimension' => ['length' => 'Length', 'mass' => 'Mass', 'count' => 'Count', 'area' => 'Area', 'service' => 'Service'], 'item_type' => Item::TYPES, 'base_unit_id' => $options(Unit::query()->orderBy('name')->get()), 'parent_id' => $options(ItemCategory::query()->where('id', '!=', $master === 'categories' ? ($record->id ?? 0) : 0)->orderBy('name')->get()), 'category_id' => $options(ItemCategory::query()->orderBy('name')->get()), 'item_id' => $options(Item::query()->where('item_type', 'finished')->orderBy('name')->get()), 'design_id' => $options(Design::query()->orderBy('name')->get()), 'color_id' => $options(Color::query()->orderBy('name')->get())],
            'units' => Unit::query()->where('is_active', true)->orderBy('name')->get(),
            'collections' => Design::query()->whereNotNull('season')->distinct()->orderBy('season')->pluck('season'),
            'itemConfig' => $master === 'items' ? app(CatalogWorkspace::class)->itemConfig($record, $readOnly) : null,
        ]);
    }
}
