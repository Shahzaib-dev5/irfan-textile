<?php

namespace Tests\Support;

use App\Models\BillOfMaterial;
use App\Models\Document;
use App\Models\ManufacturingBatch;
use App\Models\ProductVariant;
use App\Modules\Production\Services\BomService;
use App\Modules\Production\Services\ProductionService;
use Illuminate\Support\Str;

trait ProductionFixtures
{
    use InventoryFixtures;

    protected ProductVariant $variant;

    protected BillOfMaterial $bom;

    protected ProductionService $production;

    protected function productionFixtures(): void
    {
        $this->inventoryFixtures();
        $this->variant = ProductVariant::factory()->create();
        $this->bom = app(BomService::class)->create($this->admin->id, ['product_variant_id' => $this->variant->id, 'output_quantity' => '1', 'lines' => [['component_item_id' => $this->item->id, 'component_name' => 'Suit fabric', 'net_quantity' => '6', 'planned_wastage_fraction' => '0.1']]]);
        $this->production = app(ProductionService::class);
    }

    protected function batch(string $qty = '100'): Document
    {
        return $this->production->confirm($this->admin->id, ['kind' => 'batch', 'document_date' => $this->date, 'idempotency_key' => (string) Str::uuid(), 'bill_of_material_id' => $this->bom->id, 'planned_output_quantity' => $qty]);
    }

    protected function reportData(Document $batch, int $position, string $input = '300', string $output = '50', bool $final = false): array
    {
        return ['kind' => 'report', 'document_date' => $this->date, 'idempotency_key' => (string) Str::uuid(), 'manufacturing_batch_document_id' => $batch->id, 'is_final' => $final, 'materials' => [['manufacturing_batch_material_id' => ManufacturingBatch::findOrFail($batch->id)->lines->first()->id, 'stock_position_id' => $position, 'quantity' => $input]], 'outputs' => [['item_id' => $this->variant->item_id, 'warehouse_location_id' => $this->destination->id, 'lot_number' => 'PROD-'.Str::random(12), 'quality_status' => 'released', 'output_kind' => 'finished', 'quantity' => $output, 'cost_allocation_fraction' => '1']]];
    }
}
