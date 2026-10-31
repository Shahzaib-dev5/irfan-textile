<?php

namespace App\Modules\FinishedGoods\Services;

use App\Models\Document;
use App\Models\InventoryCostLink;
use App\Models\InventoryLayerAllocation;
use App\Models\InventoryValueEntry;
use App\Models\ManufacturingBatch;
use App\Models\PackingOrder;
use App\Models\PackingOutputLine;
use App\Models\ProcessingReceiptLine;
use App\Models\ProductionCostAllocation;
use App\Models\ProductionOutputLine;
use App\Models\ProductionReport;
use App\Models\StockCostLayer;
use App\Models\StockMovement;
use App\Models\StockPosition;
use App\Modules\Inventory\Services\InventoryGuard as G;
use App\Modules\Inventory\Support\Decimal as D;

/** Read-only exact-PKR projection. Never writes a cost, quantity or journal. */
class ActualCosting
{
    private array $cache = [];

    private array $active = [];

    private int $nodes = 0;

    public function reset(): void
    {
        $this->cache = [];
        $this->active = [];
        $this->nodes = 0;
    }

    public function total(array $parts): string
    {
        return array_reduce($parts, fn ($s, $p) => bcadd($s, $p['amount'], 4), '0.0000');
    }

    /** Largest remaining row absorbs deterministic residual; dimensions never enter money weights. */
    public function spread(string $amount, array $parts): array
    {
        $parts = array_filter($parts, fn ($p) => bccomp($p['amount'], '0', 4) > 0);
        $pool = $this->total($parts);
        $left = $amount;
        $result = [];
        if (bccomp($amount, '0', 4) === 0) {
            return [];
        }
        if (bccomp($pool, '0', 4) <= 0) {
            G::fail('Posted cost has no reconcilable source value.');
        }
        $keys = array_keys($parts);
        foreach ($parts as $key => $p) {
            $take = $key === end($keys) ? $left : D::round(bcdiv(bcmul($amount, $p['amount'], 16), $pool, 12));
            if (bccomp($take, $left, 4) > 0) {
                $take = $left;
            }$left = bcsub($left, $take, 4);
            if (bccomp($take, '0', 4) > 0) {
                $result[$key] = array_replace($p, ['amount' => $take]);
            }
        }

        return $result;
    }

    private function merge(array &$to, array $from): void
    {
        foreach ($from as $key => $p) {
            if (isset($to[$key])) {
                $to[$key]['amount'] = bcadd($to[$key]['amount'], $p['amount'], 4);
            } else {
                $to[$key] = $p;
            }
        }
    }

    private function leaf(string $key, string $label, string $amount, int $document, int $position, ?int $batch = null, ?int $job = null): array
    {
        return [$key => compact('label', 'amount', 'document', 'position', 'batch', 'job')];
    }

    public function layer(int $id, ?int $cutoff = null): array
    {
        $cutoff ??= (int) InventoryValueEntry::where('stock_cost_layer_id', $id)->max('id');
        $key = $id.':'.$cutoff;
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }
        if (isset($this->active[$key]) || ++$this->nodes > 5000) {
            G::fail('Cost lineage exceeds supported size or contains a cycle.');
        }$this->active[$key] = true;
        $l = StockCostLayer::findOrFail($id);
        $movement = StockMovement::with('document.type', 'position.lot.item')->findOrFail($l->receipt_stock_movement_id);
        $entries = InventoryValueEntry::where('stock_cost_layer_id', $id)->where('id', '<=', $cutoff)->whereIn('document_id', Document::whereIn('status', ['posted', 'reversed'])->select('id'))->orderBy('id')->get();
        $first = $entries->first(fn ($e) => $e->document_id === $movement->document_id && $e->value_reason === 'receipt');
        $parts = [];
        if ($first) {
            foreach (InventoryCostLink::where('destination_stock_cost_layer_id', $id)->where('document_id', $movement->document_id)->orderBy('id')->get() as $link) {
                if (bccomp($link->allocated_cost_pkr, '0', 4) <= 0) {
                    continue;
                }
                $a = InventoryLayerAllocation::findOrFail($link->source_layer_allocation_id);
                $issue = InventoryValueEntry::where('inventory_layer_allocation_id', $a->id)->where('amount_pkr', '<', 0)->orderBy('id')->firstOrFail();
                $this->merge($parts, $this->spread($link->allocated_cost_pkr, $this->layer($a->stock_cost_layer_id, $issue->id - 1)));
            }
            $service = ProcessingReceiptLine::with('step.service', 'receipt.document')->where('output_stock_movement_id', $movement->id)->first();
            if ($service && bccomp($service->service_accrual_pkr, '0', 4) > 0) {
                $this->merge($parts, $this->leaf('service:'.$service->id, 'Processing / '.$service->step->service->name.' / '.$service->receipt->document->number, $service->service_accrual_pkr, $movement->document_id, $l->stock_position_id, null, $service->receipt->processing_job_document_id));
            }
            $rest = bcsub($first->amount_pkr, $this->total($parts), 4);
            if (bccomp($rest, '0', 4) < 0) {
                G::fail('Inherited costs exceed the actual posted receipt value.');
            }
            if (bccomp($rest, '0', 4) > 0) {
                $item = $movement->position->lot->item;
                $label = ($movement->document->type->module_code === 'inventory' ? 'Audited inventory source' : 'Material').' / '.$item->sku.' / '.$movement->document->number;
                $this->merge($parts, $this->leaf('source:'.$first->id, $label, $rest, $movement->document_id, $l->stock_position_id));
            }
        }
        $directRows = ProductionCostAllocation::with('source.document', 'source.account')->whereHas('output', fn ($q) => $q->where('stock_movement_id', $movement->id))->orderBy('line_no')->get()->values();
        $directIndex = 0;
        foreach ($entries as $e) {
            if ($first && $e->id === $first->id) {
                continue;
            }
            if (bccomp($e->amount_pkr, '0', 4) > 0) {
                if ($e->value_reason === 'direct_cost' && ($direct = $directRows->get($directIndex++))) {
                    $new = $this->leaf('direct:'.$direct->id, ucwords(str_replace('_', ' ', $direct->cost_category)).' / '.$direct->source->account->name.' / '.$direct->source->document->number, $e->amount_pkr, $direct->source->journal_entry_id, $l->stock_position_id);
                } elseif ($e->reversal_of_value_entry_id) {
                    $original = InventoryValueEntry::findOrFail($e->reversal_of_value_entry_id);
                    $new = $this->spread($e->amount_pkr, $this->layer($original->stock_cost_layer_id, $original->id - 1));
                } else {
                    $new = $this->leaf('value:'.$e->id, 'Other posted value / document #'.$e->document_id, $e->amount_pkr, $e->document_id, $l->stock_position_id);
                }
                $this->merge($parts, $new);
            } elseif (bccomp($e->amount_pkr, '0', 4) < 0) {
                $removed = $this->spread(D::negative($e->amount_pkr, 4), $parts);
                foreach ($removed as $k => $p) {
                    $parts[$k]['amount'] = bcsub($parts[$k]['amount'], $p['amount'], 4);
                }
            }
        }
        if (bccomp($this->total($parts), $entries->reduce(fn ($sum, $entry) => bcadd($sum, $entry->amount_pkr, 4), '0.0000'), 4)) {
            G::fail('Cost breakdown does not reconcile to posted value entries.');
        }
        unset($this->active[$key]);

        return $this->cache[$key] = $parts;
    }

    public function acquisition(int $layer): array
    {
        $l = StockCostLayer::findOrFail($layer);
        $movement = StockMovement::findOrFail($l->receipt_stock_movement_id);
        $cutoff = InventoryValueEntry::where('stock_cost_layer_id', $layer)->where('document_id', $movement->document_id)->max('id');

        return $cutoff ? $this->layer($layer, (int) $cutoff) : [];
    }

    public function report(Document $document): array
    {
        $this->reset();
        $production = $document->type->module_code === 'production';
        if ($production) {
            $batch = ManufacturingBatch::findOrFail($document->id);
            $outputs = ProductionOutputLine::with('position.lot.item.baseUnit')->where('output_kind', 'finished')->whereIn('document_id', Document::where('status', 'posted')->select('id'))->whereIn('document_id', $batch->reports()->pluck('document_id'))->get();
        } else {
            $batch = null;
            $outputs = PackingOutputLine::with('position.lot.item.baseUnit')->where('document_id', $document->id)->get();
        }
        $rows = [];
        $all = [];
        $total = '0.0000';
        $good = '0';
        $rejected = '0';
        foreach ($outputs as $out) {
            $layer = StockCostLayer::where('receipt_stock_movement_id', $out->stock_movement_id)->first();
            if (! $layer) {
                continue;
            }$parts = $this->acquisition($layer->id);
            $value = $this->total($parts);
            $this->merge($all, $parts);
            $unit = $out->position->lot->item->baseUnit->code;
            $suits = $unit === 'SUIT' ? $out->quantity : ($unit === 'PACK' ? bcmul($out->quantity, PackingOrder::findOrFail($document->id)->spec->lines->where('component_kind', 'saleable_content')->reduce(fn ($s, $l) => bcadd($s, $l->quantity_per_pack, 6), '0'), 6) : '0');
            $bad = $out->position->lot->quality_status === 'rejected';
            $bad ? $rejected = bcadd($rejected, $suits, 6) : $good = bcadd($good, $suits, 6);
            $total = bcadd($total, $value, 4);
            $rows[] = ['output' => $out, 'layer' => $layer, 'cost' => $value, 'suits' => $suits, 'per_suit' => bccomp($suits, '0', 6) > 0 ? D::round(bcdiv($value, $suits, 12)) : null, 'parts' => $parts, 'rejected' => $bad];
        }

        return compact('document', 'batch', 'rows', 'all', 'total', 'good', 'rejected');
    }

    public function ancestry(int $layer): array
    {
        $front = [$layer];
        $seen = [];
        $batches = [];
        $jobs = [];
        $documents = [];
        $positions = [];
        while ($front) {
            $seen = array_values(array_unique([...$seen, ...$front]));
            if (count($seen) > 5000) {
                G::fail('Trace exceeds supported size.');
            }$next = [];
            foreach (StockCostLayer::whereIn('id', $front)->get() as $l) {
                $positions[] = $l->stock_position_id;
                $m = StockMovement::findOrFail($l->receipt_stock_movement_id);
                $documents[] = $m->document_id;
                $out = ProductionOutputLine::where('stock_movement_id', $m->id)->first();
                if ($out) {
                    $batches[] = ProductionReport::findOrFail($out->document_id)->manufacturing_batch_document_id;
                }
                $r = ProcessingReceiptLine::where('output_stock_movement_id', $m->id)->first();
                if ($r) {
                    $jobs[] = $r->receipt->processing_job_document_id;
                }
                $links = InventoryCostLink::where('destination_stock_cost_layer_id', $l->id)->pluck('source_layer_allocation_id');
                $next = [...$next, ...InventoryLayerAllocation::whereIn('id', $links)->pluck('stock_cost_layer_id')->all()];
            }$front = array_values(array_diff($next, $seen));
        }

        return ['batches' => array_values(array_unique($batches)), 'jobs' => array_values(array_unique($jobs)), 'documents' => array_values(array_unique($documents)), 'positions' => StockPosition::with('lot.item.baseUnit', 'location.warehouse')->whereIn('id', array_unique($positions))->get()];
    }
}
