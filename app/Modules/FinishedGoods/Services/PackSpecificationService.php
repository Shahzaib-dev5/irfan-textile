<?php

namespace App\Modules\FinishedGoods\Services;

use App\Models\Item;
use App\Models\ProductPackSpec;
use App\Models\ProductPackSpecLine;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Inventory\Services\InventoryGuard as G;
use App\Modules\Inventory\Support\Decimal as D;
use Illuminate\Support\Facades\DB;

class PackSpecificationService
{
    public static function precision(Item $item, mixed $quantity): string
    {
        $q = D::input($quantity);
        if (bccomp($q, '0', 6) <= 0 || bccomp($q, bcadd($q, '0', $item->baseUnit->decimal_places), 6)) {
            G::fail('Use a positive quantity within the item base-unit precision.');
        }

        return $q;
    }

    public function publish(int $actor, array $input): ProductPackSpec
    {
        return DB::transaction(function () use ($actor, $input) {
            G::company();
            G::actor($actor, 'finished-goods.manage');
            $d = validator($input, ['packed_item_id' => 'required|integer|exists:items,id', 'description' => 'required|string|max:200', 'lines' => 'required|array|min:1|max:100', 'lines.*.component_item_id' => 'required|integer|exists:items,id|distinct', 'lines.*.component_kind' => 'required|in:saleable_content,packaging', 'lines.*.quantity_per_pack' => 'required|numeric|gt:0'])->validate();
            $item = Item::with('baseUnit')->findOrFail($d['packed_item_id']);
            if (! $item->is_active || ! $item->is_stocked || $item->item_type !== 'finished' || $item->baseUnit->code !== 'PACK' || $item->baseUnit->decimal_places !== 0 || ! $item->baseUnit->is_active) {
                G::fail('Choose an active finished PACK item. Individual suits retain their SUIT SKU.');
            }
            $contents = [];
            foreach ($d['lines'] as &$l) {
                $component = Item::with('baseUnit', 'variant.design', 'variant.color')->findOrFail($l['component_item_id']);
                if (! $component->is_active || ! $component->is_stocked || ! $component->baseUnit->is_active || $component->id === $item->id) {
                    G::fail('Packing needs active physical components and cannot consume itself.');
                }
                if ($l['component_kind'] === 'saleable_content') {
                    if ($component->item_type !== 'finished' || $component->baseUnit->code !== 'SUIT' || ! $component->variant?->is_active || ! $component->variant->design->is_active || ! $component->variant->color->is_active) {
                        G::fail('Saleable content must be a configured active SUIT variant. Nested packs are not supported.');
                    }
                    $contents[$component->id] = self::precision($component, $l['quantity_per_pack']);
                } elseif ($component->item_type !== 'packaging') {
                    G::fail('Packing supplies must use the Packaging material classification.');
                }
                $l['quantity_per_pack'] = self::precision($component, $l['quantity_per_pack']);
            }unset($l);
            if (! $contents) {
                G::fail('A specification must contain at least one saleable suit.');
            }ksort($contents);
            foreach (ProductPackSpec::with('lines')->where('packed_item_id', $item->id)->where('state', 'published')->get() as $old) {
                $prior = $old->lines->where('component_kind', 'saleable_content')->pluck('quantity_per_pack', 'component_item_id')->all();
                ksort($prior);
                if ($prior !== $contents) {
                    G::fail('A different suit assortment needs a new packed SKU. Existing SKU contents cannot be redefined.');
                }
            }
            $spec = ProductPackSpec::create(['packed_item_id' => $item->id, 'version_number' => 1 + (int) ProductPackSpec::where('packed_item_id', $item->id)->max('version_number'), 'description' => $d['description'], 'state' => 'draft', 'is_active' => true]);
            foreach (array_values($d['lines']) as $i => $l) {
                ProductPackSpecLine::create(['component_item_id' => $l['component_item_id'], 'component_kind' => $l['component_kind'], 'quantity_per_pack' => $l['quantity_per_pack'], 'product_pack_spec_id' => $spec->id, 'line_no' => $i + 1]);
            }
            $spec->update(['state' => 'published']);
            app(AuditLogger::class)->record('product_pack_specs', $spec->id, 'published', null, $spec->load('lines')->toArray(), $actor);

            return $spec;
        }, 3);
    }
}
