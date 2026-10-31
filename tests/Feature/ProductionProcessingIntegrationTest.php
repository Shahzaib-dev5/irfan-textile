<?php

namespace Tests\Feature;

use App\Models\ManufacturingBatch;
use App\Models\ProductionOutputLine;
use App\Models\ProductionReport;
use App\Models\ProductVariant;
use App\Modules\Production\Services\BomService;
use App\Modules\Production\Services\ProductionProgress;
use App\Modules\Production\Services\ProductionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\ProcessingFixtures;
use Tests\TestCase;

class ProductionProcessingIntegrationTest extends TestCase
{
    use ProcessingFixtures,RefreshDatabase;

    public function test_batch_cannot_complete_with_vendor_custody_and_closed_batch_rejects_new_processing(): void
    {
        $this->processingFixtures();
        $variant = ProductVariant::factory()->create();
        $production = app(ProductionService::class);
        $bom = app(BomService::class)->create($this->admin->id, ['product_variant_id' => $variant->id, 'output_quantity' => '1', 'lines' => [['component_item_id' => $this->item->id, 'component_name' => 'Fabric', 'net_quantity' => '6', 'planned_wastage_fraction' => '0']]]);
        $batch = $production->confirm($this->admin->id, ['kind' => 'batch', 'document_date' => $this->date, 'idempotency_key' => (string) Str::uuid(), 'bill_of_material_id' => $bom->id, 'planned_output_quantity' => '100']);
        $p = $this->position($this->opening('700'));
        $job = $this->processing->confirm($this->admin->id, ['kind' => 'job', 'document_date' => $this->date, 'idempotency_key' => (string) Str::uuid(), 'manufacturing_batch_document_id' => $batch->id, 'processing_route_version_id' => $this->route->id, 'planned_input_quantity' => '100', 'lines' => [['vendor_service_id' => $this->capability->id]]]);
        $dispatch = $this->dispatch($job, $p, '100');
        $data = ['kind' => 'report', 'document_date' => $this->date, 'idempotency_key' => (string) Str::uuid(), 'manufacturing_batch_document_id' => $batch->id, 'is_final' => true, 'materials' => [['manufacturing_batch_material_id' => ManufacturingBatch::find($batch->id)->lines->first()->id, 'stock_position_id' => $p, 'quantity' => '600']], 'outputs' => [['item_id' => $variant->item_id, 'warehouse_location_id' => $this->source->id, 'lot_number' => 'LINKED-PRODUCTION', 'quality_status' => 'released', 'output_kind' => 'finished', 'quantity' => '100', 'cost_allocation_fraction' => '1']]];
        try {
            $production->confirm($this->admin->id, $data);
            $this->fail('Must retain custody.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('outstanding contractor', $e->getMessage());
        }
        $this->assertSame(0, ProductionReport::count());
        $this->processingReceipt($dispatch);
        $doc = $production->confirm($this->admin->id, $data);
        $this->assertTrue(app(ProductionProgress::class)->final($batch->id));
        $newJob = ['kind' => 'job', 'document_date' => $this->date, 'idempotency_key' => (string) Str::uuid(), 'manufacturing_batch_document_id' => $batch->id, 'processing_route_version_id' => $this->route->id, 'planned_input_quantity' => '10', 'lines' => [['vendor_service_id' => $this->capability->id]]];
        try {
            $this->processing->confirm($this->admin->id, $newJob);
            $this->fail('Closed batch must reject new jobs.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('has not been completed', $e->getMessage());
        }
    }

    public function test_unlinked_processing_job_preserves_pre_phase_nine_payload_hash_shape(): void
    {
        $this->processingFixtures();
        $job = $this->job();
        $h = $this->processing->header($job)->toArray();
        unset($h['manufacturing_batch_document_id']);
        $old = hash('sha256', json_encode(['document' => $job->only(['document_type_id', 'document_date', 'financial_year_id', 'accounting_period_id', 'external_reference']), 'details' => $h], JSON_THROW_ON_ERROR), true);
        $this->assertSame($old, $job->getRawOriginal('payload_hash'));
        $this->assertSame($job->id, $this->processing->post($this->admin->id, $job->id, $job->posting_key, 0)->id);
    }

    public function test_batch_linked_processing_rejects_another_batches_intermediate_stock(): void
    {
        $this->processingFixtures();
        $variant = ProductVariant::factory()->create();
        $production = app(ProductionService::class);
        $bom = app(BomService::class)->create($this->admin->id, ['product_variant_id' => $variant->id, 'output_quantity' => '1', 'lines' => [['component_item_id' => $this->item->id, 'component_name' => 'Fabric', 'net_quantity' => '6', 'planned_wastage_fraction' => '0']]]);
        $makeBatch = fn () => $production->confirm($this->admin->id, ['kind' => 'batch', 'document_date' => $this->date, 'idempotency_key' => (string) Str::uuid(), 'bill_of_material_id' => $bom->id, 'planned_output_quantity' => '1']);
        $a = $makeBatch();
        $b = $makeBatch();
        $source = $this->position($this->opening('6'));
        $report = $production->confirm($this->admin->id, ['kind' => 'report', 'document_date' => $this->date, 'idempotency_key' => (string) Str::uuid(), 'manufacturing_batch_document_id' => $a->id, 'materials' => [['manufacturing_batch_material_id' => ManufacturingBatch::find($a->id)->lines->first()->id, 'stock_position_id' => $source, 'quantity' => '6']], 'outputs' => [['item_id' => $this->item->id, 'warehouse_location_id' => $this->source->id, 'lot_number' => 'BATCH-OWNED-WIP', 'output_kind' => 'intermediate', 'quality_status' => 'released', 'quantity' => '6', 'cost_allocation_fraction' => '1']]]);
        $wip = ProductionOutputLine::where('document_id', $report->id)->first()->stock_position_id;
        $makeJob = fn ($batch) => $this->processing->confirm($this->admin->id, ['kind' => 'job', 'document_date' => $this->date, 'idempotency_key' => (string) Str::uuid(), 'manufacturing_batch_document_id' => $batch->id, 'processing_route_version_id' => $this->route->id, 'planned_input_quantity' => '6', 'lines' => [['vendor_service_id' => $this->capability->id]]]);
        $job = $makeJob($b);
        try {
            $this->dispatch($job, $wip, '6');
            $this->fail('Other-batch WIP must not be reassigned by dispatch.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('another production batch', $e->getMessage());
        }
        $own = $this->dispatch($makeJob($a), $wip, '6');
        $this->assertSame('posted', $own->status);
        $state = app(ProductionProgress::class)->summary(ManufacturingBatch::find($a->id));
        $this->assertTrue($state['has_wip']);
        $this->assertSame('6.000000', $state['custody'][0]['quantity']);
    }
}
