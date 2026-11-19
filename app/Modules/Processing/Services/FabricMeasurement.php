<?php

namespace App\Modules\Processing\Services;

use App\Models\Item;
use App\Models\Unit;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Inventory\Services\InventoryGuard as Guard;
use Illuminate\Support\Facades\DB;

class FabricMeasurement
{
    public function pieces(Item $source, int $actor): Item
    {
        if (! $source->isFabric() || $source->baseUnit->code !== 'M') {
            Guard::fail('Only meter-based fabric can be converted to pieces.');
        }
        $unit = Unit::where('code', 'PCS')->where('is_active', true)->firstOrFail();
        $existing = DB::table('fabric_measurement_items')->where('source_item_id', $source->id)->where('unit_id', $unit->id)->first();
        if ($existing) {
            $item = Item::findOrFail($existing->output_item_id);
            if (! $item->is_active || ! $item->is_stocked || $item->base_unit_id !== $unit->id || $item->track_rolls) {
                Guard::fail('The converted piece identity is inactive or incompatible.');
            }

            return $item;
        }
        $item = $source->replicate();
        $item->sku = 'CUT-PCS-'.$source->id;
        $item->name = mb_substr($source->name.' / cut pieces', 0, 200);
        $item->base_unit_id = $unit->id;
        $item->textile_definition_id = null;
        $item->track_rolls = false;
        $item->item_type = 'processed_fabric';
        $item->save();
        if ($source->fabricSpecification) {
            $spec = $source->fabricSpecification->replicate();
            $spec->item_id = $item->id;
            $spec->save();
        }
        DB::table('fabric_measurement_items')->insert(['source_item_id' => $source->id, 'output_item_id' => $item->id, 'unit_id' => $unit->id]);
        app(AuditLogger::class)->record('items', $item->id, 'cut_piece_identity_created', null, ['source_item_id' => $source->id, 'unit_id' => $unit->id], $actor);

        return $item->fresh();
    }

    public function isPieceOutput(Item $source, Item $output): bool
    {
        return $source->baseUnit->code === 'M' && $output->baseUnit->code === 'PCS'
            && DB::table('fabric_measurement_items')->where('source_item_id', $source->id)->where('output_item_id', $output->id)->exists();
    }
}
