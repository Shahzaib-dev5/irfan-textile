<?php

namespace App\Modules\Inventory\Services;

use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\TextileDefinition;
use App\Models\Unit;
use App\Modules\Catalog\Services\CatalogService;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Inventory\Support\Decimal;

class PurchaseMeasurement
{
    public const TYPES = ['grey_fabric', 'processed_fabric', 'trim', 'packaging', 'scrap'];

    public function resolve(int $actor, array $line): Item
    {
        if (! empty($line['item_id']) && ! empty($line['new_item']['sku'])) {
            InventoryGuard::fail('Select an existing item or create a new one, not both.', 'lines');
        }
        $source = ! empty($line['item_id']) ? Item::with('baseUnit', 'fabricSpecification', 'textileDefinition')->lockForUpdate()->findOrFail($line['item_id']) : null;
        $unit = Unit::findOrFail($line['unit_id'] ?? $source?->base_unit_id ?? Unit::where('code', 'PCS')->value('id'));
        if (! $unit->is_active || $unit->dimension === 'service' || in_array($unit->code, ['SUIT', 'PACK', 'ROLL'])) {
            InventoryGuard::fail('Choose an active material measurement unit.', 'lines');
        }
        if ($source) {
            if (! $source->is_active || ! $source->is_stocked || ! in_array($source->item_type, self::TYPES)) {
                InventoryGuard::fail('Choose active stocked fabric or material.', 'lines');
            }
            if ($source->base_unit_id === $unit->id) {
                return $source;
            }
            if ($source->track_rolls) {
                InventoryGuard::fail('Roll-tracked identities cannot change measurement through direct purchase. Create a separate material definition.', 'lines');
            }
            $definition = $source->textileDefinition;
            if (! $definition) {
                InventoryGuard::actor($actor, 'catalog.masters.manage');
                $definition = TextileDefinition::create(['code' => $source->sku, 'name' => $source->name, 'category_id' => $source->category_id, 'item_type' => $source->item_type]);
                $source->update(['textile_definition_id' => $definition->id]);
            }
            $existing = $definition->items()->where('base_unit_id', $unit->id)->first();
            if ($existing) {
                if (! $existing->is_active || ! $existing->is_stocked) {
                    InventoryGuard::fail('This measurement identity is inactive.', 'lines');
                }

                return $existing;
            }
            InventoryGuard::actor($actor, 'catalog.masters.manage');
            $sku = substr($definition->code, 0, 35).'-'.$unit->code.'-'.$definition->id;
        } else {
            InventoryGuard::actor($actor, 'catalog.masters.manage');
            $new = $line['new_item'];
            $type = $new['item_type'] ?? 'trim';
            if (! in_array($type, self::TYPES)) {
                InventoryGuard::fail('Choose a fabric or material classification.', 'lines');
            }
            $category = ! empty($new['category_id']) ? ItemCategory::findOrFail($new['category_id']) : ItemCategory::where('code', match ($type) {
                'grey_fabric' => 'grey-fabric', 'processed_fabric' => 'processed-fabric', 'trim' => 'materials', 'packaging' => 'packaging', 'scrap' => 'scrap',
            })->firstOrFail();
            if (! $category->is_active) {
                InventoryGuard::fail('The category must be active.', 'lines');
            }
            if (TextileDefinition::where('code', $new['sku'])->exists() || Item::where('sku', $new['sku'])->exists()) {
                InventoryGuard::fail('This fabric code already exists. Select the existing item.', 'lines');
            }
            $definition = TextileDefinition::create(['code' => $new['sku'], 'name' => $new['name'], 'category_id' => $category->id, 'item_type' => $type, 'fabric_type' => $new['fabric_type'] ?? null]);
            $sku = $definition->code;
        }
        $item = app(CatalogService::class)->save('items', [
            'sku' => $sku, 'name' => $definition->name, 'category_id' => $definition->category_id,
            'item_type' => $definition->item_type, 'base_unit_id' => $unit->id,
            'is_stocked' => true, 'track_rolls' => false, 'is_active' => true,
            'description' => $definition->fabric_type,
            'fabric' => $source?->fabricSpecification?->only(['composition', 'width_mm', 'gsm', 'weave']) ?? [],
        ], null, true);
        $item->update(['textile_definition_id' => $definition->id]);
        app(AuditLogger::class)->record('items', $item->id, 'purchase_measurement_created', null, ['definition' => $definition->toArray(), 'unit_id' => $unit->id], $actor);

        return $item;
    }

    public function amounts(array $line, Item $item): array
    {
        $quantity = Decimal::input($line['quantity']);
        $quotedRate = Decimal::input($line['unit_cost'], 8);
        if (bccomp($quantity, '0', 6) <= 0 || bccomp($quotedRate, '0', 8) <= 0) {
            InventoryGuard::fail('Received quantity and rate must be positive.', 'lines');
        }
        $rateUnit = Unit::findOrFail($line['rate_unit_id'] ?? $item->base_unit_id);
        $rateQuantity = Decimal::input($line['rate_quantity'] ?? $quantity);
        if (! $rateUnit->is_active || $rateUnit->dimension === 'service' || bccomp($rateQuantity, '0', 6) <= 0 || bccomp($rateQuantity, bcadd($rateQuantity, '0', $rateUnit->decimal_places), 6)) {
            InventoryGuard::fail('Rate quantity must respect the selected unit precision.', 'lines');
        }
        $evidence = ['M' => 'measured_meters', 'SET' => 'set_count', 'THAAN' => 'thaan_count'];
        foreach ($evidence as $code => $field) {
            if (! empty($line[$field]) && $item->baseUnit->code === $code && bccomp($quantity, (string) $line[$field], 6)) {
                InventoryGuard::fail('The supplementary '.$code.' quantity must equal the stock quantity.', 'lines');
            }
        }
        if ($rateUnit->id === $item->base_unit_id) {
            if (bccomp($rateQuantity, $quantity, 6)) {
                InventoryGuard::fail('Rate quantity must equal stock quantity when the units match.', 'lines');
            }
        } elseif (! isset($evidence[$rateUnit->code]) || empty($line[$evidence[$rateUnit->code]]) || bccomp($rateQuantity, (string) $line[$evidence[$rateUnit->code]], 6)) {
            InventoryGuard::fail('Record the actual meters, sets or thaan matching the rate quantity.', 'lines');
        }
        $total = Decimal::round(bcmul($rateQuantity, (string) $line['unit_cost'], 18));
        $stockCost = bcdiv($total, $quantity, 8);
        if (bccomp($total, '0', 4) <= 0 || bccomp(Decimal::round(bcmul($quantity, $stockCost, 18)), $total, 4)) {
            InventoryGuard::fail('This rate cannot be represented exactly at stock cost precision. Adjust the quoted quantity/rate.', 'lines');
        }

        return ['unit_cost' => $stockCost, 'snapshot' => ['rate_unit_id' => $rateUnit->id, 'rate_quantity' => $rateQuantity, 'quoted_rate' => $line['unit_cost'], 'thaan_count' => $line['thaan_count'] ?? null, 'set_count' => $line['set_count'] ?? null, 'measured_meters' => $line['measured_meters'] ?? null]];
    }
}
