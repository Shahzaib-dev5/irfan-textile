<?php

namespace Tests\Feature;

use App\Models\ManufacturingBatch;
use App\Models\ProcessingReceipt;
use App\Models\ProductVariant;
use App\Models\VendorServiceRate;
use App\Modules\FinishedGoods\Services\ActualCosting;
use App\Modules\Production\Services\BomService;
use App\Modules\Production\Services\ProductionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\ProcessingFixtures;
use Tests\TestCase;

class FinishedGoodsCostIntegrationTest extends TestCase
{
    use ProcessingFixtures,RefreshDatabase;

    public function test_processing_service_normal_loss_and_rate_changes_leave_historical_cost_exact(): void
    {
        $this->processingFixtures();
        $source = $this->position($this->opening('100', '10'));
        $job = $this->job();
        $dispatch = $this->dispatch($job, $source);
        $receipt = $this->processingReceipt($dispatch, '100', '90', true, ['normal_loss_quantity' => '10']);
        $line = ProcessingReceipt::find($receipt->id)->lines->first();
        $variant = ProductVariant::factory()->create();
        $bom = app(BomService::class)->create($this->admin->id, ['product_variant_id' => $variant->id, 'output_quantity' => '1', 'lines' => [['component_item_id' => $this->item->id, 'component_name' => 'Actual processed fabric', 'net_quantity' => '9', 'planned_wastage_fraction' => '0']]]);
        $production = app(ProductionService::class);
        $batch = $production->confirm($this->admin->id, ['kind' => 'batch', 'document_date' => $this->date, 'idempotency_key' => (string) Str::uuid(), 'bill_of_material_id' => $bom->id, 'planned_output_quantity' => '10']);
        $production->confirm($this->admin->id, ['kind' => 'report', 'document_date' => $this->date, 'idempotency_key' => (string) Str::uuid(), 'manufacturing_batch_document_id' => $batch->id, 'is_final' => true, 'materials' => [['manufacturing_batch_material_id' => ManufacturingBatch::find($batch->id)->lines->first()->id, 'stock_position_id' => $line->output_stock_position_id, 'quantity' => '90']], 'outputs' => [['item_id' => $variant->item_id, 'warehouse_location_id' => $this->destination->id, 'lot_number' => 'ACTUAL-COST-SUITS', 'output_kind' => 'finished', 'quality_status' => 'released', 'quantity' => '10', 'cost_allocation_fraction' => '1']]]);
        $cost = app(ActualCosting::class)->report($batch);
        $this->assertSame('1180.0000', $cost['total']);
        $this->assertSame('118.0000', $cost['rows'][0]['per_suit']);
        $this->assertCount(2, $cost['all']);
        $service = array_values(array_filter($cost['all'], fn ($p) => str_starts_with($p['label'], 'Processing')))[0];
        $this->assertSame('180.0000', $service['amount']);
        VendorServiceRate::where('vendor_service_id', $this->capability->id)->update(['unit_rate' => '999']);
        $this->assertSame($cost['all'], app(ActualCosting::class)->report($batch)['all']);
    }
}
