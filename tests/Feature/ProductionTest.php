<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\InventoryCostLink;
use App\Models\Item;
use App\Models\ManufacturingBatch;
use App\Models\ProductionOutputLine;
use App\Models\ProductionReport;
use App\Models\ProductionWastageLine;
use App\Models\ProductVariant;
use App\Models\StockCostLayer;
use App\Models\StockMovement;
use App\Models\StockPosition;
use App\Modules\Catalog\Services\CatalogService;
use App\Modules\Catalog\Services\CatalogWorkspace;
use App\Modules\Inventory\Services\StockLedger;
use App\Modules\Production\Services\BomService;
use App\Modules\Production\Services\ProductionProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\ProductionFixtures;
use Tests\TestCase;

class ProductionTest extends TestCase
{
    use ProductionFixtures,RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->productionFixtures();
    }

    public function test_partial_production_then_final_report_conserves_material_value_and_batch_progress(): void
    {
        $p = $this->position($this->opening('700', '10'));
        $batch = $this->batch();
        $a = $this->production->confirm($this->admin->id, $this->reportData($batch, $p));
        $state = app(ProductionProgress::class)->summary(ManufacturingBatch::find($batch->id));
        $this->assertSame('50.000000', $state['good']);
        $this->assertFalse($state['complete']);
        $b = $this->production->confirm($this->admin->id, $this->reportData($batch, $p, '300', '50', true));
        $state = app(ProductionProgress::class)->summary(ManufacturingBatch::find($batch->id));
        $this->assertTrue($state['complete']);
        $this->assertSame('100.000000', $state['good']);
        $this->assertSame('100.000000', app(StockLedger::class)->onHand($p));
        $this->assertSame('6000.0000', $state['finished_cost']);
        $this->assertSame(5, StockMovement::count());
        foreach (ProductionOutputLine::all() as $out) {
            $layer = StockCostLayer::where('receipt_stock_movement_id', $out->stock_movement_id)->firstOrFail();
            $this->assertSame('3000.0000', bcadd((string) $layer->values()->sum('amount_pkr'), '0', 4));
        }
    }

    public function test_finished_output_without_required_net_material_is_rejected_atomically(): void
    {
        $p = $this->position($this->opening('700'));
        $batch = $this->batch();
        $before = Document::count();
        try {
            $this->production->confirm($this->admin->id, $this->reportData($batch, $p, '10', '50'));
            $this->fail('Expected rejection.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('BOM net requirement', $e->getMessage());
        }
        $this->assertSame($before, Document::count());
        $this->assertSame(0, ProductionReport::count());
        $this->assertSame(1, StockMovement::count());
    }

    private function rejected(callable $action, string $text = ''): void
    {
        try {
            $action();
            $this->fail('Expected invalid production to be rejected.');
        } catch (ValidationException $e) {
            if ($text) {
                $this->assertStringContainsString($text, $e->getMessage());
            } else {
                $this->assertNotEmpty($e->errors());
            }
        }
    }

    public function test_normal_and_abnormal_waste_are_not_issued_twice_and_costs_balance(): void
    {
        $p = $this->position($this->opening('320', '10'));
        $batch = $this->batch('50');
        $d = $this->reportData($batch, $p, '320', '50', true);
        $d['wastage'] = [['material_index' => 0, 'waste_class' => 'normal', 'quantity' => '10', 'reason' => 'Measured normal cutting offcuts.'], ['material_index' => 0, 'waste_class' => 'abnormal', 'quantity' => '10', 'reason' => 'Damaged fabric cannot be recovered.']];
        $doc = $this->production->confirm($this->admin->id, $d);
        $out = ProductionOutputLine::where('document_id', $doc->id)->first();
        $layer = StockCostLayer::where('receipt_stock_movement_id', $out->stock_movement_id)->first();
        $this->assertSame('3100.0000', bcadd((string) $layer->values()->sum('amount_pkr'), '0', 4));
        $this->assertSame(3, StockMovement::count());
        $this->assertSame('100.0000', ProductionWastageLine::where('waste_class', 'abnormal')->first()->expense_amount_pkr);
        $this->assertSame('100.0000', $doc->journal->total_debit);
        $this->assertSame($doc->journal->total_debit, $doc->journal->total_credit);
        $this->assertSame('3200.0000', bcadd((string) InventoryCostLink::where('document_id', $doc->id)->sum('allocated_cost_pkr'), '0', 4));
    }

    public function test_direct_confirmation_retry_is_once_and_changed_payload_is_rejected(): void
    {
        $p = $this->position($this->opening('700'));
        $batch = $this->batch();
        $data = $this->reportData($batch, $p);
        $a = $this->production->confirm($this->admin->id, $data);
        $b = $this->production->confirm($this->admin->id, $data);
        $this->assertSame($a->id, $b->id);
        $this->assertSame(3, StockMovement::count());
        $data['outputs'][0]['quantity'] = '51';
        $this->rejected(fn () => $this->production->confirm($this->admin->id, $data), 'different production details');
        $this->assertSame(1, ProductionReport::count());
    }

    public function test_invalid_final_overproduction_waste_fraction_and_wrong_variant_leave_no_effects(): void
    {
        $p = $this->position($this->opening('700'));
        $batch = $this->batch();
        $base = $this->reportData($batch, $p);
        $before = Document::count();
        $d = $base;
        $d['is_final'] = true;
        $this->rejected(fn () => $this->production->confirm($this->admin->id, $d), 'Final completion');
        $d = $base;
        $d['outputs'][0]['quantity'] = '101';
        $this->rejected(fn () => $this->production->confirm($this->admin->id, $d), 'exceed the batch plan');
        $d = $base;
        $d['wastage'] = [['material_index' => 0, 'waste_class' => 'normal', 'quantity' => '301', 'reason' => 'Invalid excess loss.']];
        $this->rejected(fn () => $this->production->confirm($this->admin->id, $d), 'cannot exceed');
        $d = $base;
        $d['outputs'][0]['cost_allocation_fraction'] = '0.9';
        $this->rejected(fn () => $this->production->confirm($this->admin->id, $d), 'total exactly 1');
        $d = $base;
        $d['outputs'][0]['item_id'] = $this->item->id;
        $this->rejected(fn () => $this->production->confirm($this->admin->id, $d), 'design/color SKU');
        $this->assertSame($before, Document::count());
        $this->assertSame(1, StockMovement::count());
    }

    public function test_intermediate_wip_can_be_consumed_later_and_cannot_hide_in_another_location(): void
    {
        $p = $this->position($this->opening('600'));
        $batch = $this->batch();
        $intermediate = Item::factory()->create(['item_type' => 'processed_fabric', 'base_unit_id' => $this->item->base_unit_id, 'is_stocked' => true, 'track_rolls' => false]);
        $d = $this->reportData($batch, $p, '600', '600');
        $d['outputs'][0]['item_id'] = $intermediate->id;
        $d['outputs'][0]['output_kind'] = 'intermediate';
        $a = $this->production->confirm($this->admin->id, $d);
        $output = ProductionOutputLine::where('document_id', $a->id)->first();
        $this->postDocument($this->transfer($output->stock_position_id, '600', $this->source->id));
        $state = app(ProductionProgress::class)->summary(ManufacturingBatch::find($batch->id));
        $this->assertTrue($state['has_wip']);
        $this->assertSame('0.000000', $state['accounted']);
        $pos = StockPosition::where('stock_lot_id', $output->position->stock_lot_id)->where('warehouse_location_id', $this->source->id)->first();
        $d = $this->reportData($batch, $pos->id, '600', '100', true);
        $d['materials'][0]['manufacturing_batch_material_id'] = null;
        $d['materials'][0]['extra_material_reason'] = 'Use traced intermediate fabric from the earlier batch report.';
        $b = $this->production->confirm($this->admin->id, $d);
        $state = app(ProductionProgress::class)->summary(ManufacturingBatch::find($batch->id));
        $this->assertTrue($state['complete']);
        $this->assertFalse($state['has_wip']);
        $this->assertSame('6000.0000', $state['finished_cost']);
        $this->rejected(fn () => $this->production->confirm($this->admin->id, $this->reportData($batch, $p, '1', '1')), 'has not been completed');
    }

    public function test_same_unit_intermediate_output_cannot_exceed_physical_input(): void
    {
        $p = $this->position($this->opening('100'));
        $batch = $this->batch();
        $d = $this->reportData($batch, $p, '100', '1000');
        $d['outputs'][0]['item_id'] = $this->item->id;
        $d['outputs'][0]['output_kind'] = 'intermediate';
        $this->rejected(fn () => $this->production->confirm($this->admin->id, $d), 'physical net input');
        $this->assertSame(1, StockMovement::count());
    }

    public function test_retained_rejected_suits_are_separate_from_accepted_output(): void
    {
        $p = $this->position($this->opening('60'));
        $batch = $this->batch('10');
        $d = $this->reportData($batch, $p, '60', '8', true);
        $d['outputs'][0]['cost_allocation_fraction'] = '0.8';
        $reject = $d['outputs'][0];
        $reject['quantity'] = '2';
        $reject['quality_status'] = 'rejected';
        $reject['lot_number'] = 'REJECTED-SUITS';
        $reject['cost_allocation_fraction'] = '0.2';
        $d['outputs'][] = $reject;
        $d['report_note'] = 'Two suits retained in rejected stock for supervised rework.';
        $doc = $this->production->confirm($this->admin->id, $d);
        $state = app(ProductionProgress::class)->summary(ManufacturingBatch::find($batch->id));
        $this->assertSame('8.000000', $state['good']);
        $this->assertSame('2.000000', $state['rejected']);
        $this->assertTrue($state['complete']);
        $out = ProductionOutputLine::where('document_id', $doc->id)->where('line_no', 2)->first();
        $this->rejected(fn () => app(StockLedger::class)->position($out->stock_position_id, $this->date, true), 'Only released stock');
    }

    public function test_published_bom_and_posted_material_snapshots_are_immutable(): void
    {
        $batch = $this->batch();
        $line = ManufacturingBatch::find($batch->id)->lines->first();
        try {
            $line->update(['planned_net_quantity' => '1']);
            $this->fail('Expected immutable plan.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('cannot be edited', $e->getMessage());
        }
        try {
            $this->bom->lines->first()->update(['net_quantity' => '1']);
            $this->fail('Expected immutable recipe.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }
        $new = app(BomService::class)->create($this->admin->id, ['product_variant_id' => $this->variant->id, 'output_quantity' => '1', 'lines' => [['component_item_id' => $this->item->id, 'component_name' => 'Revised fabric', 'net_quantity' => '7', 'planned_wastage_fraction' => '0']]]);
        $this->assertSame(2, $new->version_number);
        $this->assertSame('600.000000', $line->fresh()->planned_net_quantity);
    }

    public function test_cost_lineage_rounding_conserves_each_source_and_output(): void
    {
        $positions = [];
        foreach (range(1, 3) as $n) {
            $positions[] = $this->position($this->opening('1', '0.0001'));
        }
        $batch = $this->batch('1');
        $d = $this->reportData($batch, $positions[0], '1', '2');
        $d['materials'] = array_map(fn ($p) => ['stock_position_id' => $p, 'quantity' => '1', 'manufacturing_batch_material_id' => ManufacturingBatch::find($batch->id)->lines->first()->id], $positions);
        $d['outputs'][0]['item_id'] = $this->item->id;
        $d['outputs'][0]['output_kind'] = 'intermediate';
        $d['outputs'][0]['cost_allocation_fraction'] = '0.5';
        $scrap = Item::factory()->create(['item_type' => 'scrap', 'base_unit_id' => $this->item->base_unit_id, 'is_stocked' => true, 'track_rolls' => false]);
        $out = $d['outputs'][0];
        $out['item_id'] = $scrap->id;
        $out['output_kind'] = 'byproduct';
        $out['quantity'] = '1';
        $out['lot_number'] = 'ROUNDING-SCRAP';
        $d['outputs'][] = $out;
        $doc = $this->production->confirm($this->admin->id, $d);
        foreach (ProductionOutputLine::where('document_id', $doc->id)->get() as $o) {
            $layer = StockCostLayer::where('receipt_stock_movement_id', $o->stock_movement_id)->first();
            $this->assertSame(bcadd((string) $layer->values()->sum('amount_pkr'), '0', 4), bcadd((string) InventoryCostLink::where('destination_stock_cost_layer_id', $layer->id)->sum('allocated_cost_pkr'), '0', 4));
        }
        $this->assertSame('0.0003', bcadd((string) InventoryCostLink::where('document_id', $doc->id)->sum('allocated_cost_pkr'), '0', 4));
    }

    public function test_bom_cycles_and_component_identity_edits_are_rejected(): void
    {
        $other = ProductVariant::factory()->create();
        $service = app(BomService::class);
        $service->create($this->admin->id, ['product_variant_id' => $this->variant->id, 'output_quantity' => '1', 'lines' => [['component_item_id' => $other->item_id, 'component_name' => 'Subassembly', 'net_quantity' => '1', 'planned_wastage_fraction' => '0']]]);
        $this->rejected(fn () => $service->create($this->admin->id, ['product_variant_id' => $other->id, 'output_quantity' => '1', 'lines' => [['component_item_id' => $this->variant->item_id, 'component_name' => 'Recursive product', 'net_quantity' => '1', 'planned_wastage_fraction' => '0']]]), 'Recursive BOM');
        $data = ['item_id' => $this->variant->item_id, 'design_id' => $other->design_id, 'color_id' => $this->variant->color_id, 'configuration_code' => $this->variant->configuration_code, 'piece_count' => $this->variant->piece_count, 'is_active' => true, 'description' => null];
        $this->rejected(fn () => app(CatalogService::class)->save('variants', $data, $this->variant->id), 'Published BOMs');
        $finished = $this->variant->item;
        $edit = $finished->only(['sku', 'name', 'item_type', 'base_unit_id', 'category_id', 'is_stocked', 'track_rolls', 'is_active', 'description']);
        $edit['sku'] = 'RENAME-FROZEN-OUTPUT';
        $this->rejected(fn () => app(CatalogService::class)->save('items', $edit, $finished->id), 'Published BOMs');
        $config = app(CatalogWorkspace::class)->itemConfig($finished, false);
        $this->assertTrue($config['skuLocked']);
        $this->assertTrue($config['identityLocked']);

    }

    public function test_fully_received_batch_can_close_without_duplicate_consumption(): void
    {
        $batch = $this->batch('50');
        $p = $this->position($this->opening('300'));
        $close = ['kind' => 'report', 'document_date' => $this->date, 'idempotency_key' => (string) Str::uuid(), 'manufacturing_batch_document_id' => $batch->id, 'is_final' => true];
        $this->rejected(fn () => $this->production->confirm($this->admin->id, $close), 'Final completion');
        $this->production->confirm($this->admin->id, $this->reportData($batch, $p, '300', '50'));
        $count = StockMovement::count();
        $this->get(route('production.reports.create', ['batch' => $batch->id]))->assertOk()->assertSee('Complete fully received batch');
        $doc = $this->production->confirm($this->admin->id, $close);
        $this->assertSame($count, StockMovement::count());
        $this->assertTrue(app(ProductionProgress::class)->summary(ManufacturingBatch::find($batch->id))['complete']);
        $this->assertNull($doc->journal);
        $this->assertSame($doc->id, $this->production->confirm($this->admin->id, $close)->id);
    }

    public function test_recycled_intermediate_cannot_count_the_same_raw_input_twice(): void
    {
        $p = $this->position($this->opening('6'));
        $batch = $this->batch('2');
        $d = $this->reportData($batch, $p, '6', '6');
        $d['outputs'][0]['item_id'] = $this->item->id;
        $d['outputs'][0]['output_kind'] = 'intermediate';
        $report = $this->production->confirm($this->admin->id, $d);
        $wip = ProductionOutputLine::where('document_id', $report->id)->first()->stock_position_id;
        $next = $this->reportData($batch, $wip, '6', '2', true);
        $this->rejected(fn () => $this->production->confirm($this->admin->id, $next), 'requirement twice');
        $next['materials'][0]['manufacturing_batch_material_id'] = null;
        $next['materials'][0]['extra_material_reason'] = 'Reconsume the earlier traced intermediate.';
        $this->rejected(fn () => $this->production->confirm($this->admin->id, $next), 'not backed by the BOM');
        $this->assertSame(1, ProductionReport::count());
        $this->assertSame('6.000000', app(StockLedger::class)->onHand($wip));
    }

    public function test_transferred_wip_cannot_be_consumed_by_a_different_batch(): void
    {
        $p = $this->position($this->opening('6'));
        $a = $this->batch('1');
        $d = $this->reportData($a, $p, '6', '6');
        $d['outputs'][0]['item_id'] = $this->item->id;
        $d['outputs'][0]['output_kind'] = 'intermediate';
        $r = $this->production->confirm($this->admin->id, $d);
        $out = ProductionOutputLine::where('document_id', $r->id)->first();
        $this->postDocument($this->transfer($out->stock_position_id, '6', $this->source->id));
        $wip = StockPosition::where('stock_lot_id', $out->position->stock_lot_id)->where('warehouse_location_id', $this->source->id)->first();
        $b = $this->batch('1');
        $this->rejected(fn () => $this->production->confirm($this->admin->id, $this->reportData($b, $wip->id, '6', '1', true)), 'another production batch');
        $this->assertSame('6.000000', app(StockLedger::class)->onHand($wip->id));
    }
}
