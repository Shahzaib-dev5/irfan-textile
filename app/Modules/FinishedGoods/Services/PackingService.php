<?php

namespace App\Modules\FinishedGoods\Services;

use App\Models\AuditEvent;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\InventoryCostLink;
use App\Models\InventoryLayerAllocation;
use App\Models\Item;
use App\Models\Package;
use App\Models\PackageContent;
use App\Models\PackingMaterialLine;
use App\Models\PackingOrder;
use App\Models\PackingOutputLine;
use App\Models\ProductPackSpec;
use App\Models\StockBalance;
use App\Models\StockCostLayer;
use App\Models\StockLot;
use App\Models\StockPosition;
use App\Models\WarehouseLocation;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Inventory\Services\InventoryGuard as G;
use App\Modules\Inventory\Services\StockCostDistribution;
use App\Modules\Inventory\Services\StockLedger;
use App\Modules\Inventory\Support\Decimal as D;
use App\Modules\Purchasing\Services\PurchaseDocuments;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

class PackingService
{
    public function __construct(private StockLedger $stock, private PurchaseDocuments $docs, private StockCostDistribution $distribution) {}

    public function kind(Document $d): string
    {
        abort_unless($d->type->module_code === 'finished-goods' && $d->type->code === 'finished_goods_packing', 404);

        return 'packing';
    }

    public function header(Document $d): PackingOrder
    {
        $this->kind($d);

        return PackingOrder::with('spec.item.baseUnit', 'spec.lines.item.baseUnit', 'materials.position.lot.item.baseUnit', 'outputs.position.lot.item.baseUnit', 'outputs.packages.contents')->findOrFail($d->id);
    }

    public function save(int $actor, array $input): Document
    {
        return DB::transaction(function () use ($actor, $input) {
            G::company();
            G::actor($actor, 'finished-goods.manage');
            $d = validator($input, ['document_date' => 'required|date_format:Y-m-d', 'idempotency_key' => 'required|uuid', 'product_pack_spec_id' => 'required|integer|exists:product_pack_specs,id', 'operation' => 'required|in:pack,unpack', 'remarks' => 'nullable|string|max:1000', 'materials' => 'required|array|min:1|max:100', 'materials.*' => 'array', 'outputs' => 'required|array|min:1|max:100', 'outputs.*' => 'array'])->validate();
            $d['materials'] = array_values($d['materials']);
            $d['outputs'] = array_values($d['outputs']);
            $hash = hash('sha256', json_encode($d, JSON_THROW_ON_ERROR));
            if ($old = Document::where('idempotency_key', $d['idempotency_key'])->first()) {
                $this->kind($old);
                $audit = AuditEvent::where('document_id', $old->id)->where('action', 'packing_prepared')->first();
                if (($audit?->after_json['input_hash'] ?? null) !== $hash) {
                    G::fail('This request key already belongs to different packing details.');
                }

                return $old;
            }
            [$year,$period] = $this->docs->calendar($d['document_date']);
            $spec = ProductPackSpec::with('item.baseUnit', 'lines.item.baseUnit')->findOrFail($d['product_pack_spec_id']);
            $this->activeSpec($spec);
            $type = DocumentType::where('code', 'finished_goods_packing')->where('is_active', true)->firstOrFail();
            $doc = Document::create(['document_type_id' => $type->id, 'document_date' => $d['document_date'], 'financial_year_id' => $year->id, 'accounting_period_id' => $period->id, 'created_by' => $actor, 'status' => 'draft', 'idempotency_key' => $d['idempotency_key']]);
            PackingOrder::create(['document_id' => $doc->id, 'product_pack_spec_id' => $spec->id, 'operation' => $d['operation'], 'remarks' => $d['remarks'] ?? null]);
            foreach ($d['materials'] as $i => $raw) {
                $r = validator($raw, ['stock_position_id' => 'required|integer|exists:stock_positions,id', 'product_pack_spec_line_id' => 'nullable|integer', 'quantity' => 'required|numeric|gt:0'])->validate();
                $p = $this->stock->position($r['stock_position_id'], $d['document_date'], $d['operation'] === 'pack');
                if ($p->location->location_type !== 'internal') {
                    G::fail('Use company internal storage for packing/recovery.');
                }
                $qty = $this->stock->quantity($p, $r['quantity']);
                $line = empty($r['product_pack_spec_line_id']) ? null : $spec->lines->firstWhere('id', (int) $r['product_pack_spec_line_id']);
                if ($d['operation'] === 'pack' && (! $line || $line->component_item_id !== $p->lot->item_id)) {
                    G::fail('The actual input must match its selected specification component.');
                }
                if ($d['operation'] === 'unpack' && (! empty($r['product_pack_spec_line_id']) || $p->lot->item_id !== $spec->packed_item_id)) {
                    G::fail('Unpacking consumes the matching PACK item, without a component requirement.');
                }
                PackingMaterialLine::create(['document_id' => $doc->id, 'line_no' => $i + 1, 'stock_position_id' => $p->id, 'quantity' => $qty, 'product_pack_spec_line_id' => $line?->id]);
            }
            foreach ($d['outputs'] as $i => $raw) {
                $r = validator($raw, ['item_id' => 'required|integer|exists:items,id', 'warehouse_location_id' => 'required|integer|exists:warehouse_locations,id', 'lot_number' => 'required|string|max:50|unique:stock_lots,lot_number', 'quantity' => 'required|numeric|gt:0', 'quality_status' => 'required|in:pending,released,held,rejected', 'cost_allocation_fraction' => 'required_if:operation,unpack|nullable|numeric|min:0|max:1', 'packages' => 'sometimes|array|max:1000'])->validate();
                $item = Item::with('baseUnit')->findOrFail($r['item_id']);
                $qty = PackSpecificationService::precision($item, $r['quantity']);
                $loc = WarehouseLocation::with('warehouse')->findOrFail($r['warehouse_location_id']);
                if (! $item->is_active || ! $item->is_stocked || ! $item->baseUnit->is_active || ! $loc->is_active || ! $loc->warehouse->is_active || ! in_array($loc->location_type, ['internal', 'quarantine'], true)) {
                    G::fail('Receive actual finished goods at active company internal/quarantine storage.');
                }
                if ($d['operation'] === 'pack' && $item->id !== $spec->packed_item_id) {
                    G::fail('Packed output must match the specification PACK item.');
                }
                if ($d['operation'] === 'unpack' && ! $spec->lines->contains('component_item_id', $item->id)) {
                    G::fail('Recovered output must be a component of this specification.');
                }
                if ($r['quality_status'] === 'rejected' && mb_strlen(trim($d['remarks'] ?? '')) < 5) {
                    G::fail('Explain retained rejected output in the document remarks.');
                }
                $lot = StockLot::create(['item_id' => $item->id, 'lot_number' => $r['lot_number'], 'origin_document_id' => $doc->id, 'quality_status' => $r['quality_status']]);
                $pos = StockPosition::create(['stock_lot_id' => $lot->id, 'fabric_roll_id' => null, 'warehouse_location_id' => $loc->id]);
                StockBalance::create(['stock_position_id' => $pos->id, 'on_hand_quantity' => '0', 'reserved_quantity' => '0', 'lock_version' => 0]);
                $out = PackingOutputLine::create(['document_id' => $doc->id, 'line_no' => $i + 1, 'stock_position_id' => $pos->id, 'quantity' => $qty, 'cost_allocation_fraction' => $d['operation'] === 'pack' ? '0' : D::input($r['cost_allocation_fraction'] ?? '', 10)]);
                if ($d['operation'] === 'pack') {
                    foreach ($r['packages'] ?? [] as $package) {
                        $p = validator($package, ['package_code' => 'required|string|max:80|unique:packages,package_code', 'quantity' => 'required|numeric|gt:0', 'gross_weight_kg' => 'nullable|numeric|min:0|max:999999999.999|decimal:0,3'])->validate();
                        Package::create(['packing_output_line_id' => $out->id, 'package_code' => $p['package_code'], 'quantity' => PackSpecificationService::precision($item, $p['quantity']), 'gross_weight_kg' => $p['gross_weight_kg'] ?? null, 'sealed_at' => null]);
                    }
                } elseif (! empty($r['packages'])) {
                    G::fail('Recovered component lots do not create new PACK container records.');
                }
            }
            $h = $this->header($doc);
            $this->reconcile($h, true);
            $this->contents($h);
            app(AuditLogger::class)->record('documents', $doc->id, 'packing_prepared', null, ['input_hash' => $hash, 'input' => $d], $actor, $doc->id);
            $this->docs->event($doc, 'created', $actor, $d['idempotency_key'], null, 'draft');

            return $doc->fresh();
        }, 3);
    }

    private function activeSpec(ProductPackSpec $s): void
    {
        if ($s->state !== 'published' || ! $s->is_active || ! $s->item->is_active || ! $s->item->is_stocked || $s->item->baseUnit->code !== 'PACK' || $s->lines->isEmpty()) {
            G::fail('Choose an active published PACK specification.');
        }
        foreach ($s->lines as $line) {
            if (! $line->item->is_active || ! $line->item->is_stocked || ! $line->item->baseUnit->is_active) {
                G::fail('Specification components must remain active.');
            }
        }
    }

    private function reconcile(PackingOrder $h, bool $prepare = false): void
    {
        $this->activeSpec($h->spec);
        $pack = $h->operation === 'pack';
        $total = ($pack ? $h->outputs : $h->materials)->reduce(fn ($sum, $l) => bcadd($sum, $l->quantity, 6), '0');
        $totals = [];
        foreach ($pack ? $h->materials : $h->outputs as $l) {
            $id = $pack ? $l->product_pack_spec_line_id : $h->spec->lines->firstWhere('component_item_id', $l->position->lot->item_id)?->id;
            $line = $h->spec->lines->firstWhere('id', $id);
            if (! $line || $line->component_item_id !== $l->position->lot->item_id) {
                G::fail('Input/output identity does not match this specification.');
            }
            $totals[$id] = bcadd($totals[$id] ?? '0', $l->quantity, 6);
        }
        foreach ($h->spec->lines as $line) {
            if (bccomp($totals[$line->id] ?? '0', bcmul($total, $line->quantity_per_pack, 6), 6)) {
                G::fail('Every component must reconcile exactly to PACK quantity; implicit loss or SUIT conversion is not allowed.');
            }
        }
        if ($pack) {
            $left = '1.0000000000';
            foreach ($h->outputs as $i => $o) {
                if ($o->position->lot->item_id !== $h->spec->packed_item_id) {
                    G::fail('Output must use the packed SKU.');
                }
                $fraction = $i === $h->outputs->count() - 1 ? $left : bcdiv($o->quantity, $total, 10);
                $left = bcsub($left, $fraction, 10);
                if (bccomp($fraction, '0', 10) <= 0) {
                    G::fail('Output quantity share is below supported allocation precision.');
                }
                if ($prepare) {
                    $o->update(['cost_allocation_fraction' => $fraction]);
                } elseif (bccomp($fraction, $o->cost_allocation_fraction, 10)) {
                    G::fail('Packed lot cost shares must follow actual pack quantities.');
                }
                if (bccomp($o->packages->reduce(fn ($s, $p) => bcadd($s, $p->quantity, 6), '0'), $o->quantity, 6)) {
                    G::fail('Package container quantities must cover each output completely.');
                }
            }
        } else {
            if (mb_strlen(trim($h->remarks ?? '')) < 5) {
                G::fail('Unpacking requires recovery/inspection evidence in remarks.');
            }
            if (bccomp($h->outputs->reduce(fn ($s, $o) => bcadd($s, $o->cost_allocation_fraction, 10), '0'), '1', 10)) {
                G::fail('Recovered output cost allocation fractions must total one.');
            }
            foreach ($h->materials as $m) {
                if ($m->position->lot->item_id !== $h->spec->packed_item_id || $m->product_pack_spec_line_id) {
                    G::fail('Unpacking source must be this PACK SKU.');
                }
            }
            if ($h->materials->contains(fn ($m) => $m->position->lot->quality_status !== 'released') && $h->outputs->contains(fn ($o) => $o->position->lot->quality_status === 'released')) {
                G::fail('Held/rejected source recovery must remain pending/held/rejected until separately inspected.');
            }
        }
    }

    private function contents(PackingOrder $h): void
    {
        if ($h->operation !== 'pack') {
            return;
        }
        foreach ($h->spec->lines as $line) {
            $sources = $h->materials->where('product_pack_spec_line_id', $line->id)->values();
            $remaining = $sources->mapWithKeys(fn ($m) => [$m->id => $m->quantity])->all();
            foreach ($h->outputs as $out) {
                foreach ($out->packages as $package) {
                    $need = bcmul($package->quantity, $line->quantity_per_pack, 6);
                    foreach ($sources as $m) {
                        if (bccomp($need, '0', 6) === 0) {
                            break;
                        }$take = bccomp($need, $remaining[$m->id], 6) > 0 ? $remaining[$m->id] : $need;
                        if (bccomp($take, '0', 6) <= 0) {
                            continue;
                        }
                        PackageContent::create(['package_id' => $package->id, 'packing_material_line_id' => $m->id, 'quantity' => $take]);
                        $need = bcsub($need, $take, 6);
                        $remaining[$m->id] = bcsub($remaining[$m->id], $take, 6);
                    }if (bccomp($need, '0', 6)) {
                        G::fail('Package content quantities cannot be reconciled.');
                    }
                }
            }
        }
    }

    private function validateContents(PackingOrder $h): void
    {
        $used = [];
        foreach ($h->outputs as $out) {
            foreach ($out->packages as $package) {
                if ($h->operation !== 'pack') {
                    G::fail('Recovered component output cannot contain package records.');
                }
                $per = [];
                foreach ($package->contents as $content) {
                    $m = $h->materials->firstWhere('id', $content->packing_material_line_id);
                    if (! $m) {
                        G::fail('Package provenance must reference an input in the same packing document.');
                    }
                    PackSpecificationService::precision($m->position->lot->item, $content->quantity);
                    $used[$m->id] = bcadd($used[$m->id] ?? '0', $content->quantity, 6);
                    $per[$m->product_pack_spec_line_id] = bcadd($per[$m->product_pack_spec_line_id] ?? '0', $content->quantity, 6);
                }
                foreach ($h->spec->lines as $line) {
                    if (bccomp($per[$line->id] ?? '0', bcmul($package->quantity, $line->quantity_per_pack, 6), 6)) {
                        G::fail('Each container must contain exactly its specification contents.');
                    }
                }
            }
        }
        if ($h->operation === 'pack') {
            foreach ($h->materials as $m) {
                if (bccomp($used[$m->id] ?? '0', $m->quantity, 6)) {
                    G::fail('Package contents must account for every consumed input exactly once.');
                }
            }
        }
    }

    private function unpackOrigin(int $layer, int $spec): void
    {
        $front = [$layer];
        $seen = [];
        $found = false;
        while ($front) {
            $seen = array_unique([...$seen, ...$front]);
            if (count($seen) > 5000) {
                G::fail('Pack origin exceeds supported trace size.');
            }$next = [];
            foreach (StockCostLayer::whereIn('id', $front)->get() as $l) {
                $out = PackingOutputLine::with('order')->where('stock_movement_id', $l->receipt_stock_movement_id)->first();
                if ($out && $out->order->operation === 'pack') {
                    if ($out->order->product_pack_spec_id !== $spec) {
                        G::fail('Unpacking must use the original packing specification version.');
                    }$found = true;

                    continue;
                }
                $links = InventoryCostLink::where('destination_stock_cost_layer_id', $l->id)->pluck('source_layer_allocation_id');
                $parents = InventoryLayerAllocation::whereIn('id', $links)->pluck('stock_cost_layer_id')->all();
                if (! $parents) {
                    G::fail('A PACK opening without proven packing contents cannot be unpacked.');
                }$next = [...$next, ...$parents];
            }$front = array_values(array_diff($next, $seen));
        }if (! $found) {
            G::fail('Original pack conversion is required for unpacking.');
        }
    }

    public function confirm(int $actor, array $input): Document
    {
        return DB::transaction(function () use ($actor, $input) {
            G::company();
            G::actor($actor, 'finished-goods.confirm');
            $d = $this->save($actor, $input);

            return $this->post($actor, $d->id, (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'textile:packing:'.$input['idempotency_key']), $d->lock_version);
        }, 3);
    }

    public function post(int $actor, int $id, string $key, int $version): Document
    {
        return DB::transaction(function () use ($actor, $id, $key, $version) {
            G::company();
            G::actor($actor, 'finished-goods.confirm');
            validator(['key' => $key], ['key' => 'required|uuid'])->validate();
            $d = Document::with('type')->lockForUpdate()->findOrFail($id);
            $this->kind($d);
            if ($d->status === 'posted') {
                if ($d->posting_key === $key && hash_equals($d->getRawOriginal('payload_hash'), $this->hash($d))) {
                    return $d;
                }G::fail('This packing document is already confirmed under another request/payload.');
            }
            if ($d->status !== 'draft' || $d->lock_version !== $version) {
                G::fail('Only an unchanged saved document can be confirmed.');
            }
            if (Document::where('posting_key', $key)->exists()) {
                G::fail('This posting key is already used.');
            }
            [$year,$period] = $this->docs->calendar($d->document_date->format('Y-m-d'));
            if ($d->financial_year_id !== $year->id || $d->accounting_period_id !== $period->id) {
                G::fail('The calendar changed; recreate this saved entry.');
            }
            $h = $this->header($d);
            $this->reconcile($h);
            $this->validateContents($h);
            $inputs = [];
            $total = '0.0000';
            $positions = [];
            foreach ($h->materials as $m) {
                $p = $this->stock->position($m->stock_position_id, $d->document_date->format('Y-m-d'), $h->operation === 'pack');
                if ($p->location->location_type !== 'internal') {
                    G::fail('Packing inputs must remain at internal company storage.');
                }
                $qty = $this->stock->quantity($p, $m->quantity);
                if (bccomp($qty, $this->stock->available($p->id), 6) > 0) {
                    G::fail('Insufficient unreserved stock for packing.');
                }
                $fifo = $this->stock->plan($p->id, $qty, $d->document_date->format('Y-m-d'));
                if ($h->operation === 'unpack') {
                    foreach ($fifo as [$layer,$take]) {
                        $this->unpackOrigin($layer->id, $h->product_pack_spec_id);
                    }
                }
                $movement = $this->stock->movement($d, $p->id, D::negative($qty), 'packing');
                foreach ($fifo as [$layer,$take]) {
                    [$allocation,$value] = $this->stock->issue($d, $movement, $layer, $take);
                    $inputs[] = [$allocation, $value];
                    $total = bcadd($total, $value, 4);
                }
                $m->update(['stock_movement_id' => $movement->id]);
                $positions[] = $p->id;
            }
            $left = $total;
            $targets = [];
            $positive = $h->outputs->filter(fn ($o) => bccomp($o->cost_allocation_fraction, '0', 10) > 0)->values();
            foreach ($h->outputs as $o) {
                $p = $this->stock->position($o->stock_position_id, $d->document_date->format('Y-m-d'));
                if (! in_array($p->location->location_type, ['internal', 'quarantine'], true)) {
                    G::fail('Output must use company receiving storage.');
                }$q = $this->stock->quantity($p, $o->quantity);
                $amount = bccomp($o->cost_allocation_fraction, '0', 10) > 0 ? ($positive->last()->id === $o->id ? $left : D::round(bcmul($total, $o->cost_allocation_fraction, 14))) : '0.0000';
                if (bccomp($amount, $left, 4) > 0) {
                    $amount = $left;
                }$left = bcsub($left, $amount, 4);
                $movement = $this->stock->movement($d, $p->id, $q, 'packing');
                $layer = $this->stock->receipt($d, $movement, $q, $amount);
                $targets[$o->id] = ['layer' => $layer, 'amount' => $amount];
                $o->update(['stock_movement_id' => $movement->id]);
                $positions[] = $p->id;
                foreach ($o->packages as $package) {
                    $package->update(['sealed_at' => now()]);
                }
            }
            foreach ($inputs as [$allocation,$amount]) {
                $this->distribution->distribute($d, $allocation, $amount, '0.0000', $targets);
            }
            foreach (array_unique($positions) as $p) {
                $this->stock->refresh($p);
            }$this->stock->validateRolls($positions);
            $this->docs->finish($d, $actor, $key, $this->hash($d));

            return $d->fresh();
        }, 3);
    }

    private function hash(Document $d): string
    {
        $h = $this->header($d);
        $data = ['document' => $d->only(['document_type_id', 'document_date', 'financial_year_id', 'accounting_period_id']), 'header' => $h->only(['product_pack_spec_id', 'operation', 'remarks'])];
        foreach (['materials', 'outputs'] as $section) {
            $data[$section] = $h->$section->map(fn ($l) => array_diff_key($l->getAttributes(), array_flip(['created_at', 'updated_at'])))->all();
        }
        $data['packages'] = $h->outputs->flatMap(fn ($o) => $o->packages)->map(fn ($p) => $p->only(['id', 'packing_output_line_id', 'package_code', 'quantity', 'gross_weight_kg', 'sealed_at']) + ['contents' => $p->contents->map(fn ($c) => $c->only(['id', 'packing_material_line_id', 'quantity']))->all()])->all();

        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR), true);
    }

    public function cancel(int $actor, int $id, int $version, string $reason): Document
    {
        return DB::transaction(function () use ($actor, $id, $version, $reason) {
            G::company();
            G::actor($actor, 'finished-goods.manage');
            $d = Document::with('type')->lockForUpdate()->findOrFail($id);
            $this->kind($d);
            if ($d->status !== 'draft' || $d->lock_version !== $version) {
                G::fail('Only unchanged saved packing can be cancelled.');
            }validator(['reason' => $reason], ['reason' => 'required|string|min:5|max:500'])->validate();
            $d->update(['status' => 'cancelled', 'reason' => $reason, 'lock_version' => $version + 1]);
            $this->docs->event($d, 'cancelled', $actor, (string) Uuid::uuid4(), 'draft', 'cancelled', $reason);

            return $d;
        }, 3);
    }
}
