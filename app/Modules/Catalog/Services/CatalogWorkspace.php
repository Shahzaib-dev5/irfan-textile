<?php

namespace App\Modules\Catalog\Services;

use App\Models\Item;
use App\Models\Unit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Read-only presentation guidance. CatalogService owns all master validation. */
class CatalogWorkspace
{
    public const SCREENS = [
        'items' => ['title' => 'Textile items', 'new' => 'New textile item', 'help' => 'Create the actual fabric or material SKU used in purchases and stock documents. Choose its type first. Quantities are recorded through inventory documents.'],
        'categories' => ['title' => 'Item categories', 'new' => 'New category', 'help' => 'Categories group items, such as Cotton fabric or Packing materials. A category is not a stock item and cannot be received or dispatched.'],
        'units' => ['title' => 'Units of measure', 'new' => 'New unit', 'help' => 'Choose how quantities are measured. Fabric uses meters (M); complete finished suits use SUIT. Standard units are already available.'],
        'designs' => ['title' => 'Designs', 'new' => 'New design', 'help' => 'Name a design and optionally give it a collection or season label. Link it to a finished SKU through Finished product variants.'],
        'colors' => ['title' => 'Colors', 'new' => 'New color', 'help' => 'Maintain reusable color names and optional display swatches. A color record creates no stock or dyeing job.'],
        'variants' => ['title' => 'Finished product variants', 'new' => 'New finished variant', 'help' => 'Link one finished SUIT item to a design, color and component count. This defines the product; it does not manufacture suits.'],
    ];

    public const TYPES = [
        'grey_fabric' => ['title' => 'Grey / kora fabric', 'help' => 'Unprocessed fabric bought from a supplier. Stock is measured in meters.', 'example' => 'Grey cotton / M'],
        'processed_fabric' => ['title' => 'Processed fabric', 'help' => 'Fabric after a processing stage, such as dyed cotton. Stock stays in meters.', 'example' => 'Dyed cotton / M'],
        'trim' => ['title' => 'Material / consumable', 'help' => 'Thread, lace or other physical supplies. Choose their actual measurement unit.', 'example' => 'Thread / KG or PCS'],
        'packaging' => ['title' => 'Packaging material', 'help' => 'Bags, boxes and other packing supplies. Choose the unit you count or measure.', 'example' => 'Suit bag / PCS'],
        'finished' => ['title' => 'Finished product', 'help' => 'Use SUIT for an individual finished variant, or PACK for a separate packed assortment. Define PACK contents under Finished goods.', 'example' => 'Individual suit / SUIT or assortment / PACK'],
        'scrap' => ['title' => 'Scrap', 'help' => 'Reusable or saleable waste defined as its own item. Choose its physical unit.', 'example' => 'Cotton offcuts / KG'],
        'service' => ['title' => 'Nonstock service', 'help' => 'A service definition with no physical stock or fabric rolls.', 'example' => 'Service / SVC'],
    ];

    public const HELP = [
        'code' => 'Use a unique, recognizable code. Existing unit codes cannot be changed.',
        'name' => 'Use the name staff will recognize in dropdowns and documents.',
        'parent_id' => 'Optional: place this group inside a broader category. Leave blank for a top-level category.',
        'dimension' => 'Length for meters; Mass for kilograms; Count for pieces/suits; Service for nonstock services.',
        'decimal_places' => 'How many decimal places are allowed for quantities. Count units use 0.',
        'season' => 'Example: Summer 2027. This label groups designs under Collections; no separate collection record is needed.',
        'hex_code' => 'Optional display swatch, such as #176B58. Leave blank if you only need the color name.',
        'item_id' => 'Choose a finished stock item measured in whole suits. Create it under Textile items first.',
        'configuration_code' => 'Example: THREE_PIECE. The design/color/configuration combination must be unique.',
        'piece_count' => 'Components in one complete suit: for example, shirt + trouser + dupatta = 3. This is not stock quantity.',
        'description' => 'Optional notes to help staff recognize this definition.',
        'is_active' => 'Active records can be selected for new work. Existing history is retained when a record is deactivated.',
    ];

    public function itemConfig(Item $item, bool $readOnly): array
    {
        $stockReferenced = $item->exists && (DB::table('stock_lots')->where('item_id', $item->id)->exists() || DB::table('purchase_order_lines')->where('item_id', $item->id)->exists() || (Schema::hasTable('sales_order_lines') && DB::table('sales_order_lines')->where('item_id', $item->id)->exists()));
        $bomReferenced = (CatalogService::hasPublishedBomReference($item) || CatalogService::hasPublishedPackReference($item));
        $skuLocked = $stockReferenced || $bomReferenced;
        $identityLocked = $bomReferenced || $stockReferenced || ($item->exists && ($item->fabricSpecification || $item->conversions->isNotEmpty() || $item->variant()->exists()));
        $value = fn ($field, $default = null) => $readOnly || ($identityLocked && in_array($field, ['item_type', 'base_unit_id', 'is_stocked', 'track_rolls'])) || ($skuLocked && $field === 'sku') ? ($item->$field ?? $default) : old($field, $item->$field ?? $default);
        $fabric = [];
        foreach (['composition', 'weave', 'width_mm', 'gsm'] as $field) {
            $fabric[$field] = $readOnly || $stockReferenced ? $item->fabricSpecification?->$field : old('fabric.'.$field, $item->fabricSpecification?->$field);
        }

        return ['type' => $value('item_type', ''), 'unit' => $value('base_unit_id', ''), 'stocked' => (bool) $value('is_stocked', true), 'rolls' => (bool) $value('track_rolls', false), 'sku' => $value('sku', ''), 'name' => $value('name', ''), 'category' => $value('category_id', ''), 'active' => (bool) $value('is_active', true), 'description' => $value('description', ''), 'fabric' => $fabric, 'identityLocked' => (bool) $identityLocked, 'stockReferenced' => (bool) $stockReferenced, 'skuLocked' => (bool) $skuLocked, 'bomReferenced' => (bool) $bomReferenced, 'readOnly' => $readOnly, 'types' => self::TYPES,
            'units' => Unit::orderBy('name')->get(['id', 'code', 'name', 'dimension', 'is_active'])->toArray()];
    }

    public function alternateUnits(Item $item)
    {
        return Unit::where('is_active', true)->where('dimension', $item->baseUnit->dimension)->where('id', '!=', $item->base_unit_id)->where('code', '!=', 'ROLL')
            ->when(in_array($item->baseUnit->code, ['ROLL', 'PACK'], true), fn ($q) => $q->whereRaw('1=0'))
            ->when($item->item_type === 'finished', fn ($q) => $q->whereIn('code', ['SUIT', 'SET']), fn ($q) => $q->where('code', '!=', 'SUIT'))
            ->where('code', '!=', 'PACK')->whereNotIn('id', $item->conversions->pluck('unit_id'))->orderBy('name')->get();
    }
}
