<?php

namespace App\Modules\Production\Services;

use App\Models\AuditEvent;
use App\Models\BillOfMaterial;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\FabricRoll;
use App\Models\Item;
use App\Models\JournalLine;
use App\Models\ManufacturingBatch;
use App\Models\ManufacturingBatchMaterial;
use App\Models\ProductionCostAllocation;
use App\Models\ProductionMaterialLine;
use App\Models\ProductionOutputLine;
use App\Models\ProductionReport;
use App\Models\ProductionWastageLine;
use App\Models\StockBalance;
use App\Models\StockLot;
use App\Models\StockPosition;
use App\Models\WarehouseLocation;
use App\Modules\Accounting\Services\SourceJournal;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Inventory\Services\InventoryGuard as Guard;
use App\Modules\Inventory\Services\StockCostDistribution;
use App\Modules\Inventory\Services\StockLedger;
use App\Modules\Inventory\Support\Decimal as D;
use App\Modules\Purchasing\Services\PurchaseDocuments;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

class ProductionService
{
    public function __construct(private StockLedger $stock, private PurchaseDocuments $documents, private ProductionProgress $progress, private SourceJournal $journals, private AuditLogger $audit) {}

    public function kind(Document $doc): string
    {
        abort_unless($doc->type->module_code === 'production' && in_array($doc->type->code, ['production_batch', 'production_report'], true), 404);

        return $doc->type->code === 'production_batch' ? 'batch' : 'report';
    }

    public function header(Document $doc): mixed
    {
        return $this->kind($doc) === 'batch' ? ManufacturingBatch::with('lines', 'bom.variant.item', 'bom.variant.design', 'bom.variant.color')->findOrFail($doc->id) : ProductionReport::with('materials.wastage', 'outputs', 'wastage', 'costs')->findOrFail($doc->id);
    }

    public function openBatch(int $id): ManufacturingBatch
    {
        $batch = ManufacturingBatch::with('document', 'bom.variant.item.baseUnit', 'lines.item.baseUnit')->lockForUpdate()->findOrFail($id);
        if ($batch->document->status !== 'posted' || $this->progress->final($id)) {
            Guard::fail('Choose a confirmed batch that has not been completed.');
        }

        return $batch;
    }

    public function save(int $actor, array $input): Document
    {
        return DB::transaction(function () use ($actor, $input) {
            Guard::company();
            Guard::actor($actor, 'production.manage');
            $d = validator($input, ['kind' => 'required|in:batch,report', 'document_date' => 'required|date_format:Y-m-d', 'idempotency_key' => 'required|uuid', 'bill_of_material_id' => 'required_if:kind,batch|nullable|integer|exists:bills_of_materials,id', 'planned_output_quantity' => 'required_if:kind,batch|nullable|numeric|gt:0', 'planned_start_on' => 'nullable|date_format:Y-m-d', 'planned_finish_on' => 'nullable|date_format:Y-m-d|after_or_equal:planned_start_on', 'instructions' => 'nullable|string|max:2000', 'manufacturing_batch_document_id' => 'required_if:kind,report|nullable|integer|exists:manufacturing_batches,document_id', 'is_final' => 'sometimes|boolean', 'report_note' => 'nullable|string|max:1000', 'materials' => 'sometimes|array|max:100', 'materials.*' => 'array', 'outputs' => 'sometimes|array|max:100', 'outputs.*' => 'array', 'wastage' => 'sometimes|array|max:100', 'wastage.*' => 'array', 'costs' => 'sometimes|array|max:100', 'costs.*' => 'array'])->validate();
            foreach (['materials', 'outputs', 'wastage', 'costs'] as $key) {
                if (isset($d[$key])) {
                    $d[$key] = array_values($d[$key]);
                }
            }
            $hash = hash('sha256', json_encode($d, JSON_THROW_ON_ERROR));
            if ($existing = Document::where('idempotency_key', $d['idempotency_key'])->first()) {
                $this->kind($existing);
                $audit = AuditEvent::where('document_id', $existing->id)->where('action', 'production_prepared')->first();
                if (($audit?->after_json['input_hash'] ?? null) !== $hash) {
                    Guard::fail('This request key was already used for different production details.');
                }

                return $existing;
            }
            [$year,$period] = $this->documents->calendar($d['document_date']);
            $type = DocumentType::where('code', 'production_'.$d['kind'])->where('is_active', true)->firstOrFail();
            $doc = Document::create(['document_type_id' => $type->id, 'document_date' => $d['document_date'], 'financial_year_id' => $year->id, 'accounting_period_id' => $period->id, 'status' => 'draft', 'created_by' => $actor, 'idempotency_key' => $d['idempotency_key']]);
            if ($d['kind'] === 'batch') {
                $this->prepareBatch($doc, $d);
            } else {
                $this->prepareReport($actor, $doc, $d);
            }
            $this->audit->record('documents', $doc->id, 'production_prepared', null, ['input_hash' => $hash, 'input' => $d], $actor, $doc->id);
            $this->documents->event($doc, 'created', $actor, $d['idempotency_key'], null, 'draft');

            return $doc->fresh();
        }, 3);
    }

    private function prepareBatch(Document $doc, array $d): void
    {
        $bom = BillOfMaterial::with('lines.item.baseUnit', 'variant.item.baseUnit', 'variant.design', 'variant.color')->lockForUpdate()->findOrFail($d['bill_of_material_id']);
        $variant = $bom->variant;
        if ($bom->state !== 'published' || ! $bom->is_active || $bom->lines->isEmpty() || ! $variant->is_active || ! $variant->item->is_active || ! $variant->design->is_active || ! $variant->color->is_active) {
            Guard::fail('Choose an active published BOM and finished variant.');
        }
        $qty = D::input($d['planned_output_quantity']);
        $this->precision($variant->item, $qty);
        ManufacturingBatch::create(['document_id' => $doc->id, 'bill_of_material_id' => $bom->id, 'planned_output_quantity' => $qty, 'planned_start_on' => $d['planned_start_on'] ?? null, 'planned_finish_on' => $d['planned_finish_on'] ?? null, 'instructions' => $d['instructions'] ?? null]);
        foreach ($bom->lines as $i => $line) {
            if (! $line->item->is_active || ! $line->item->is_stocked || ! $line->item->baseUnit->is_active) {
                Guard::fail('BOM components and their units must remain active.');
            }
            $net = BomService::ceilQuantity(bcdiv(bcmul($line->net_quantity, $qty, 16), $bom->output_quantity, 16), $line->item->baseUnit->decimal_places);
            $gross = BomService::ceilQuantity(bcdiv($net, bcsub('1', $line->planned_wastage_fraction, 10), 16), $line->item->baseUnit->decimal_places);
            ManufacturingBatchMaterial::create(['document_id' => $doc->id, 'line_no' => $i + 1, 'bill_of_material_line_id' => $line->id, 'component_item_id' => $line->component_item_id, 'planned_net_quantity' => $net, 'planned_gross_quantity' => $gross]);
        }
    }

    private function prepareReport(int $actor, Document $doc, array $d): void
    {
        $batch = $this->openBatch($d['manufacturing_batch_document_id']);
        if ($doc->document_date->lt($batch->document->document_date)) {
            Guard::fail('Production cannot predate its batch.');
        }
        ProductionReport::create(['document_id' => $doc->id, 'manufacturing_batch_document_id' => $batch->document_id, 'is_final' => $d['is_final'] ?? false, 'report_note' => $d['report_note'] ?? null]);
        if (empty($d['materials']) || empty($d['outputs'])) {
            if (empty($d['is_final']) || ! empty($d['materials']) || ! empty($d['outputs']) || ! empty($d['wastage']) || ! empty($d['costs'])) {
                Guard::fail('A conversion requires actual materials and output. Only a fully accounted batch may use a completion-only report.');
            }
            $this->validateCapacity($actor, $batch, $doc->id);
            $this->assertCompletion($batch, $doc->id);

            return;
        }
        $sources = [];
        foreach ($d['materials'] as $i => $raw) {
            $r = validator($raw, ['manufacturing_batch_material_id' => 'nullable|integer', 'stock_position_id' => 'required|integer|exists:stock_positions,id', 'quantity' => 'required|numeric|gt:0', 'extra_material_reason' => 'nullable|string|min:5|max:500'])->validate();
            $position = $this->stock->position($r['stock_position_id'], $doc->document_date->format('Y-m-d'), true);
            if ($position->location->location_type !== 'internal') {
                Guard::fail('Production consumes released material at internal company storage, not contractor custody.');
            }
            $qty = $this->stock->quantity($position, $r['quantity']);
            $plan = empty($r['manufacturing_batch_material_id']) ? null : $batch->lines->firstWhere('id', (int) $r['manufacturing_batch_material_id']);
            if (! empty($r['manufacturing_batch_material_id']) && ! $plan) {
                Guard::fail('Material plan line belongs to another batch.');
            }
            if (! $plan || $plan->component_item_id !== $position->lot->item_id) {
                if (blank($r['extra_material_reason'] ?? null)) {
                    Guard::fail('Extra, intermediate or substituted input needs a reason.');
                }
                Guard::actor($actor, 'production.materials.override');
                if ($plan && $plan->item->base_unit_id !== $position->lot->item->base_unit_id) {
                    Guard::fail('A substitution must use the same physical base unit as its planned component.');
                }
            }
            $sources[$position->id] = bcadd($sources[$position->id] ?? '0', $qty, 6);
            if (bccomp($sources[$position->id], $this->stock->available($position->id), 6) > 0) {
                Guard::fail('Actual consumption exceeds available unreserved stock.');
            }
            $this->progress->assertWipOrigin(array_map(fn ($entry) => $entry[0]->id, $this->stock->plan($position->id, $qty, $doc->document_date->format('Y-m-d'))), $batch->document_id, $plan?->id);
            ProductionMaterialLine::create(['document_id' => $doc->id, 'line_no' => $i + 1, 'manufacturing_batch_material_id' => $plan?->id, 'stock_position_id' => $position->id, 'quantity' => $qty, 'extra_material_reason' => $r['extra_material_reason'] ?? null]);
        }
        $fraction = '0';
        foreach ($d['outputs'] as $i => $raw) {
            $r = validator($raw, ['item_id' => 'required|integer|exists:items,id', 'warehouse_location_id' => 'required|integer|exists:warehouse_locations,id', 'lot_number' => 'required|string|max:50', 'roll_number' => 'nullable|string|max:60', 'output_kind' => 'required|in:finished,intermediate,byproduct', 'quality_status' => 'required|in:pending,released,held,rejected', 'quantity' => 'required|numeric|gt:0', 'cost_allocation_fraction' => 'required|numeric|min:0|max:1'])->validate();
            $item = Item::with('baseUnit')->findOrFail($r['item_id']);
            $qty = D::input($r['quantity']);
            $this->precision($item, $qty);
            if (! $item->is_stocked || ! $item->is_active || ! $item->baseUnit->is_active) {
                Guard::fail('Output requires an active stocked item and unit.');
            }
            if ($r['output_kind'] === 'finished' && $item->id !== $batch->bom->variant->item_id) {
                Guard::fail('Finished output must match the batch design/color SKU.');
            }
            if ($r['output_kind'] === 'intermediate' && $item->item_type === 'finished') {
                Guard::fail('Use a material/processed-fabric item for intermediate WIP, not a complete finished SKU.');
            }
            if ($r['output_kind'] === 'byproduct' && $item->item_type !== 'scrap') {
                Guard::fail('Recoverable by-products use a scrap item.');
            }
            if ($r['quality_status'] === 'rejected' && blank($d['report_note'] ?? null)) {
                Guard::fail('Explain retained rejected output in the report note.');
            }
            $f = D::input($r['cost_allocation_fraction'], 10);
            $fraction = bcadd($fraction, $f, 10);
            $position = $this->outputPosition($doc, $item, $r);
            ProductionOutputLine::create(['document_id' => $doc->id, 'line_no' => $i + 1, 'stock_position_id' => $position->id, 'output_kind' => $r['output_kind'], 'quantity' => $qty, 'cost_allocation_fraction' => $f]);
        }
        if (bccomp($fraction, '1', 10)) {
            Guard::fail('Output cost allocation fractions must total exactly 1.');
        }
        $materials = ProductionMaterialLine::where('document_id', $doc->id)->orderBy('line_no')->get();
        $wasteQty = [];
        foreach ($d['wastage'] ?? [] as $i => $raw) {
            $r = validator($raw, ['material_index' => 'required|integer|min:0', 'waste_class' => 'required|in:normal,abnormal', 'quantity' => 'required|numeric|gt:0', 'reason' => 'required|string|min:5|max:500'])->validate();
            $material = $materials->get($r['material_index']);
            if (! $material) {
                Guard::fail('Wastage must reference an actual input line in this report.');
            }
            $qty = D::input($r['quantity']);
            $this->precision($material->position->lot->item, $qty);
            $wasteQty[$material->id] = bcadd($wasteQty[$material->id] ?? '0', $qty, 6);
            if (bccomp($wasteQty[$material->id], $material->quantity, 6) > 0) {
                Guard::fail('Wastage is a subset of the consumed material; it cannot exceed it.');
            }
            ProductionWastageLine::create(['document_id' => $doc->id, 'line_no' => $i + 1, 'production_material_line_id' => $material->id, 'waste_class' => $r['waste_class'], 'quantity' => $qty, 'expense_account_id' => $r['waste_class'] === 'abnormal' ? $this->documents->account('abnormal_production_loss') : null, 'expense_amount_pkr' => 0, 'reason' => $r['reason']]);
        }
        $outputs = ProductionOutputLine::where('document_id', $doc->id)->orderBy('line_no')->get();
        foreach ($d['costs'] ?? [] as $i => $raw) {
            Guard::actor($actor, 'production.costs.allocate');
            $r = validator($raw, ['source_journal_line_id' => 'required|integer|exists:journal_lines,id', 'output_index' => 'required|integer|min:0', 'cost_category' => 'required|in:direct_labor,overhead,other_conversion', 'amount_pkr' => 'required|numeric|gt:0'])->validate();
            $out = $outputs->get($r['output_index']);
            if (! $out) {
                Guard::fail('Direct cost must reference an output in this report.');
            }
            $source = $this->eligibleCost($doc, $r['source_journal_line_id'], $r['cost_category']);
            ProductionCostAllocation::create(['document_id' => $doc->id, 'line_no' => $i + 1, 'source_journal_line_id' => $source->id, 'production_output_line_id' => $out->id, 'cost_category' => $r['cost_category'], 'amount_pkr' => D::input($r['amount_pkr'], 4), 'absorption_account_id' => $source->ledger_account_id]);
        }
        $this->validatePhysicalUnits($batch, $doc->id);
        $this->validateCapacity($actor, $batch, $doc->id);
    }

    private function precision(Item $item, string $qty): void
    {
        if (bccomp($qty, '0', 6) <= 0 || bccomp($qty, '100000000000000', 6) >= 0 || bccomp($qty, bcadd($qty, '0', $item->baseUnit->decimal_places), 6)) {
            Guard::fail('Quantity must be positive and fit the item base-unit precision.');
        }
    }

    private function outputPosition(Document $doc, Item $item, array $r): StockPosition
    {
        $location = WarehouseLocation::with('warehouse')->findOrFail($r['warehouse_location_id']);
        if (! $location->is_active || ! $location->warehouse->is_active || ! in_array($location->location_type, ['internal', 'quarantine'])) {
            Guard::fail('Receive production at active internal/quarantine company storage.');
        }
        if (StockLot::where('lot_number', $r['lot_number'])->exists()) {
            Guard::fail('Give each output line a new unique lot number.');
        }
        $lot = StockLot::create(['item_id' => $item->id, 'lot_number' => $r['lot_number'], 'quality_status' => $r['quality_status'], 'origin_document_id' => $doc->id, 'manufactured_on' => $doc->document_date]);
        $roll = null;
        if ($item->track_rolls) {
            if (blank($r['roll_number'] ?? null) || FabricRoll::where('roll_number', $r['roll_number'])->exists()) {
                Guard::fail('Identify each new output roll with a unique number.');
            }$roll = FabricRoll::create(['stock_lot_id' => $lot->id, 'roll_number' => $r['roll_number']]);
        } elseif (filled($r['roll_number'] ?? null)) {
            Guard::fail('This output item does not track rolls.');
        }
        $position = StockPosition::create(['stock_lot_id' => $lot->id, 'warehouse_location_id' => $location->id, 'fabric_roll_id' => $roll?->id]);
        StockBalance::create(['stock_position_id' => $position->id, 'on_hand_quantity' => 0, 'reserved_quantity' => 0]);

        return $position;
    }

    private function validatePhysicalUnits(ManufacturingBatch $batch, int $report): void
    {
        $h = ProductionReport::with('materials.position.lot.item.baseUnit', 'materials.wastage', 'outputs.position.lot.item.baseUnit')->findOrFail($report);
        $available = [];
        $recoverable = [];
        $finished = '0';
        foreach ($h->materials as $m) {
            $unit = $m->position->lot->item->base_unit_id;
            $net = bcsub($m->quantity, $m->wastage->reduce(fn ($sum, $w) => bcadd($sum, $w->quantity, 6), '0'), 6);
            $available[$unit] = bcadd($available[$unit] ?? '0', $net, 6);
        }
        foreach ($h->outputs as $o) {
            if ($o->output_kind === 'finished') {
                $finished = bcadd($finished, $o->quantity, 6);

                continue;
            }
            $unit = $o->position->lot->item->base_unit_id;
            if (! array_key_exists($unit, $available)) {
                if ($o->output_kind === 'intermediate') {
                    Guard::fail('Intermediate WIP must retain a physical input base unit. Cross-unit product conversion belongs to the finished BOM.');
                }
                if (blank($h->report_note)) {
                    Guard::fail('A by-product measured in a different unit requires measured-yield evidence in report notes.');
                }

                continue;
            }
            $recoverable[$unit] = bcadd($recoverable[$unit] ?? '0', $o->quantity, 6);
        }
        // If a report both completes suits and leaves same-unit intermediate/scrap,
        // reserve the BOM net requirement before comparing remaining physical output.
        foreach ($batch->lines as $plan) {
            $unit = $plan->item->base_unit_id;
            if (isset($available[$unit])) {
                $available[$unit] = bcsub($available[$unit], bcdiv(bcmul($plan->planned_net_quantity, $finished, 16), $batch->planned_output_quantity, 6), 6);
            }
        }
        foreach ($recoverable as $unit => $qty) {
            if (bccomp($finished, '0', 6) === 0 && bccomp($qty, $available[$unit] ?? '0', 6) !== 0) {
                Guard::fail('Intermediate output must reconcile to physical net input; record measured wastage explicitly.');
            }
            if (bccomp($qty, $available[$unit] ?? '0', 6) > 0) {
                Guard::fail('Intermediate/by-product output exceeds the physical net input left after finished production.');
            }
        }
    }

    private function validateCapacity(int $actor, ManufacturingBatch $batch, int $report): void
    {
        $state = $this->progress->summary($batch, $report);
        if (bccomp($state['accounted'], $batch->planned_output_quantity, 6) > 0) {
            Guard::fail('Finished and retained rejected output exceed the batch plan.');
        }
        foreach ($state['materials'] as $material) {
            $plan = $material['plan'];
            $required = BomService::ceilQuantity(bcdiv(bcmul($plan->planned_net_quantity, $state['accounted'], 16), $batch->planned_output_quantity, 16), $plan->item->baseUnit->decimal_places);
            if (bccomp($material['net'], $required, 6) < 0) {
                Guard::fail('Finished quantity is not backed by the BOM net requirement for '.$plan->item->sku.'.');
            }
            if (bccomp($material['gross'], $plan->planned_gross_quantity, 6) > 0) {
                Guard::actor($actor, 'production.materials.override');
                foreach (ProductionMaterialLine::where('document_id', $report)->where('manufacturing_batch_material_id', $plan->id)->get() as $line) {
                    if (blank($line->extra_material_reason)) {
                        Guard::fail('Explain actual consumption above the planned gross requirement.');
                    }
                }
            }
        }
    }

    private function eligibleCost(Document $doc, int $id, string $category): JournalLine
    {
        $source = JournalLine::with('document', 'account')->lockForUpdate()->findOrFail($id);
        $purpose = ['direct_labor' => 'production_labor', 'overhead' => 'production_overhead', 'other_conversion' => 'production_conversion'][$category];
        $account = $this->documents->account($purpose);
        if ($source->journal_entry_id === $doc->id || $source->document->status !== 'posted' || $source->document->document_date->gt($doc->document_date) || bccomp($source->debit, '0', 4) <= 0 || $source->ledger_account_id !== $account || $source->account->account_type !== 'expense') {
            Guard::fail('Use an eligible posted actual debit from the configured production cost account.');
        }

        return $source;
    }

    public function confirm(int $actor, array $input): Document
    {
        return DB::transaction(function () use ($actor, $input) {
            Guard::company();
            Guard::actor($actor, 'production.confirm');
            $doc = $this->save($actor, $input);

            return $this->post($actor, $doc->id, (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'textile:production:confirm:'.$input['idempotency_key']), $doc->lock_version);
        }, 3);
    }

    public function post(int $actor, int $id, string $key, int $version): Document
    {
        return DB::transaction(function () use ($actor, $id, $key, $version) {
            Guard::company();
            Guard::actor($actor, 'production.confirm');
            validator(['key' => $key], ['key' => 'required|uuid'])->validate();
            $doc = Document::with('type')->lockForUpdate()->findOrFail($id);
            $kind = $this->kind($doc);
            if ($doc->status === 'posted') {
                if ($doc->posting_key === $key && hash_equals($doc->getRawOriginal('payload_hash'), $this->hash($doc))) {
                    return $doc;
                }
                Guard::fail('This production document is already confirmed under another request or payload.');
            }
            if ($doc->status !== 'draft' || $doc->lock_version !== $version) {
                Guard::fail('This saved entry changed. Reload before confirming.');
            }
            if (Document::where('posting_key', $key)->exists()) {
                Guard::fail('The confirmation key is already used.');
            }
            [$year,$period] = $this->documents->calendar($doc->document_date->format('Y-m-d'));
            if ($year->id !== $doc->financial_year_id || $period->id !== $doc->accounting_period_id) {
                Guard::fail('The accounting calendar changed. Recreate the saved entry.');
            }
            $h = $this->header($doc);
            if ($kind === 'batch') {
                if ($h->bom->state !== 'published' || ! $h->bom->is_active || ! $h->bom->variant->is_active || ! $h->bom->variant->item->is_active || ! $h->bom->variant->design->is_active || ! $h->bom->variant->color->is_active) {
                    Guard::fail('The batch BOM or design/color is no longer active.');
                }
            } else {
                $this->postReport($actor, $doc, $h);
            }
            $this->documents->finish($doc, $actor, $key, $this->hash($doc));

            return $doc->fresh();
        }, 3);
    }

    private function postReport(int $actor, Document $doc, ProductionReport $h): void
    {
        $batch = $this->openBatch($h->manufacturing_batch_document_id);
        if ($doc->document_date->lt($batch->document->document_date)) {
            Guard::fail('Production cannot predate its batch.');
        }
        $this->validatePhysicalUnits($batch, $doc->id);
        $this->validateCapacity($actor, $batch, $doc->id);
        if ($h->materials->isEmpty() || $h->outputs->isEmpty()) {
            if (! $h->is_final || $h->materials->isNotEmpty() || $h->outputs->isNotEmpty() || $h->wastage->isNotEmpty() || $h->costs->isNotEmpty()) {
                Guard::fail('Only a completion-only report may have no conversion lines.');
            }
            $this->assertCompletion($batch, $doc->id);

            return;
        }
        $inputs = [];
        $inputCost = '0.0000';
        $loss = '0.0000';
        $positions = [];
        $used = [];
        foreach ($h->materials as $line) {
            $p = $this->stock->position($line->stock_position_id, $doc->document_date->format('Y-m-d'), true);
            if ($p->location->location_type !== 'internal') {
                Guard::fail('Use company internal storage for actual consumption.');
            }
            $qty = $this->stock->quantity($p, $line->quantity);
            $used[$p->id] = bcadd($used[$p->id] ?? '0', $qty, 6);
            // Prior material lines are already issued, so check this line against current availability.
            if (bccomp($qty, $this->stock->available($p->id), 6) > 0) {
                Guard::fail('Actual consumption now exceeds available stock.');
            }
            $plan = $line->plan;
            if ($plan && $plan->document_id !== $batch->document_id) {
                Guard::fail('The material plan belongs to another batch.');
            }
            $waste = $line->wastage->reduce(fn ($sum, $w) => bcadd($sum, $w->quantity, 6), '0');
            if (bccomp($waste, $qty, 6) > 0) {
                Guard::fail('Wastage exceeds actual consumption.');
            }
            if (! $plan || $plan->component_item_id !== $p->lot->item_id) {
                Guard::actor($actor, 'production.materials.override');
                if (blank($line->extra_material_reason)) {
                    Guard::fail('Extra/substituted material requires authorization and a reason.');
                }
            }
            $fifo = $this->stock->plan($p->id, $qty, $doc->document_date->format('Y-m-d'));
            $this->progress->assertWipOrigin(array_map(fn ($entry) => $entry[0]->id, $fifo), $batch->document_id, $plan?->id);
            $movement = $this->stock->movement($doc, $p->id, D::negative($qty), 'production');
            $allocations = [];
            $cost = '0.0000';
            foreach ($fifo as [$layer,$take]) {
                [$allocation,$value] = $this->stock->issue($doc, $movement, $layer, $take);
                $allocations[] = [$allocation, $value];
                $cost = bcadd($cost, $value, 4);
            }
            $abnormal = $line->wastage->where('waste_class', 'abnormal')->values();
            $abnormalQty = $abnormal->reduce(fn ($sum, $w) => bcadd($sum, $w->quantity, 6), '0');
            $lossCost = D::round(bcdiv(bcmul($cost, $abnormalQty, 16), $qty, 12));
            $remainingLoss = $lossCost;
            $remainingQty = $abnormalQty;
            foreach ($abnormal as $w) {
                if ($w->expense_account_id !== $this->documents->account('abnormal_production_loss')) {
                    Guard::fail('Abnormal loss account changed; recreate this report.');
                }
                $part = bccomp($w->quantity, $remainingQty, 6) === 0 ? $remainingLoss : D::round(bcdiv(bcmul($remainingLoss, $w->quantity, 16), $remainingQty, 12));
                $w->update(['expense_amount_pkr' => $part]);
                $remainingLoss = bcsub($remainingLoss, $part, 4);
                $remainingQty = bcsub($remainingQty, $w->quantity, 6);
            }
            $line->update(['stock_movement_id' => $movement->id]);
            $inputs[] = [$allocations, $cost, $lossCost];
            $inputCost = bcadd($inputCost, $cost, 4);
            $loss = bcadd($loss, $lossCost, 4);
            $positions[] = $p->id;
        }
        $fraction = $h->outputs->reduce(fn ($sum, $o) => bcadd($sum, $o->cost_allocation_fraction, 10), '0');
        if (bccomp($fraction, '1', 10)) {
            Guard::fail('Output cost fractions must total one.');
        }
        $goodCost = bcsub($inputCost, $loss, 4);
        $remaining = $goodCost;
        $outLayers = [];
        $positive = $h->outputs->filter(fn ($o) => bccomp($o->cost_allocation_fraction, '0', 10) > 0)->values();
        foreach ($h->outputs as $out) {
            $p = $this->stock->position($out->stock_position_id, $doc->document_date->format('Y-m-d'));
            if (! in_array($p->location->location_type, ['internal', 'quarantine'])) {
                Guard::fail('Production output must remain at company receiving storage.');
            }
            $qty = $this->stock->quantity($p, $out->quantity);
            $amount = '0.0000';
            if ($out->output_kind === 'finished' && $p->lot->item_id !== $batch->bom->variant->item_id) {
                Guard::fail('Finished output differs from the batch SKU.');
            }
            if (bccomp($out->cost_allocation_fraction, '0', 10) > 0) {
                $amount = $positive->last()->id === $out->id ? $remaining : D::round(bcmul($goodCost, $out->cost_allocation_fraction, 14));
                if (bccomp($amount, $remaining, 4) > 0) {
                    $amount = $remaining;
                }
                $remaining = bcsub($remaining, $amount, 4);
            }
            $movement = $this->stock->movement($doc, $p->id, $qty, 'production');
            $layer = $this->stock->receipt($doc, $movement, $qty, $amount);
            $outLayers[$out->id] = ['layer' => $layer, 'amount' => $amount, 'fraction' => $out->cost_allocation_fraction];
            $out->update(['stock_movement_id' => $movement->id]);
            $positions[] = $p->id;
        }
        // Apportion each source allocation, including loss, without duplicating a stock issue.
        foreach ($inputs as [$allocations,$cost,$lossCost]) {
            $lossLeft = $lossCost;
            $costLeft = $cost;
            foreach ($allocations as [$allocation,$value]) {
                $lost = bccomp($costLeft, '0', 4) === 0 ? '0.0000' : D::round(bcdiv(bcmul($lossLeft, $value, 16), $costLeft, 12));
                if (bccomp($value, $costLeft, 4) === 0) {
                    $lost = $lossLeft;
                }
                $this->costLinks($doc, $allocation, $value, $lost, $outLayers);
                $lossLeft = bcsub($lossLeft, $lost, 4);
                $costLeft = bcsub($costLeft, $value, 4);
            }
        }
        $amounts = [];
        $add = function (int $account, string $value) use (&$amounts) {
            $amounts[$account] = bcadd($amounts[$account] ?? '0', $value, 4);
        };
        $direct = '0.0000';
        $sourceTotals = [];
        foreach ($h->costs as $row) {
            Guard::actor($actor, 'production.costs.allocate');
            $source = $this->eligibleCost($doc, $row->source_journal_line_id, $row->cost_category);
            $sourceTotals[$source->id] = bcadd($sourceTotals[$source->id] ?? '0', $row->amount_pkr, 4);
            $usedCost = (string) ProductionCostAllocation::where('source_journal_line_id', $source->id)->whereHas('report.document', fn ($q) => $q->where('status', 'posted'))->sum('amount_pkr');
            if (bccomp(bcadd($usedCost, $sourceTotals[$source->id], 4), $source->debit, 4) > 0) {
                Guard::fail('These actual source costs have already been absorbed by another production report.');
            }
            if ($row->absorption_account_id !== $source->ledger_account_id) {
                Guard::fail('The absorption account must match the actual source expense.');
            }
            $layer = $outLayers[$row->production_output_line_id]['layer'];
            $allocation = $layer->allocations()->where('quantity_delta', '>', 0)->firstOrFail();
            $this->stock->value($doc, $layer, $allocation, $row->amount_pkr, 'direct_cost');
            $direct = bcadd($direct, $row->amount_pkr, 4);
            $add($source->ledger_account_id, D::negative($row->amount_pkr, 4));
        }
        $add($this->documents->account('inventory'), bcsub($direct, $loss, 4));
        $add($this->documents->account('abnormal_production_loss'), $loss);
        $this->journals->post($doc, $amounts);
        foreach (array_unique($positions) as $id) {
            $this->stock->refresh($id);
        }
        $this->stock->validateRolls($positions);
        if ($h->is_final) {
            $this->assertCompletion($batch, $doc->id);
        }
    }

    private function assertCompletion(ManufacturingBatch $batch, int $report): void
    {
        $state = $this->progress->summary($batch, $report);
        if (bccomp($state['accounted'], $batch->planned_output_quantity, 6) !== 0 || $state['has_wip'] || $state['custody']) {
            Guard::fail('Final completion requires the planned output accounted, no remaining intermediate WIP and no outstanding contractor custody.');
        }
    }

    private function costLinks(Document $doc, mixed $allocation, string $value, string $lost, array &$outputs): void
    {
        app(StockCostDistribution::class)->distribute($doc, $allocation, $value, $lost, $outputs, $this->documents->account('abnormal_production_loss'));
    }

    private function hash(Document $doc): string
    {
        $h = $this->header($doc);
        $data = ['document' => $doc->only(['document_type_id', 'document_date', 'financial_year_id', 'accounting_period_id'])];
        if ($h instanceof ManufacturingBatch) {
            $data['header'] = $h->only(['bill_of_material_id', 'planned_output_quantity', 'planned_start_on', 'planned_finish_on', 'instructions']);
            $data['materials'] = $h->lines->map(fn ($l) => $l->only(['id', 'bill_of_material_line_id', 'component_item_id', 'planned_net_quantity', 'planned_gross_quantity']))->all();
        } else {
            $data['header'] = $h->only(['manufacturing_batch_document_id', 'is_final', 'report_note']);
            foreach (['materials', 'outputs', 'wastage', 'costs'] as $key) {
                $data[$key] = $h->$key->map(fn ($l) => array_diff_key($l->getAttributes(), array_flip(['created_at', 'updated_at'])))->all();
            }
        }

        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR), true);
    }

    public function cancel(int $actor, int $id, int $version, string $reason): Document
    {
        return DB::transaction(function () use ($actor, $id, $version, $reason) {
            Guard::company();
            Guard::actor($actor, 'production.manage');
            $d = Document::with('type')->lockForUpdate()->findOrFail($id);
            $this->kind($d);
            if ($d->status !== 'draft' || $d->lock_version !== $version) {
                Guard::fail('Only an unchanged saved entry can be cancelled.');
            }
            validator(['reason' => $reason], ['reason' => 'required|string|min:5|max:500'])->validate();
            $d->update(['status' => 'cancelled', 'reason' => $reason, 'lock_version' => $version + 1]);
            $this->documents->event($d, 'cancelled', $actor, (string) Uuid::uuid4(), 'draft', 'cancelled', $reason);

            return $d;
        }, 3);
    }
}
