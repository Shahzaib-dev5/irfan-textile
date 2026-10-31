<?php

namespace App\Modules\Production\Services;

use App\Models\InventoryCostLink;
use App\Models\InventoryLayerAllocation;
use App\Models\InventoryValueEntry;
use App\Models\ManufacturingBatch;
use App\Models\ProcessingDispatch;
use App\Models\ProcessingDispatchLine;
use App\Models\ProductionMaterialLine;
use App\Models\ProductionOutputLine;
use App\Models\ProductionReport;
use App\Models\StockCostLayer;
use App\Models\StockPosition;
use App\Modules\Inventory\Services\InventoryGuard;
use App\Modules\Processing\Services\ProcessingService;

class ProductionProgress
{
    public function reportIds(int $batch, ?int $include = null): array
    {
        return ProductionReport::where('manufacturing_batch_document_id', $batch)->where(function ($q) use ($include) {
            $q->whereHas('document', fn ($q) => $q->where('status', 'posted'));
            if ($include) {
                $q->orWhere('document_id', $include);
            }
        })->pluck('document_id')->all();
    }

    public function final(int $batch): bool
    {
        return ProductionReport::where('manufacturing_batch_document_id', $batch)->where('is_final', true)->whereHas('document', fn ($q) => $q->where('status', 'posted'))->exists();
    }

    /** Reusing WIP must not satisfy a raw-material requirement twice or fund another batch. */
    public function assertWipOrigin(array $layers, int $batch, ?int $plan): void
    {
        $front = $layers;
        $seen = [];
        while ($front) {
            $seen = array_values(array_unique([...$seen, ...$front]));
            if (count($seen) > 5000) {
                InventoryGuard::fail('WIP origin exceeds the supported lineage size.');
            }
            $movements = StockCostLayer::whereIn('id', $front)->pluck('receipt_stock_movement_id');
            $origins = ProductionReport::whereIn('document_id', ProductionOutputLine::whereIn('stock_movement_id', $movements)->where('output_kind', 'intermediate')->pluck('document_id'))->pluck('manufacturing_batch_document_id');
            foreach ($origins as $origin) {
                if ((int) $origin !== $batch) {
                    InventoryGuard::fail('Intermediate WIP belongs to another production batch. Cross-batch reallocation is not implemented.');
                }
                if ($plan !== null) {
                    InventoryGuard::fail('Consume this batch WIP as Extra / intermediate input; do not count its frozen raw-material requirement twice.');
                }
            }
            // A completed finished subassembly may legitimately be a new BOM component.
            $finished = StockCostLayer::whereIn('id', $front)->whereIn('receipt_stock_movement_id', ProductionOutputLine::whereIn('stock_movement_id', $movements)->where('output_kind', 'finished')->pluck('stock_movement_id'))->pluck('id')->all();
            $traverse = array_values(array_diff($front, $finished));
            $allocations = InventoryCostLink::whereIn('destination_stock_cost_layer_id', $traverse)->pluck('source_layer_allocation_id');
            $next = InventoryLayerAllocation::whereIn('id', $allocations)->pluck('stock_cost_layer_id')->unique()->all();
            $front = array_values(array_diff($next, $seen));
        }
    }

    public function summary(ManufacturingBatch $batch, ?int $include = null): array
    {
        $ids = $this->reportIds($batch->document_id, $include);
        $outputs = ProductionOutputLine::with('position.lot.item.baseUnit')->whereIn('document_id', $ids)->get();
        $good = '0.000000';
        $rejected = '0.000000';
        $wip = [];
        $remainingWip = false;
        $finishedCost = '0.0000';
        foreach ($outputs as $out) {
            if ($out->output_kind === 'finished') {
                if ($out->position->lot->quality_status === 'rejected') {
                    $rejected = bcadd($rejected, $out->quantity, 6);
                } else {
                    $good = bcadd($good, $out->quantity, 6);
                }
                $layer = StockCostLayer::where('receipt_stock_movement_id', $out->stock_movement_id)->first();
                if ($layer) {
                    $finishedCost = bcadd($finishedCost, (string) InventoryValueEntry::where('stock_cost_layer_id', $layer->id)->where('document_id', $out->document_id)->sum('amount_pkr'), 4);
                }
            }

        }
        // Follow WIP through transfers/Processing cost lineage; moving a lot cannot hide it.
        $roots = StockCostLayer::whereIn('receipt_stock_movement_id', $outputs->where('output_kind', 'intermediate')->pluck('stock_movement_id')->filter())->pluck('id')->all();
        $seen = [];
        $front = $roots;
        while ($front) {
            $seen = array_values(array_unique([...$seen, ...$front]));
            if (count($seen) > 5000) {
                InventoryGuard::fail('WIP lineage exceeds the supported inquiry size.');
            }
            $next = InventoryCostLink::whereIn('source_layer_allocation_id', InventoryLayerAllocation::whereIn('stock_cost_layer_id', $front)->pluck('id'))->whereNotNull('destination_stock_cost_layer_id')->pluck('destination_stock_cost_layer_id')->unique()->all();
            $front = array_values(array_diff($next, $seen));
        }
        foreach (StockCostLayer::whereIn('id', $seen)->where('remaining_quantity', '>', 0)->get() as $layer) {
            $position = StockPosition::with('lot.item.baseUnit', 'location.warehouse')->findOrFail($layer->stock_position_id);
            if (in_array($position->lot->item->item_type, ['finished', 'scrap'])) {
                continue;
            }
            $remainingWip = true;
            $wip[] = ['position' => $position, 'quantity' => $layer->remaining_quantity, 'value' => bcadd((string) $layer->values()->sum('amount_pkr'), '0', 4), 'unit' => $position->lot->item->baseUnit->code];
        }
        $materials = [];
        foreach ($batch->lines as $plan) {
            $lines = ProductionMaterialLine::with('wastage')->whereIn('document_id', $ids)->where('manufacturing_batch_material_id', $plan->id)->get();
            $gross = '0';
            $waste = '0';
            foreach ($lines as $l) {
                $gross = bcadd($gross, $l->quantity, 6);
                $waste = bcadd($waste, $l->wastage->reduce(fn ($sum, $w) => bcadd($sum, $w->quantity, 6), '0'), 6);
            }
            $materials[] = ['plan' => $plan, 'gross' => bcadd($gross, '0', 6), 'waste' => bcadd($waste, '0', 6), 'net' => bcsub($gross, $waste, 6)];
        }
        $outstanding = [];
        $jobIds = $batch->processingJobs()->pluck('document_id');
        $dispatchIds = ProcessingDispatch::whereIn('processing_job_document_id', $jobIds)->whereHas('document', fn ($q) => $q->where('status', 'posted'))->pluck('document_id');
        foreach (ProcessingDispatchLine::with('source.lot.item.baseUnit', 'dispatch.document')->whereIn('document_id', $dispatchIds)->get() as $line) {
            $qty = app(ProcessingService::class)->outstanding($line);
            if (bccomp($qty, '0', 6) > 0) {
                $outstanding[] = ['line' => $line, 'quantity' => $qty];
            }
        }

        return ['good' => $good, 'rejected' => $rejected, 'accounted' => bcadd($good, $rejected, 6), 'materials' => $materials, 'wip' => $wip, 'has_wip' => $remainingWip, 'custody' => $outstanding, 'finished_cost' => $finishedCost, 'complete' => $this->final($batch->document_id), 'reports' => $ids];
    }
}
