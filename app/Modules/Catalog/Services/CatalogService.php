<?php

namespace App\Modules\Catalog\Services;

use App\Models\BillOfMaterial;
use App\Models\BillOfMaterialLine;
use App\Models\Color;
use App\Models\Company;
use App\Models\Design;
use App\Models\FabricSpecification;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\ItemUnitConversion;
use App\Models\ProcessingService;
use App\Models\ProductPackSpec;
use App\Models\ProductPackSpecLine;
use App\Models\ProductVariant;
use App\Models\Unit;
use App\Modules\Catalog\CatalogRegistry;
use App\Modules\Core\Services\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class CatalogService
{
    public function save(string $master, array $input, ?int $id = null): Model
    {
        $modelClass = CatalogRegistry::model($master);

        return DB::transaction(function () use ($master, $input, $id, $modelClass): Model {
            $this->lockCatalog();
            $record = $id ? $modelClass::query()->lockForUpdate()->findOrFail($id) : new $modelClass;
            $data = validator($input, CatalogRegistry::rules($master, $id))->validate();
            $data = Arr::only($data, [...array_keys(CatalogRegistry::fields($master)), 'fabric']);
            $before = $record->exists ? $record->toArray() : null;
            $this->validateDomain($master, $record, $data);
            $fabric = Arr::pull($data, 'fabric', []);
            $record->fill($data)->save();
            app(AuditLogger::class)->record($record->getTable(), $record->getKey(), $before ? 'updated' : 'created', $before, $record->fresh()->toArray());
            if ($record instanceof Item && $record->isFabric()) {
                $spec = FabricSpecification::query()->firstOrNew(['item_id' => $record->id]);
                $specBefore = $spec->exists ? $spec->toArray() : null;
                $spec->fill($fabric)->save();
                app(AuditLogger::class)->record('fabric_specifications', $record->id, $specBefore ? 'updated' : 'created', $specBefore, $spec->fresh()->toArray());
            }

            return $record->fresh();
        }, 3);
    }

    public static function hasPublishedBomReference(Item $item): bool
    {
        return $item->exists && Schema::hasTable('bills_of_materials') && (
            BillOfMaterialLine::where('component_item_id', $item->id)->whereHas('bom', fn ($q) => $q->where('state', 'published'))->exists()
            || BillOfMaterial::where('state', 'published')->whereHas('variant', fn ($q) => $q->where('item_id', $item->id))->exists()
        );
    }

    public static function hasPublishedPackReference(Item $item): bool
    {
        return $item->exists && Schema::hasTable('product_pack_specs') && (
            ProductPackSpec::where('packed_item_id', $item->id)->where('state', 'published')->exists()
            || ProductPackSpecLine::where('component_item_id', $item->id)->whereHas('spec', fn ($q) => $q->where('state', 'published'))->exists()
        );
    }

    private function lockCatalog(): void
    {
        if (! Company::query()->whereKey(1)->lockForUpdate()->first()) {
            $this->fail('company', 'Complete the company profile before maintaining textile master data.');
        }
    }

    private function validateDomain(string $master, Model $record, array $data): void
    {
        if ($master === 'units') {
            if ($record->exists && ($record->code !== $data['code'] || $record->dimension !== $data['dimension'] || $record->decimal_places !== (int) $data['decimal_places'])) {
                $this->fail('code', 'A unit measurement definition is immutable. Create a new unit code for a different basis.');
            }
            if ($data['dimension'] === 'count' && (int) $data['decimal_places'] !== 0) {
                $this->fail('decimal_places', 'Count units require zero decimal places.');
            }
            if ($record->exists && ! $data['is_active'] && ($record->items()->where('is_active', true)->exists() || ProcessingService::query()->where('billing_unit_id', $record->id)->where('is_active', true)->exists())) {
                $this->fail('is_active', 'Deactivate dependent items and processing services before this unit.');
            }
        } elseif ($master === 'categories') {
            $cursor = $data['parent_id'] ?? null;
            $visited = [];
            while ($cursor) {
                if ((int) $cursor === (int) $record->id || isset($visited[$cursor])) {
                    $this->fail('parent_id', 'A category cannot be its own parent or a descendant of itself.');
                }
                $visited[$cursor] = true;
                $parent = ItemCategory::query()->findOrFail($cursor);
                if ($data['is_active'] && ! $parent->is_active) {
                    $this->fail('parent_id', 'Choose an active parent category.');
                }
                $cursor = $parent->parent_id;
            }
            if ($record->exists && ! $data['is_active'] && ($record->children()->where('is_active', true)->exists() || $record->items()->where('is_active', true)->exists())) {
                $this->fail('is_active', 'Deactivate dependent categories and items first.');
            }
        } elseif ($master === 'items') {
            $unit = Unit::query()->findOrFail($data['base_unit_id']);
            $category = ItemCategory::query()->findOrFail($data['category_id']);
            if ($data['is_active'] && (! $unit->is_active || ! $category->is_active)) {
                $this->fail('base_unit_id', 'Active items require an active unit and category.');
            }
            $isFabric = in_array($data['item_type'], ['grey_fabric', 'processed_fabric'], true);
            if ($isFabric && ($unit->code !== 'M' || $unit->dimension !== 'length' || ! $data['is_stocked'])) {
                $this->fail('base_unit_id', 'Fabric is a stock-managed item with base unit M (meters).');
            }
            if ($isFabric && blank($data['fabric']['composition'] ?? null)) {
                $this->fail('fabric.composition', 'Fabric composition is required.');
            }
            if (! $isFabric && collect($data['fabric'] ?? [])->filter(fn ($v) => $v !== null && $v !== '')->isNotEmpty()) {
                $this->fail('fabric', 'Fabric specifications apply only to grey or processed fabric.');
            }
            if ($data['item_type'] === 'finished' && (! in_array($unit->code, ['SUIT', 'PACK'], true) || $unit->dimension !== 'count' || $unit->decimal_places !== 0 || ! $data['is_stocked'])) {
                $this->fail('base_unit_id', 'Finished products use stock-managed whole SUIT or separate PACK items.');
            }
            if ($data['item_type'] !== 'finished' && in_array($unit->code, ['SUIT', 'PACK'], true)) {
                $this->fail('base_unit_id', 'SUIT describes a complete finished product, not a material or fabric component.');
            }
            if ($data['item_type'] === 'service' && ($data['is_stocked'] || $data['track_rolls'] || $unit->dimension !== 'service')) {
                $this->fail('is_stocked', 'Service items are nonstock and require a service-dimension unit.');
            }
            if ($data['item_type'] !== 'service' && $unit->dimension === 'service') {
                $this->fail('base_unit_id', 'Physical materials require a physical measurement unit.');
            }
            if ($data['track_rolls'] && (! $isFabric || ! $data['is_stocked'])) {
                $this->fail('track_rolls', 'Roll tracking is only available for stock-managed fabric.');
            }
            if ($record->exists) {
                if (DB::table('stock_lots')->where('item_id', $record->id)->exists() || DB::table('purchase_order_lines')->where('item_id', $record->id)->exists() || (Schema::hasTable('sales_order_lines') && DB::table('sales_order_lines')->where('item_id', $record->id)->exists())) {
                    if (! $data['is_active'] && DB::table('stock_movements as m')->join('stock_positions as p', 'p.id', '=', 'm.stock_position_id')->join('stock_lots as l', 'l.id', '=', 'p.stock_lot_id')->where('l.item_id', $record->id)->groupBy('p.id')->havingRaw('SUM(m.quantity_delta)>0')->exists()) {
                        $this->fail('is_active', 'Move or dispose remaining stock before deactivating its item.');
                    }
                    foreach (['sku', 'item_type', 'base_unit_id', 'track_rolls', 'is_stocked'] as $identity) {
                        if ($data[$identity] != $record->$identity) {
                            $this->fail($identity, 'A stock-referenced identity is immutable. Create a new SKU.');
                        }
                    }
                    if (($data['fabric'] ?? []) != ($record->fabricSpecification?->only(array_keys($data['fabric'] ?? [])) ?? [])) {
                        $this->fail('fabric', 'Stock-referenced fabric specifications are immutable.');
                    }
                }
                if (self::hasPublishedBomReference($record) || self::hasPublishedPackReference($record)) {
                    foreach (['sku', 'item_type', 'base_unit_id', 'is_stocked', 'track_rolls'] as $identity) {
                        if ($data[$identity] != $record->$identity) {
                            $this->fail($identity, 'Published BOMs or packing specifications protect this stock identity. Create another SKU.');
                        }
                    }
                }
                foreach (['item_type', 'base_unit_id', 'track_rolls', 'is_stocked'] as $field) {
                    if ($data[$field] != $record->$field && ($record->conversions()->exists() || $record->variant()->exists() || $record->fabricSpecification()->exists())) {
                        $this->fail($field, 'This item measurement/classification is referenced. Create a new SKU for a different stock identity.');
                    }
                }
                if (! $data['is_active'] && $record->variant()->where('is_active', true)->exists()) {
                    $this->fail('is_active', 'Deactivate the finished variant first.');
                }
            }
        } elseif ($master === 'variants') {
            if ($record->exists && Schema::hasTable('bills_of_materials') && (BillOfMaterial::where('product_variant_id', $record->id)->where('state', 'published')->exists() || self::hasPublishedPackReference($record->item) || (Schema::hasTable('sales_order_lines') && DB::table('sales_order_lines')->where('item_id', $record->item_id)->exists()))) {
                foreach (['item_id', 'design_id', 'color_id', 'configuration_code', 'piece_count'] as $identity) {
                    if (($data[$identity] ?? null) != $record->$identity) {
                        $this->fail($identity, 'Published BOMs reference this variant. Create a new finished SKU/configuration.');
                    }
                }
            }

            if ($record->exists && DB::table('stock_lots')->where('item_id', $record->item_id)->exists()) {
                foreach (['item_id', 'design_id', 'color_id', 'configuration_code', 'piece_count'] as $field) {
                    if (isset($data[$field]) && $data[$field] != $record->$field) {
                        $this->fail($field, 'A stock-referenced product identity is immutable.');
                    }
                }
            }
            $item = Item::query()->with('baseUnit')->findOrFail($data['item_id']);
            if ($item->item_type !== 'finished' || ! $item->is_stocked || $item->baseUnit->code !== 'SUIT' || $item->baseUnit->decimal_places !== 0) {
                $this->fail('item_id', 'Choose a finished stock item measured in whole suits.');
            }
            if ($data['is_active'] && (! $item->is_active || ! Design::query()->findOrFail($data['design_id'])->is_active || ! Color::query()->findOrFail($data['color_id'])->is_active)) {
                $this->fail('item_id', 'Active variants require active items, designs and colors.');
            }
            if (ProductVariant::query()->where('design_id', $data['design_id'])->where('color_id', $data['color_id'])->where('configuration_code', $data['configuration_code'])->when($record->exists, fn ($q) => $q->where('id', '!=', $record->id))->exists()) {
                $this->fail('configuration_code', 'This design, color and configuration already has a finished SKU.');
            }
        } elseif (in_array($master, ['designs', 'colors'], true) && $record->exists && ! $data['is_active'] && $record->variants()->where('is_active', true)->exists()) {
            $this->fail('is_active', 'Deactivate dependent finished variants first.');
        }
    }

    public function saveConversion(Item $item, array $input, ?int $id = null): ItemUnitConversion
    {
        return DB::transaction(function () use ($item, $input, $id): ItemUnitConversion {
            $this->lockCatalog();
            $item = Item::query()->with('baseUnit')->lockForUpdate()->findOrFail($item->id);
            $data = validator($input, CatalogRegistry::conversionRules())->validate();
            $unit = Unit::query()->findOrFail($data['unit_id']);
            $conversion = $id ? $item->conversions()->findOrFail($id) : new ItemUnitConversion(['item_id' => $item->id]);
            if (! $item->is_active || ! $unit->is_active || ! $item->baseUnit->is_active) {
                $this->fail('unit_id', 'Conversions require an active item and active units.');
            }
            if ($unit->id === $item->base_unit_id || $unit->dimension !== $item->baseUnit->dimension || in_array('ROLL', [$unit->code, $item->baseUnit->code], true)) {
                $this->fail('unit_id', 'Choose a distinct compatible measurement unit; roll lengths cannot use fixed conversions.');
            }
            if ($item->baseUnit->code === 'PACK' || $unit->code === 'PACK') {
                $this->fail('unit_id', 'PACK contents use a packing specification, not a unit conversion.');
            }
            if ($item->item_type === 'finished' && ! in_array($unit->code, ['SUIT', 'SET'], true)) {
                $this->fail('unit_id', 'Complete suits may convert only to whole-suit sets, never component pieces.');
            }
            if ($item->item_type !== 'finished' && $unit->code === 'SUIT') {
                $this->fail('unit_id', 'Materials cannot convert to complete finished suits.');
            }
            if ($item->baseUnit->dimension === 'count' && (int) $item->baseUnit->decimal_places === 0 && bccomp($data['factor_to_base'], bcadd($data['factor_to_base'], '0', 0), 10) !== 0) {
                $this->fail('factor_to_base', 'Count conversions must produce whole base units.');
            }
            if ($item->conversions()->where('unit_id', $unit->id)->when($id, fn ($q) => $q->where('id', '!=', $id))->exists()) {
                $this->fail('unit_id', 'This alternate unit already has a conversion for this item.');
            }
            if ($conversion->exists && (int) $conversion->unit_id !== (int) $unit->id) {
                $this->fail('unit_id', 'The conversion unit is immutable; add a new conversion.');
            }
            $before = $conversion->exists ? $conversion->toArray() : null;
            $conversion->fill($data)->save();
            app(AuditLogger::class)->record('item_unit_conversions', $conversion->id, $before ? 'updated' : 'created', $before, $conversion->fresh()->toArray());

            return $conversion->fresh();
        }, 3);
    }

    public function convertToBase(Item $item, int $unitId, string $quantity): string
    {
        validator(['quantity' => $quantity], ['quantity' => ['required', 'regex:/^(?:0|[1-9][0-9]{0,13})(?:\.[0-9]{1,6})?$/', 'numeric', 'gt:0']])->validate();
        $unit = Unit::query()->findOrFail($unitId);
        $fraction = strlen(rtrim(explode('.', $quantity)[1] ?? '', '0'));
        if ($fraction > $unit->decimal_places) {
            $this->fail('quantity', 'The entered quantity exceeds this unit precision.');
        }
        $factor = $unitId === $item->base_unit_id ? '1' : $item->conversions()->where('unit_id', $unitId)->firstOrFail()->factor_to_base;
        $exact = bcmul($quantity, $factor, 16);
        $result = bcmul($quantity, $factor, 6);
        if (bccomp($exact, $result, 16) !== 0 || bccomp($result, bcadd($result, '0', $item->baseUnit->decimal_places), 6) !== 0 || bccomp($result, '99999999999999.999999', 6) > 0) {
            $this->fail('quantity', 'Conversion would exceed the base quantity precision or range.');
        }

        return $result;
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
