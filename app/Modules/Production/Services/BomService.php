<?php

namespace App\Modules\Production\Services;

use App\Models\BillOfMaterial;
use App\Models\BillOfMaterialLine;
use App\Models\Item;
use App\Models\ProductVariant;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Inventory\Services\InventoryGuard as Guard;
use App\Modules\Inventory\Support\Decimal as D;
use Illuminate\Support\Facades\DB;

class BomService
{
    public static function ceilQuantity(string $value, int $places): string
    {
        $rounded = bcadd($value, '0', $places);
        if (bccomp($value, $rounded, 16) > 0) {
            $rounded = bcadd($rounded, $places ? '0.'.str_repeat('0', $places - 1).'1' : '1', $places);
        }
        if (bccomp($rounded, '100000000000000', 6) >= 0) {
            Guard::fail('Quantity exceeds supported precision.');
        }

        return bcadd($rounded, '0', 6);
    }

    public function create(int $actor, array $input): BillOfMaterial
    {
        return DB::transaction(function () use ($actor, $input) {
            Guard::company();
            Guard::actor($actor, 'production.manage');
            $d = validator($input, ['product_variant_id' => 'required|integer|exists:product_variants,id', 'output_quantity' => 'required|numeric|gt:0', 'lines' => 'required|array|min:1|max:100', 'lines.*.component_item_id' => 'required|integer|exists:items,id', 'lines.*.component_name' => 'required|string|max:100', 'lines.*.net_quantity' => 'required|numeric|gt:0', 'lines.*.planned_wastage_fraction' => 'required|numeric|min:0|lt:1'])->validate();
            $variant = ProductVariant::with('item.baseUnit', 'design', 'color')->findOrFail($d['product_variant_id']);
            if (! $variant->is_active || ! $variant->design->is_active || ! $variant->color->is_active || ! $variant->item->is_active || ! $variant->item->is_stocked || $variant->item->baseUnit->code !== 'SUIT') {
                Guard::fail('Choose an active finished suit variant, design and color.');
            }
            $yield = D::input($d['output_quantity']);
            if (bccomp($yield, bcadd($yield, '0', 0), 6)) {
                Guard::fail('BOM yield must use whole suits.');
            }
            $bom = BillOfMaterial::create(['state' => 'draft', 'is_active' => true, 'product_variant_id' => $variant->id, 'version_number' => 1 + (int) BillOfMaterial::where('product_variant_id', $variant->id)->max('version_number'), 'output_quantity' => $yield]);
            foreach (array_values($d['lines']) as $i => $line) {
                $item = Item::with('baseUnit')->findOrFail($line['component_item_id']);
                if (! $item->is_active || ! $item->is_stocked || ! $item->baseUnit->is_active || $item->id === $variant->item_id) {
                    Guard::fail('Choose active stocked components; a product cannot consume itself.');
                }
                $qty = D::input($line['net_quantity']);
                $loss = D::input($line['planned_wastage_fraction'], 10);
                if (bccomp($qty, bcadd($qty, '0', $item->baseUnit->decimal_places), 6)) {
                    Guard::fail('BOM component quantity exceeds its unit precision.');
                }
                BillOfMaterialLine::create(['bill_of_material_id' => $bom->id, 'line_no' => $i + 1, 'component_item_id' => $item->id, 'component_name' => $line['component_name'], 'net_quantity' => $qty, 'planned_wastage_fraction' => $loss]);
            }
            // All active/published versions participate: no recursive product/component cycle.
            $this->cycle($variant->item_id, $bom->lines->pluck('component_item_id')->all(), []);
            $bom->update(['state' => 'published']);
            app(AuditLogger::class)->record('bills_of_materials', $bom->id, 'published', null, $bom->load('lines')->toArray(), $actor);

            return $bom;
        }, 3);
    }

    private function cycle(int $target, array $items, array $path): void
    {
        foreach ($items as $item) {
            if ($item === $target || in_array($item, $path, true)) {
                Guard::fail('Recursive BOM components are not permitted.');
            }
            $children = BillOfMaterialLine::whereHas('bom', fn ($q) => $q->where('state', 'published')->whereHas('variant', fn ($q) => $q->where('item_id', $item)))->pluck('component_item_id')->all();
            if ($children) {
                $this->cycle($target, $children, [...$path, $item]);
            }
        }
    }
}
