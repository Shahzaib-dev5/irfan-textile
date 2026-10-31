<?php

namespace App\Modules\Catalog;

use App\Models\Color;
use App\Models\Design;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\ProductVariant;
use App\Models\Unit;
use Illuminate\Validation\Rule;

class CatalogRegistry
{
    public const MODELS = ['units' => Unit::class, 'categories' => ItemCategory::class, 'items' => Item::class, 'designs' => Design::class, 'colors' => Color::class, 'variants' => ProductVariant::class];

    public const LABELS = ['units' => 'Units of measure', 'categories' => 'Item categories', 'items' => 'Textile items', 'designs' => 'Designs', 'colors' => 'Colors', 'variants' => 'Finished product variants'];

    public static function model(string $master): string
    {
        abort_unless(isset(self::MODELS[$master]), 404);

        return self::MODELS[$master];
    }

    public static function fields(string $master): array
    {
        $fields = match ($master) {
            'units' => ['code' => ['Code', 'text'], 'name' => ['Name', 'text'], 'dimension' => ['Measurement dimension', 'select'], 'decimal_places' => ['Decimal places (0–6)', 'number']],
            'categories' => ['code' => ['Code', 'text'], 'name' => ['Name', 'text'], 'parent_id' => ['Parent category (optional)', 'select']],
            'items' => ['sku' => ['SKU / item code', 'text'], 'name' => ['Item name', 'text'], 'category_id' => ['Category', 'select'], 'item_type' => ['Classification', 'select'], 'base_unit_id' => ['Base unit', 'select'], 'is_stocked' => ['Stock-managed item', 'checkbox'], 'track_rolls' => ['Track individual fabric rolls', 'checkbox'], 'description' => ['Description', 'textarea']],
            'designs' => ['code' => ['Design code', 'text'], 'name' => ['Design name', 'text'], 'season' => ['Collection / season (optional)', 'text'], 'description' => ['Description', 'textarea']],
            'colors' => ['code' => ['Color code', 'text'], 'name' => ['Color name', 'text'], 'hex_code' => ['Swatch hex (optional, e.g. #176B58)', 'text']],
            'variants' => ['item_id' => ['Finished SKU', 'select'], 'design_id' => ['Design', 'select'], 'color_id' => ['Color', 'select'], 'configuration_code' => ['Configuration code (e.g. THREE_PIECE)', 'text'], 'piece_count' => ['Number of fabric pieces', 'number'], 'description' => ['Configuration description', 'textarea']],
        };

        return [...$fields, 'is_active' => ['Active', 'checkbox']];
    }

    public static function rules(string $master, ?int $id = null): array
    {
        $unique = fn (string $table, string $column) => Rule::unique($table, $column)->ignore($id);
        $rules = match ($master) {
            'units' => ['code' => ['required', 'string', 'max:20', 'regex:/^[A-Z][A-Z0-9_-]*$/', $unique('units', 'code')], 'name' => ['required', 'string', 'max:60'], 'dimension' => ['required', Rule::in(['length', 'mass', 'count', 'area', 'service'])], 'decimal_places' => ['required', 'integer', 'between:0,6']],
            'categories' => ['code' => ['required', 'string', 'max:40', $unique('item_categories', 'code')], 'name' => ['required', 'string', 'max:100'], 'parent_id' => ['nullable', 'integer', 'exists:item_categories,id']],
            'items' => ['sku' => ['required', 'string', 'max:60', $unique('items', 'sku')], 'name' => ['required', 'string', 'max:200'], 'category_id' => ['required', 'integer', 'exists:item_categories,id'], 'item_type' => ['required', Rule::in(array_keys(Item::TYPES))], 'base_unit_id' => ['required', 'integer', 'exists:units,id'], 'is_stocked' => ['required', 'boolean'], 'track_rolls' => ['required', 'boolean'], 'description' => ['nullable', 'string', 'max:10000'], 'fabric' => ['sometimes', 'array:composition,weave,width_mm,gsm'], 'fabric.composition' => ['nullable', 'string', 'max:200'], 'fabric.weave' => ['nullable', 'string', 'max:100'], 'fabric.width_mm' => ['nullable', 'numeric', 'gt:0', 'decimal:0,3', 'max:999999999.999'], 'fabric.gsm' => ['nullable', 'numeric', 'gt:0', 'decimal:0,3', 'max:999999999.999']],
            'designs' => ['code' => ['required', 'string', 'max:50', $unique('designs', 'code')], 'name' => ['required', 'string', 'max:150'], 'season' => ['nullable', 'string', 'max:60'], 'description' => ['nullable', 'string', 'max:10000']],
            'colors' => ['code' => ['required', 'string', 'max:40', $unique('colors', 'code')], 'name' => ['required', 'string', 'max:100'], 'hex_code' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/']],
            'variants' => ['item_id' => ['required', 'integer', 'exists:items,id', $unique('product_variants', 'item_id')], 'design_id' => ['required', 'integer', 'exists:designs,id'], 'color_id' => ['required', 'integer', 'exists:colors,id'], 'configuration_code' => ['required', 'string', 'max:40', 'regex:/^[A-Z0-9_-]+$/'], 'piece_count' => ['required', 'integer', 'between:1,255'], 'description' => ['nullable', 'string', 'max:500']],
        };

        return [...$rules, 'is_active' => ['required', 'boolean'], 'quantity' => ['prohibited'], 'stock_quantity' => ['prohibited'], 'opening_stock' => ['prohibited'], 'on_hand' => ['prohibited']];
    }

    public static function conversionRules(): array
    {
        return ['unit_id' => ['required', 'integer', 'exists:units,id'], 'factor_to_base' => ['required', 'regex:/^(?:0|[1-9][0-9]{0,9})(?:\.[0-9]{1,10})?$/', 'numeric', 'gt:0']];
    }
}
