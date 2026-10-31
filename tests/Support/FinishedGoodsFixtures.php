<?php

namespace Tests\Support;

use App\Models\Document;
use App\Models\Item;
use App\Models\ProductionOutputLine;
use App\Models\ProductPackSpec;
use App\Models\Unit;
use App\Modules\FinishedGoods\Services\PackingService;
use App\Modules\FinishedGoods\Services\PackSpecificationService;
use Illuminate\Support\Str;

trait FinishedGoodsFixtures
{
    use ProductionFixtures;

    protected Item $packItem;

    protected Item $bag;

    protected ProductPackSpec $packSpec;

    protected int $suitPosition;

    protected int $bagPosition;

    protected Document $finishedBatch;

    protected PackingService $packing;

    protected function finishedGoodsFixtures(): void
    {
        $this->productionFixtures();
        $this->finishedBatch = $this->batch();
        $p = $this->position($this->opening('600', '10'));
        $report = $this->production->confirm($this->admin->id, $this->reportData($this->finishedBatch, $p, '600', '100', true));
        $this->suitPosition = ProductionOutputLine::where('document_id', $report->id)->firstOrFail()->stock_position_id;
        $this->bag = Item::factory()->create(['sku' => 'BAG-TEST', 'item_type' => 'packaging', 'base_unit_id' => Unit::where('code', 'PCS')->value('id'), 'is_stocked' => true, 'track_rolls' => false]);
        $original = $this->item;
        $this->item = $this->bag;
        $this->bagPosition = $this->position($this->opening('100', '2'));
        $this->item = $original;
        $this->packItem = Item::factory()->create(['sku' => 'PACK-TEST', 'item_type' => 'finished', 'base_unit_id' => Unit::where('code', 'PACK')->value('id'), 'is_stocked' => true, 'track_rolls' => false]);
        $this->packSpec = app(PackSpecificationService::class)->publish($this->admin->id, ['packed_item_id' => $this->packItem->id, 'description' => 'One finished suit with actual bag', 'lines' => [['component_item_id' => $this->variant->item_id, 'component_kind' => 'saleable_content', 'quantity_per_pack' => '1'], ['component_item_id' => $this->bag->id, 'component_kind' => 'packaging', 'quantity_per_pack' => '1']]]);
        $this->packing = app(PackingService::class);
    }

    protected function packingData(string $qty = '10'): array
    {
        return ['document_date' => $this->date, 'idempotency_key' => (string) Str::uuid(), 'product_pack_spec_id' => $this->packSpec->id, 'operation' => 'pack', 'remarks' => 'Inspected actual packing at warehouse.', 'materials' => [['product_pack_spec_line_id' => $this->packSpec->lines->first()->id, 'stock_position_id' => $this->suitPosition, 'quantity' => $qty], ['product_pack_spec_line_id' => $this->packSpec->lines->last()->id, 'stock_position_id' => $this->bagPosition, 'quantity' => $qty]], 'outputs' => [['item_id' => $this->packItem->id, 'warehouse_location_id' => $this->destination->id, 'lot_number' => 'PACK-'.Str::random(12), 'quantity' => $qty, 'quality_status' => 'released', 'packages' => [['package_code' => 'BOX-'.Str::random(16), 'quantity' => $qty]]]]];
    }
}
