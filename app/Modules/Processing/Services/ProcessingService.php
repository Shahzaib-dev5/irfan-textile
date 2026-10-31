<?php

namespace App\Modules\Processing\Services;

use App\Models\AuditEvent;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\FabricRoll;
use App\Models\FabricRollLineage;
use App\Models\InventoryCostLink;
use App\Models\Item;
use App\Models\LedgerAccount;
use App\Models\PartyRole;
use App\Models\ProcessingDispatch;
use App\Models\ProcessingDispatchLine;
use App\Models\ProcessingJob;
use App\Models\ProcessingJobStep;
use App\Models\ProcessingReceipt;
use App\Models\ProcessingReceiptLine;
use App\Models\ProcessingRouteVersion;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceLine;
use App\Models\PurchaseInvoiceProcessingMatch;
use App\Models\StockBalance;
use App\Models\StockCostLayer;
use App\Models\StockLot;
use App\Models\StockPosition;
use App\Models\Unit;
use App\Models\VendorService;
use App\Models\VendorServiceRate;
use App\Models\WarehouseLocation;
use App\Modules\Accounting\Services\SourceJournal;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Inventory\Services\InventoryGuard as Guard;
use App\Modules\Inventory\Services\StockLedger;
use App\Modules\Inventory\Support\Decimal as D;
use App\Modules\Production\Services\ProductionProgress;
use App\Modules\Production\Services\ProductionService;
use App\Modules\Purchasing\Services\PurchaseDocuments;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

class ProcessingService
{
    private ?int $handoverCustodian = null;

    private array $handoverPositions = [];

    /** Receive at the current contractor and immediately transfer the entire accepted output.
     * The callback constructs the next job/dispatch using this same service instance.
     * No general-purpose contractor stock picking permission is granted.
     */
    public function handover(int $actor, array $input, callable $dispatch): array
    {
        return $this->transaction($actor, 'processing.dispatches.post', function () use ($actor, $input, $dispatch) {
            if ($this->handoverCustodian !== null) {
                Guard::fail('Nested contractor handovers are not supported.');
            }
            try {
                $this->handoverCustodian = (int) $input['vendor_party_role_id'];
                foreach ($input['lines'] as $line) {
                    if ($line['disposition'] !== 'accepted' || $line['quality_status'] !== 'released') {
                        Guard::fail('Direct transfer requires inspected, accepted and released fabric.');
                    }
                }
                $receipt = $this->confirm($actor, $input);
                $lines = ProcessingReceipt::findOrFail($receipt->id)->lines;
                $this->handoverPositions = $lines->pluck('output_stock_position_id')->all();
                $onward = $dispatch($receipt);
                $sent = ProcessingDispatch::findOrFail($onward->id);
                if ($onward->status !== 'posted' || $sent->vendor_party_role_id === $this->handoverCustodian) {
                    Guard::fail('Choose a different next vendor and confirm the onward dispatch.');
                }
                foreach ($lines as $line) {
                    if (bccomp($this->sum($sent->lines->where('source_stock_position_id', $line->output_stock_position_id), 'quantity'), $line->output_quantity, 6)) {
                        Guard::fail('Direct handover must transfer all output from this receipt. Receive a smaller portion for a partial handover.');
                    }
                }

                return [$receipt, $onward];
            } finally {
                $this->handoverCustodian = null;
                $this->handoverPositions = [];
            }
        });
    }

    public const HEADERS = ['job' => ProcessingJob::class, 'dispatch' => ProcessingDispatch::class, 'receipt' => ProcessingReceipt::class, 'bill' => PurchaseInvoice::class];

    public const PERMISSIONS = ['job' => 'processing.jobs.approve', 'dispatch' => 'processing.dispatches.post', 'receipt' => 'processing.receipts.post', 'bill' => 'processing.bills.post'];

    public function __construct(private StockLedger $stock, private PurchaseDocuments $documents, private SourceJournal $journals, private AuditLogger $audit) {}

    private function transaction(int $actor, string $permission, callable $fn): mixed
    {
        return DB::transaction(function () use ($actor, $permission, $fn) {
            Guard::company();
            Guard::actor($actor, $permission);

            return $fn();
        }, 3);
    }

    public function kind(Document $doc): string
    {
        $k = str_replace('processing_', '', $doc->type->code);
        abort_unless($doc->type->module_code === 'processing' && isset(self::HEADERS[$k]), 404);

        return $k;
    }

    public function header(Document $doc): mixed
    {
        $c = self::HEADERS[$this->kind($doc)];

        return $c::with('lines')->findOrFail($doc->id);
    }

    private function find(int $id): Document
    {
        $d = Document::with('type')->lockForUpdate()->findOrFail($id);
        $this->kind($d);

        return $d;
    }

    private function positive(mixed $v): string
    {
        $q = D::input($v);
        if (bccomp($q, '0', 6) <= 0) {
            Guard::fail('Quantity must be greater than zero.');
        }

        return $q;
    }

    private function vendor(int $id): PartyRole
    {
        $r = PartyRole::with(['party', 'type', 'vendorProfile'])->lockForUpdate()->findOrFail($id);
        if (! $r->is_active || ! $r->party->is_active || ! $r->type->is_active || $r->type->code !== 'vendor' || ! $r->vendorProfile) {
            Guard::fail('Choose an active shared Vendor role and profile.');
        }

        return $r;
    }

    private function capability(int $id): VendorService
    {
        $v = VendorService::with('processingService.billingUnit')->lockForUpdate()->findOrFail($id);
        $this->vendor($v->vendor_party_role_id);
        if (! $v->is_active || ! $v->processingService->is_active || ! $v->processingService->billingUnit->is_active) {
            Guard::fail('The vendor capability is inactive.');
        }

        return $v;
    }

    private function rate(VendorService $v, string $date): VendorServiceRate
    {
        $r = VendorServiceRate::where('vendor_service_id', $v->id)->whereDate('effective_from', '<=', $date)->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>', $date))->lockForUpdate()->first();
        if (! $r || $r->currency_code !== 'PKR' || $r->billing_unit_id !== $v->processingService->billing_unit_id) {
            Guard::fail('No compatible published PKR vendor rate covers the rate date.');
        }

        return $r;
    }

    private function quantityPrecision(Item $item, string $qty): void
    {
        if (bccomp($qty, '100000000000000', 6) >= 0) {
            Guard::fail('Calculated quantity exceeds DECIMAL(20,6) precision.');
        }
        if (bccomp($qty, bcadd($qty, '0', $item->baseUnit->decimal_places), 6)) {
            Guard::fail('Quantity exceeds the item base-unit precision.');
        }
    }

    private function money(string $amount): string
    {
        if (bccomp($amount, '0', 4) < 0 || bccomp($amount, '10000000000000000', 4) >= 0) {
            Guard::fail('Processing amount exceeds DECIMAL(20,4) precision.');
        }

        return bcadd($amount, '0', 4);
    }

    private function newPosition(int $lot, int $location, ?int $roll): StockPosition
    {
        $p = StockPosition::firstOrCreate(['stock_lot_id' => $lot, 'warehouse_location_id' => $location, 'fabric_roll_id' => $roll]);
        StockBalance::firstOrCreate(['stock_position_id' => $p->id], ['on_hand_quantity' => 0, 'reserved_quantity' => 0, 'lock_version' => 0]);

        return $p;
    }

    public function save(int $actor, array $input): Document
    {
        return $this->transaction($actor, 'processing.documents.manage', function () use ($actor, $input) {
            $d = validator($input, ['manufacturing_batch_document_id' => 'nullable|integer|exists:manufacturing_batches,document_id', 'kind' => 'required|in:job,dispatch,receipt,bill', 'document_date' => 'required|date_format:Y-m-d', 'idempotency_key' => 'required|uuid', 'processing_route_version_id' => 'required_if:kind,job|nullable|integer', 'planned_input_quantity' => 'required_if:kind,job|nullable|numeric|gt:0', 'required_by' => 'nullable|date_format:Y-m-d', 'instructions' => 'nullable|string|max:2000', 'processing_job_document_id' => 'required_if:kind,dispatch,receipt|nullable|integer', 'vendor_party_role_id' => 'required_unless:kind,job|nullable|integer', 'contractor_reference' => 'nullable|string|max:100', 'remarks' => 'nullable|string|max:1000', 'supplier_invoice_number' => 'required_if:kind,bill|nullable|string|max:100', 'supplier_invoice_date' => 'required_if:kind,bill|nullable|date_format:Y-m-d|before_or_equal:document_date', 'due_on' => 'required_if:kind,bill|nullable|date_format:Y-m-d|after_or_equal:supplier_invoice_date', 'lines' => 'required|array|min:1|max:100', 'lines.*' => 'required|array'])->validate();
            $d['lines'] = array_values($d['lines']);
            $hash = hash('sha256', json_encode($d, JSON_THROW_ON_ERROR));
            $existing = Document::where('idempotency_key', $d['idempotency_key'])->first();
            if ($existing) {
                $this->kind($existing);
                $a = AuditEvent::where('document_id', $existing->id)->where('action', 'processing_draft_created')->first();
                if (($a?->after_json['input_hash'] ?? null) !== $hash) {
                    Guard::fail('Creation request key was already used for different data.');
                }

                return $existing;
            }
            [$year,$period] = $this->documents->calendar($d['document_date']);
            $type = DocumentType::where('code', 'processing_'.$d['kind'])->where('is_active', true)->firstOrFail();
            $doc = Document::create(['document_type_id' => $type->id, 'document_date' => $d['document_date'], 'financial_year_id' => $year->id, 'accounting_period_id' => $period->id, 'created_by' => $actor, 'status' => 'draft', 'idempotency_key' => $d['idempotency_key'], 'external_reference' => $d['contractor_reference'] ?? null]);
            match ($d['kind']) {
                'job' => $this->saveJob($doc, $d, $actor),'dispatch' => $this->saveDispatch($doc, $d),'receipt' => $this->saveReceipt($doc, $d),'bill' => $this->saveBill($doc, $d)
            };
            $this->audit->record('documents', $doc->id, 'processing_draft_created', null, ['input_hash' => $hash, 'input' => $d], $actor, $doc->id);
            $this->documents->event($doc, 'created', $actor, $d['idempotency_key'], null, 'draft');

            return $doc;
        });
    }

    private function saveJob(Document $doc, array $d, int $actor): void
    {
        $route = ProcessingRouteVersion::with('steps')->lockForUpdate()->findOrFail($d['processing_route_version_id']);
        if (! empty($d['manufacturing_batch_document_id'])) {
            Guard::actor($actor, 'production.view');
            $batch = app(ProductionService::class)->openBatch($d['manufacturing_batch_document_id']);
            $allowed = $batch->lines->pluck('component_item_id')->all();
            foreach (app(ProductionProgress::class)->summary($batch)['wip'] as $wip) {
                $allowed[] = $wip['position']->lot->item_id;
            }
            $allowed = array_values(array_unique($allowed));
            $matches = $route->steps->contains(fn ($s) => in_array($s->input_item_id, $allowed, true) || in_array($s->output_item_id, $allowed, true));
            if (! $matches) {
                Guard::fail('The processing route must prepare or use a component of this batch.');
            }
        }

        if ($route->state !== 'published' || ! $route->is_active || $route->steps->isEmpty()) {
            Guard::fail('Select an active published processing route.');
        }
        if (count($d['lines']) !== $route->steps->count()) {
            Guard::fail('Assign exactly one vendor capability to every route stage.');
        }
        $qty = $this->positive($d['planned_input_quantity']);
        ProcessingJob::create(['document_id' => $doc->id, 'manufacturing_batch_document_id' => $d['manufacturing_batch_document_id'] ?? null, 'processing_route_version_id' => $route->id, 'planned_input_quantity' => $qty, 'required_by' => $d['required_by'] ?? null, 'instructions' => $d['instructions'] ?? null]);
        foreach ($route->steps as $i => $s) {
            $line = $d['lines'][$i];
            $v = $this->capability((int) ($line['vendor_service_id'] ?? 0));
            if ($v->processing_service_id !== $s->processing_service_id) {
                Guard::fail('The selected vendor capability must match this route stage.');
            }
            $date = $line['rate_effective_date'] ?? $d['document_date'];
            validator(['date' => $date], ['date' => 'required|date_format:Y-m-d|before_or_equal:'.$d['document_date']])->validate();
            $r = $this->rate($v, $date);
            $rate = isset($line['agreed_unit_rate_pkr']) && $line['agreed_unit_rate_pkr'] !== '' ? D::input($line['agreed_unit_rate_pkr'], 8) : $r->unit_rate;
            if (bccomp($rate, $r->unit_rate, 8)) {
                Guard::actor($actor, 'processing.rates.override');
                validator($line, ['override_reason' => 'required|string|min:10|max:500'])->validate();
                $this->audit->record('documents', $doc->id, 'negotiated_rate_authorized', null, ['stage' => $s->id, 'source_rate_id' => $r->id, 'source_rate' => $r->unit_rate, 'agreed_rate' => $rate, 'reason' => $line['override_reason']], $actor, $doc->id);
            }
            $out = bcmul($qty, $s->planned_yield_ratio, 6);
            if (bccomp($out, '0', 6) <= 0) {
                Guard::fail('Planned output must be positive.');
            }
            $this->quantityPrecision(Item::with('baseUnit')->findOrFail($s->input_item_id), $qty);
            $this->quantityPrecision(Item::with('baseUnit')->findOrFail($s->output_item_id), $out);
            ProcessingJobStep::create(['document_id' => $doc->id, 'line_no' => $i + 1, 'processing_route_step_id' => $s->id, 'processing_service_id' => $s->processing_service_id, 'vendor_service_id' => $v->id, 'vendor_service_rate_id' => $r->id, 'input_item_id' => $s->input_item_id, 'output_item_id' => $s->output_item_id, 'planned_input_quantity' => $qty, 'planned_output_quantity' => $out, 'billing_unit_id' => $r->billing_unit_id, 'agreed_unit_rate_pkr' => $rate, 'rate_effective_date' => $date, 'permitted_normal_loss_fraction' => $s->permitted_normal_loss_fraction]);
            $qty = $out;
        }
    }

    private function execution(Document $doc, array $d, string $kind): mixed
    {
        $job = ProcessingJob::with('document')->findOrFail($d['processing_job_document_id']);
        if ($job->document->status !== 'posted' || $doc->document_date->lt($job->document->document_date)) {
            Guard::fail('Execution needs a posted job dated on or before this document.');
        }
        $this->vendor($d['vendor_party_role_id']);
        $class = $kind === 'dispatch' ? ProcessingDispatch::class : ProcessingReceipt::class;

        return $class::create(['document_id' => $doc->id, 'processing_job_document_id' => $job->document_id, 'vendor_party_role_id' => $d['vendor_party_role_id'], 'contractor_reference' => $d['contractor_reference'] ?? null] + ($kind === 'receipt' ? ['remarks' => $d['remarks'] ?? null] : []));
    }

    private function step(int $id, int $job, int $vendor): ProcessingJobStep
    {
        $s = ProcessingJobStep::with('inputItem.baseUnit', 'outputItem.baseUnit', 'billingUnit')->lockForUpdate()->findOrFail($id);
        $v = $this->capability($s->vendor_service_id);
        if ($s->document_id !== $job || $v->vendor_party_role_id !== $vendor) {
            Guard::fail('Job stage and assigned vendor must match the document.');
        }

        return $s;
    }

    private function location(int $id, ?int $vendor = null): WarehouseLocation
    {
        $l = WarehouseLocation::with('warehouse')->findOrFail($id);
        if (! $l->is_active || ! $l->warehouse->is_active || ($vendor !== null ? ($l->location_type !== 'contractor' || $l->contractor_vendor_party_role_id !== $vendor) : ! in_array($l->location_type, ['internal', 'quarantine']))) {
            Guard::fail('Choose active storage with the correct contractor custodian or an internal receiving location.');
        }

        return $l;
    }

    private function saveDispatch(Document $doc, array $d): void
    {
        $h = $this->execution($doc, $d, 'dispatch');
        $no = 0;
        foreach ($d['lines'] as $l) {
            validator($l, ['processing_job_step_id' => 'required|integer', 'source_stock_position_id' => 'required|integer', 'contractor_location_id' => 'required|integer', 'quantity' => 'required|numeric|gt:0'])->validate();
            $s = $this->step($l['processing_job_step_id'], $h->processing_job_document_id, $h->vendor_party_role_id);
            $p = $this->stock->position($l['source_stock_position_id'], $d['document_date'], true);
            if ($p->lot->item_id !== $s->input_item_id || ($p->location->location_type === 'contractor' && ! in_array($p->id, $this->handoverPositions, true))) {
                Guard::fail('Choose '.$s->inputItem->sku.' from your internal warehouse. Selected stock: '.$p->lot->item->sku.' at '.$p->location->name.'. Contractor-held fabric must be received before dispatching again.');
            }
            $qty = $this->stock->quantity($p, $l['quantity']);
            $location = $this->location($l['contractor_location_id'], $h->vendor_party_role_id);
            $target = $this->newPosition($p->stock_lot_id, $location->id, $p->fabric_roll_id);
            foreach ($this->stock->plan($p->id, $qty, $d['document_date']) as [$layer,$take]) {
                ProcessingDispatchLine::create(['document_id' => $doc->id, 'line_no' => ++$no, 'processing_job_step_id' => $s->id, 'source_stock_position_id' => $p->id, 'contractor_stock_position_id' => $target->id, 'source_stock_cost_layer_id' => $layer->id, 'quantity' => $take]);
            }
        }
    }

    private function saveReceipt(Document $doc, array $d): void
    {
        $h = $this->execution($doc, $d, 'receipt');
        foreach ($d['lines'] as $i => $l) {
            validator($l, ['processing_dispatch_line_id' => 'required|integer', 'disposition' => 'required|in:accepted,rejected', 'source_quantity' => 'required|numeric|gt:0', 'output_quantity' => 'required|numeric|gt:0', 'normal_loss_quantity' => 'nullable|numeric|min:0', 'abnormal_loss_quantity' => 'nullable|numeric|min:0', 'accepted_service_quantity' => 'nullable|numeric|min:0', 'receiving_location_id' => 'required|integer', 'lot_number' => 'required|string|max:80|unique:stock_lots,lot_number', 'roll_number' => 'nullable|string|max:80|unique:fabric_rolls,roll_number', 'quality_status' => 'required|in:pending,released,held,rejected'])->validate();
            $dispatch = ProcessingDispatchLine::with('dispatch.document')->findOrFail($l['processing_dispatch_line_id']);
            if ($dispatch->dispatch->document->status !== 'posted' || $dispatch->dispatch->processing_job_document_id !== $h->processing_job_document_id || $dispatch->dispatch->vendor_party_role_id !== $h->vendor_party_role_id || $doc->document_date->lt($dispatch->dispatch->document->document_date)) {
                Guard::fail('Choose a posted dispatch for this job and vendor dated before receipt.');
            }
            $s = $this->step($dispatch->processing_job_step_id, $h->processing_job_document_id, $h->vendor_party_role_id);
            $item = $l['disposition'] === 'accepted' ? $s->outputItem : $s->inputItem;
            $source = $this->positive($l['source_quantity']);
            $out = $this->positive($l['output_quantity']);
            $normal = D::input($l['normal_loss_quantity'] ?? '0');
            $abnormal = D::input($l['abnormal_loss_quantity'] ?? '0');
            $this->quantityPrecision($s->inputItem, $source);
            $this->quantityPrecision($item, $out);
            $this->quantityPrecision($s->inputItem, $normal);
            $this->quantityPrecision($s->inputItem, $abnormal);
            // Physical output and input use the same base unit. There is no invented meter-to-piece conversion.
            if (bccomp($source, bcadd($out, bcadd($normal, $abnormal, 6), 6), 6)) {
                Guard::fail('Issued quantity closed must equal returned output plus normal and abnormal loss.');
            }
            if (bccomp($normal, bcmul($source, $s->permitted_normal_loss_fraction, 6), 6) > 0) {
                Guard::fail('Normal loss exceeds the approved route allowance; classify excess as abnormal loss.');
            }
            $service = '0.000000';
            if ($l['disposition'] === 'rejected') {
                if (bccomp($normal, '0', 6) || bccomp($abnormal, '0', 6) || bccomp($source, $out, 6) || bccomp((string) ($l['accepted_service_quantity'] ?? 0), '0', 6)) {
                    Guard::fail('Rejected returns preserve the input item and cannot include charges or loss.');
                }
            } else {
                if ($s->billing_unit_id === $item->base_unit_id) {
                    $service = $out;
                    if (isset($l['accepted_service_quantity']) && $l['accepted_service_quantity'] !== '' && bccomp(D::input($l['accepted_service_quantity']), $out, 6)) {
                        Guard::fail('Service quantity must equal the accepted measured meters/base units.');
                    }
                } else {
                    $service = $this->positive($l['accepted_service_quantity'] ?? '0');
                    if (bccomp($service, bcadd($service, '0', $s->billingUnit->decimal_places), 6)) {
                        Guard::fail('Service quantity exceeds billing-unit precision.');
                    }
                    if ($s->billingUnit->dimension === $item->baseUnit->dimension) {
                        $factor = $item->conversions()->where('unit_id', $s->billing_unit_id)->value('factor_to_base');
                        if (! $factor || bccomp(bcmul($service, $factor, 16), $out, 16)) {
                            Guard::fail('Service quantity must reconcile to the configured item conversion.');
                        }
                    } elseif (empty($d['remarks']) || strlen($d['remarks']) < 10) {
                        Guard::fail('Record the inspected piece/set service count evidence in receipt remarks.');
                    }
                }
            }
            $charge = $this->money(D::round(bcmul($service, $s->agreed_unit_rate_pkr, 18)));
            $location = $this->location($l['receiving_location_id'], $this->handoverCustodian);
            $lot = StockLot::create(['item_id' => $item->id, 'lot_number' => $l['lot_number'], 'origin_document_id' => $doc->id, 'quality_status' => $l['quality_status']]);
            $roll = null;
            if ($item->track_rolls) {
                if (empty($l['roll_number'])) {
                    Guard::fail('Give the returned roll a new unique roll number.');
                }
                $roll = FabricRoll::create(['stock_lot_id' => $lot->id, 'roll_number' => $l['roll_number'], 'is_active' => true]);
            }
            $p = $this->newPosition($lot->id, $location->id, $roll?->id);
            ProcessingReceiptLine::create(['document_id' => $doc->id, 'line_no' => $i + 1, 'processing_job_step_id' => $s->id, 'processing_dispatch_line_id' => $dispatch->id, 'disposition' => $l['disposition'], 'source_stock_position_id' => $dispatch->contractor_stock_position_id, 'output_stock_position_id' => $p->id, 'source_quantity' => $source, 'output_quantity' => $out, 'output_source_equivalent_quantity' => $out, 'normal_loss_quantity' => $normal, 'abnormal_loss_quantity' => $abnormal, 'accepted_service_quantity' => $service, 'service_accrual_pkr' => $charge, 'abnormal_loss_cost_pkr' => 0, 'abnormal_loss_account_id' => bccomp($abnormal, '0', 6) > 0 ? $this->documents->account('abnormal_processing_loss') : null]);
        }
    }

    private function duplicateBill(int $role, string $reference, ?int $except = null): void
    {
        $party = PartyRole::findOrFail($role)->party_id;
        if (PurchaseInvoice::where('supplier_invoice_number', $reference)->whereHas('supplier', fn ($q) => $q->where('party_id', $party))->when($except, fn ($q) => $q->where('document_id', '<>', $except))->exists()) {
            Guard::fail('This party invoice reference is already recorded, including drafts or cancelled history.');
        }
    }

    private function saveBill(Document $doc, array $d): void
    {
        $role = $this->vendor($d['vendor_party_role_id']);
        $ref = Str::upper(preg_replace('/\s+/', ' ', trim($d['supplier_invoice_number'])));
        $this->duplicateBill($role->id, $ref);
        $rows = [];
        $total = '0.0000';
        foreach ($d['lines'] as $i => $l) {
            validator($l, ['processing_receipt_line_id' => 'required|integer', 'quantity' => 'required|numeric|gt:0'])->validate();
            $r = ProcessingReceiptLine::with('receipt.document', 'step')->findOrFail($l['processing_receipt_line_id']);
            if ($r->receipt->document->status !== 'posted' || $r->receipt->vendor_party_role_id !== $role->id || $r->disposition !== 'accepted' || $r->receipt->document->document_date->gt($doc->document_date)) {
                Guard::fail('Bills must match accepted posted receipts of this vendor.');
            }
            $q = $this->positive($l['quantity']);
            $this->quantityPrecisionForBilling($r->step->billing_unit_id, $q);
            $amount = $this->money(D::round(bcmul($q, $r->step->agreed_unit_rate_pkr, 18)));
            // The last bill takes the rounded residual, so repeated partial bills conserve the accrual.
            $posted = PurchaseInvoiceProcessingMatch::where('processing_receipt_line_id', $r->id)->whereHas('invoiceLine.invoice.document', fn ($q) => $q->where('status', 'posted'));
            $used = (string) (clone $posted)->sum('billed_service_quantity');
            $relieved = (string) (clone $posted)->sum('accrued_value_relieved');
            foreach ($rows as $prior) {
                if ($prior[0]->id === $r->id) {
                    $used = bcadd($used, $prior[1], 6);
                    $relieved = bcadd($relieved, $prior[2], 4);
                }
            }
            if (bccomp(bcadd($used, $q, 6), $r->accepted_service_quantity, 6) === 0) {
                $amount = bcsub($r->service_accrual_pkr, $relieved, 4);
            }
            if (bccomp($amount, '0', 4) <= 0) {
                Guard::fail('A bill line must have a positive amount; free service needs no payable.');
            }
            $rows[] = [$r, $q, $amount];
            $total = $this->money(bcadd($total, $amount, 4));
        }
        PurchaseInvoice::create(['document_id' => $doc->id, 'payee_party_role_id' => $role->id, 'supplier_invoice_number' => $ref, 'supplier_invoice_date' => $d['supplier_invoice_date'], 'due_on' => $d['due_on'], 'net_amount' => $total, 'gross_amount' => $total, 'notes' => $d['remarks'] ?? null]);
        foreach ($rows as $i => [$r,$q,$amount]) {
            $line = PurchaseInvoiceLine::create(['document_id' => $doc->id, 'line_no' => $i + 1, 'line_kind' => 'processing', 'processing_job_step_id' => $r->processing_job_step_id, 'description' => 'Processing receipt '.$r->receipt->document->number.' line '.$r->line_no, 'unit_id' => $r->step->billing_unit_id, 'quantity' => $q, 'base_quantity' => $q, 'conversion_factor' => 1, 'unit_price' => $r->step->agreed_unit_rate_pkr, 'net_amount' => $amount, 'gross_amount' => $amount]);
            PurchaseInvoiceProcessingMatch::create(['purchase_invoice_line_id' => $line->id, 'processing_receipt_line_id' => $r->id, 'billed_service_quantity' => $q, 'net_amount' => $amount, 'accrued_value_relieved' => $amount]);
        }
    }

    private function quantityPrecisionForBilling(int $unit, string $q): void
    {
        $u = Unit::findOrFail($unit);
        if (! $u->is_active || bccomp($q, bcadd($q, '0', $u->decimal_places), 6)) {
            Guard::fail('Billing quantity exceeds active unit precision.');
        }
    }

    public function jobProgress(ProcessingJob $job): array
    {
        $ids = $job->lines->pluck('id');
        $issued = ProcessingDispatchLine::whereIn('processing_job_step_id', $ids)->whereHas('dispatch.document', fn ($q) => $q->where('status', 'posted'))->selectRaw('processing_job_step_id,SUM(quantity) AS issued')->groupBy('processing_job_step_id')->get()->keyBy('processing_job_step_id');
        $returned = ProcessingReceiptLine::whereIn('processing_job_step_id', $ids)->whereHas('receipt.document', fn ($q) => $q->where('status', 'posted'))->selectRaw("processing_job_step_id,SUM(source_quantity) AS closed,SUM(CASE WHEN disposition='accepted' THEN output_quantity ELSE 0 END) AS accepted,SUM(CASE WHEN disposition='rejected' THEN output_quantity ELSE 0 END) AS rejected,SUM(normal_loss_quantity) AS normal_loss,SUM(abnormal_loss_quantity) AS abnormal_loss")->groupBy('processing_job_step_id')->get()->keyBy('processing_job_step_id');
        $result = [];
        foreach ($ids as $id) {
            $r = $returned->get($id);
            $out = $issued->get($id)?->issued ?? '0';
            $result[$id] = ['issued' => bcadd($out, '0', 6), 'accepted' => bcadd($r?->accepted ?? '0', '0', 6), 'rejected' => bcadd($r?->rejected ?? '0', '0', 6), 'normal_loss' => bcadd($r?->normal_loss ?? '0', '0', 6), 'abnormal_loss' => bcadd($r?->abnormal_loss ?? '0', '0', 6), 'outstanding' => bcsub($out, $r?->closed ?? '0', 6)];
        }

        return $result;
    }

    public function custodyMatches(ProcessingDispatchLine $line): bool
    {
        $layer = StockCostLayer::where('receipt_stock_movement_id', $line->contractor_stock_movement_id)->first();

        return $layer && bccomp($this->outstanding($line), $layer->remaining_quantity, 6) === 0;
    }

    public function outstanding(ProcessingDispatchLine $line): string
    {
        $returned = (string) ProcessingReceiptLine::where('processing_dispatch_line_id', $line->id)->whereHas('receipt.document', fn ($q) => $q->where('status', 'posted'))->sum('source_quantity');

        return bcsub($line->quantity, $returned, 6);
    }

    /** Failure rolls back the preparation as well as all posting effects. */
    public function confirm(int $actor, array $input): Document
    {
        validator($input, ['kind' => 'required|in:job,dispatch,receipt,bill'])->validate();

        return $this->transaction($actor, self::PERMISSIONS[$input['kind']], function () use ($actor, $input) {
            $doc = $this->save($actor, $input)->fresh();
            $key = (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'textile:processing:confirm:'.$input['idempotency_key']);

            return $this->post($actor, $doc->id, $key, $doc->lock_version);
        });
    }

    public function post(int $actor, int $id, string $key, int $version): Document
    {
        validator(['key' => $key], ['key' => 'required|uuid'])->validate();

        return $this->transaction($actor, 'processing.documents.view', function () use ($actor, $id, $key, $version) {
            $doc = $this->find($id);
            $kind = $this->kind($doc);
            Guard::actor($actor, self::PERMISSIONS[$kind]);
            if ($doc->status === 'posted' && $doc->posting_key === $key) {
                if (! hash_equals($doc->getRawOriginal('payload_hash'), $this->payloadHash($doc))) {
                    Guard::fail('Posted processing details differ from their frozen payload.');
                }

                return $doc;
            }
            if ($doc->status !== 'draft' || $doc->lock_version !== $version) {
                Guard::fail('The draft changed or is already posted. Reload it.');
            }
            if (Document::where('posting_key', $key)->exists()) {
                Guard::fail('Posting key already belongs to another document.');
            }
            [$year, $period] = $this->documents->calendar($doc->document_date->format('Y-m-d'));
            if ($year->id !== $doc->financial_year_id || $period->id !== $doc->accounting_period_id) {
                Guard::fail('The financial calendar changed. Cancel and recreate this draft.');
            }
            $h = $this->header($doc);

            match ($kind) {
                'job' => $this->approveJob($h),'dispatch' => $this->postDispatch($doc, $h),'receipt' => $this->postReceipt($doc, $h),'bill' => $this->postBill($doc, $h)
            };
            $this->documents->finish($doc, $actor, $key, $this->payloadHash($doc));

            return $doc->fresh();
        });
    }

    private function payloadHash(Document $doc): string
    {
        $details = $this->header($doc)->toArray();
        // Preserve pre-Phase-9 frozen hashes for unlinked historical jobs.
        if ($this->kind($doc) === 'job' && ($details['manufacturing_batch_document_id'] ?? null) === null) {
            unset($details['manufacturing_batch_document_id']);
        }

        return hash('sha256', json_encode(['document' => $doc->only(['document_type_id', 'document_date', 'financial_year_id', 'accounting_period_id', 'external_reference']), 'details' => $details], JSON_THROW_ON_ERROR), true);
    }

    private function approveJob(ProcessingJob $h): void
    {
        if ($h->manufacturing_batch_document_id) {
            app(ProductionService::class)->openBatch($h->manufacturing_batch_document_id);
        }
        foreach ($h->lines as $s) {
            $v = $this->capability($s->vendor_service_id);
            $r = $this->rate($v, $s->rate_effective_date->format('Y-m-d'));
            if ($r->id !== $s->vendor_service_rate_id) {
                Guard::fail('The source contract changed; cancel and prepare a new job version.');
            }
            if (bccomp($s->agreed_unit_rate_pkr, $r->unit_rate, 8) && ! AuditEvent::where('document_id', $h->document_id)->where('action', 'negotiated_rate_authorized')->get()->contains(fn ($a) => ($a->after_json['stage'] ?? null) === $s->processing_route_step_id && bccomp($a->after_json['agreed_rate'] ?? '-1', $s->agreed_unit_rate_pkr, 8) === 0)) {
                Guard::fail('The negotiated rate has no authorization evidence.');
            }
        }
    }

    private function postedExecution(Document $doc, mixed $h): void
    {
        $batchId = ProcessingJob::findOrFail($h->processing_job_document_id)->manufacturing_batch_document_id;
        if ($batchId) {
            app(ProductionService::class)->openBatch($batchId);
        }
        $this->vendor($h->vendor_party_role_id);
        $job = ProcessingJob::with('document')->findOrFail($h->processing_job_document_id);
        if ($job->document->status !== 'posted' || $doc->document_date->lt($job->document->document_date)) {
            Guard::fail('A posted job must precede execution.');
        }
    }

    private function postDispatch(Document $doc, ProcessingDispatch $h): void
    {
        $this->postedExecution($doc, $h);
        $batchId = ProcessingJob::findOrFail($h->processing_job_document_id)->manufacturing_batch_document_id;
        $date = $doc->document_date->format('Y-m-d');
        $positions = [];
        foreach ($h->lines as $l) {
            $this->step($l->processing_job_step_id, $h->processing_job_document_id, $h->vendor_party_role_id);
            foreach ([$l->source_stock_position_id, $l->contractor_stock_position_id] as $p) {
                $positions[$p] = $this->stock->position($p, $date, $p === $l->source_stock_position_id);
            }
            $s = $positions[$l->source_stock_position_id];
            $t = $positions[$l->contractor_stock_position_id];
            $this->location($t->warehouse_location_id, $h->vendor_party_role_id);
            if (($s->location->location_type === 'contractor' && ! in_array($s->id, $this->handoverPositions, true)) || $s->stock_lot_id !== $t->stock_lot_id || $s->fabric_roll_id !== $t->fabric_roll_id) {
                Guard::fail('Dispatch identities or internal source are invalid.');
            }
        }
        foreach ($h->lines->groupBy('processing_job_step_id') as $stepId => $lines) {
            $step = ProcessingJobStep::findOrFail($stepId);
            $issued = (string) ProcessingDispatchLine::where('processing_job_step_id', $stepId)->whereHas('dispatch.document', fn ($q) => $q->where('status', 'posted'))->sum('quantity');
            $rejected = (string) ProcessingReceiptLine::where('processing_job_step_id', $stepId)->where('disposition', 'rejected')->whereHas('receipt.document', fn ($q) => $q->where('status', 'posted'))->sum('source_quantity');
            if (bccomp(bcadd(bcsub($issued, $rejected, 6), $this->sum($lines, 'quantity'), 6), $step->planned_input_quantity, 6) > 0) {
                Guard::fail('Dispatch exceeds the approved stage quantity.');
            }
            if ($step->line_no > 1) {
                $prev = ProcessingJobStep::where('document_id', $h->processing_job_document_id)->where('line_no', $step->line_no - 1)->firstOrFail();
                foreach ($lines->groupBy('source_stock_position_id') as $position => $group) {
                    $eligible = (string) ProcessingReceiptLine::where(fn ($q) => $q->where(fn ($q) => $q->where('processing_job_step_id', $prev->id)->where('disposition', 'accepted'))->orWhere(fn ($q) => $q->where('processing_job_step_id', $stepId)->where('disposition', 'rejected')))->where('output_stock_position_id', $position)->whereHas('receipt.document', fn ($q) => $q->where('status', 'posted'))->sum('output_quantity');
                    $used = (string) ProcessingDispatchLine::where('processing_job_step_id', $stepId)->where('source_stock_position_id', $position)->whereHas('dispatch.document', fn ($q) => $q->where('status', 'posted'))->sum('quantity');
                    if (bccomp(bcadd($used, $this->sum($group, 'quantity'), 6), $eligible, 6) > 0) {
                        Guard::fail("This stage can dispatch only preceding accepted output or this stage's rejected returns in this job.");
                    }
                }
            }
        }
        foreach ($h->lines->groupBy('source_stock_position_id') as $id => $lines) {
            $source = $positions[$id];
            if ($source->roll && bccomp($this->sum($lines, 'quantity'), $this->stock->onHand($id), 6)) {
                Guard::fail('Roll '.$source->roll->roll_number.' contains '.$this->stock->onHand($id).' '.$source->lot->item->baseUnit->code.'. Dispatch the entire physical roll. Partial-roll dispatch requires a roll split, which is not available yet.');
            }
            if (bccomp($this->sum($lines, 'quantity'), $this->stock->available($id), 6) > 0) {
                Guard::fail('Insufficient unreserved stock for dispatch.');
            }
        }
        foreach ($h->lines as $l) {
            $p = $positions[$l->source_stock_position_id];
            $step = ProcessingJobStep::findOrFail($l->processing_job_step_id);
            if ($p->lot->item_id !== $step->input_item_id) {
                Guard::fail('Dispatch item differs from the frozen stage input.');
            }
            $layer = StockCostLayer::lockForUpdate()->findOrFail($l->source_stock_cost_layer_id);
            if ($layer->stock_position_id !== $p->id || $layer->fifo_date->gt($doc->document_date)) {
                Guard::fail('Invalid dispatch acquisition layer.');
            }
            if ($batchId) {
                app(ProductionProgress::class)->assertWipOrigin([$layer->id], $batchId, null);
            }
            $from = $this->stock->movement($doc, $p->id, D::negative($l->quantity), 'processing');
            [$a,$cost] = $this->stock->issue($doc, $from, $layer, $l->quantity);
            $to = $this->stock->movement($doc, $l->contractor_stock_position_id, $l->quantity, 'processing');
            $target = $this->stock->receipt($doc, $to, $l->quantity, $cost, $layer->fifo_date->format('Y-m-d'));
            $this->stock->link($doc, $a, $cost, $target->id, null);
            $l->update(['source_stock_movement_id' => $from->id, 'contractor_stock_movement_id' => $to->id]);
        }
        foreach (array_keys($positions) as $id) {
            $this->stock->refresh($id);
        }
        $this->stock->validateRolls(array_keys($positions));
    }

    private function sum(iterable $rows, string $column): string
    {
        $v = '0.000000';
        foreach ($rows as $r) {
            $v = bcadd($v, $r->$column, 6);
        }

        return $v;
    }

    private function postReceipt(Document $doc, ProcessingReceipt $h): void
    {
        $this->postedExecution($doc, $h);
        $date = $doc->document_date->format('Y-m-d');
        $positions = [];
        foreach ($h->lines as $l) {
            foreach ([$l->source_stock_position_id, $l->output_stock_position_id] as $id) {
                $positions[$id] = $this->stock->position($id, $date);
            }
            $this->location($positions[$l->source_stock_position_id]->warehouse_location_id, $h->vendor_party_role_id);
            $this->location($positions[$l->output_stock_position_id]->warehouse_location_id, $this->handoverCustodian);
        }
        $charge = '0.0000';
        $loss = '0.0000';
        foreach ($h->lines as $l) {
            $dispatch = ProcessingDispatchLine::with('dispatch.document')->lockForUpdate()->findOrFail($l->processing_dispatch_line_id);
            $s = $this->step($l->processing_job_step_id, $h->processing_job_document_id, $h->vendor_party_role_id);
            if ($dispatch->dispatch->document->status !== 'posted' || $dispatch->processing_job_step_id !== $s->id || $dispatch->dispatch->processing_job_document_id !== $h->processing_job_document_id || $dispatch->dispatch->vendor_party_role_id !== $h->vendor_party_role_id || $dispatch->contractor_stock_position_id !== $l->source_stock_position_id || $doc->document_date->lt($dispatch->dispatch->document->document_date)) {
                Guard::fail('Receipt dispatch, stage and contractor do not match.');
            }
            $prior = ProcessingReceiptLine::where('document_id', $doc->id)->where('processing_dispatch_line_id', $dispatch->id)->where('line_no', '<', $l->line_no)->sum('source_quantity');
            if (bccomp(bcadd((string) $prior, $l->source_quantity, 6), $this->outstanding($dispatch), 6) > 0) {
                Guard::fail('Receipt exceeds outstanding issued quantity.');
            }
            $target = $positions[$l->output_stock_position_id];
            if ($target->lot->item_id !== ($l->disposition === 'accepted' ? $s->output_item_id : $s->input_item_id)) {
                Guard::fail('Returned item differs from the approved stage output/disposition.');
            }
            $layer = StockCostLayer::where('receipt_stock_movement_id', $dispatch->contractor_stock_movement_id)->lockForUpdate()->firstOrFail();
            $from = $this->stock->movement($doc, $l->source_stock_position_id, D::negative($l->source_quantity), 'processing');
            [$a,$cost] = $this->stock->issue($doc, $from, $layer, $l->source_quantity);
            if ($l->abnormal_loss_account_id && ($l->abnormal_loss_account_id !== $this->documents->account('abnormal_processing_loss') || LedgerAccount::findOrFail($l->abnormal_loss_account_id)->account_type !== 'expense')) {
                Guard::fail('Abnormal loss requires the configured expense account; refresh this draft.');
            }
            $abnormal = bccomp($l->abnormal_loss_quantity, '0', 6) > 0 ? D::round(bcdiv(bcmul($cost, $l->abnormal_loss_quantity, 18), $l->source_quantity, 12)) : '0.0000';
            $outputCost = bcadd(bcsub($cost, $abnormal, 4), $l->service_accrual_pkr, 4);
            $to = $this->stock->movement($doc, $l->output_stock_position_id, $l->output_quantity, 'processing');
            $outLayer = $this->stock->receipt($doc, $to, $l->output_quantity, $outputCost, $layer->fifo_date->format('Y-m-d'));
            if (bccomp($l->abnormal_loss_quantity, '0', 6) > 0) {
                $fraction = bcdiv($l->abnormal_loss_quantity, $l->source_quantity, 10);
                foreach ([[$outLayer->id, null, bcsub('1', $fraction, 10), bcsub($cost, $abnormal, 4)], [null, $l->abnormal_loss_account_id, $fraction, $abnormal]] as [$destination,$expense,$share,$amount]) {
                    if (bccomp($share, '0', 10) <= 0) {
                        Guard::fail('Loss share is too small for cost lineage precision.');
                    }InventoryCostLink::create(['document_id' => $doc->id, 'sequence' => 1 + (int) InventoryCostLink::where('document_id', $doc->id)->max('sequence'), 'source_layer_allocation_id' => $a->id, 'destination_stock_cost_layer_id' => $destination, 'expense_account_id' => $expense, 'allocation_fraction' => $share, 'allocated_cost_pkr' => $amount]);
                }
            } else {
                $this->stock->link($doc, $a, $cost, $outLayer->id, null);
            }
            if ($positions[$l->source_stock_position_id]->fabric_roll_id && $target->fabric_roll_id) {
                FabricRollLineage::create(['document_id' => $doc->id, 'parent_roll_id' => $positions[$l->source_stock_position_id]->fabric_roll_id, 'child_roll_id' => $target->fabric_roll_id, 'parent_quantity_used' => $l->source_quantity]);
            }
            $l->update(['source_stock_movement_id' => $from->id, 'output_stock_movement_id' => $to->id, 'abnormal_loss_cost_pkr' => $abnormal]);
            $charge = bcadd($charge, $l->service_accrual_pkr, 4);
            $loss = bcadd($loss, $abnormal, 4);
        }
        foreach (array_keys($positions) as $id) {
            $this->stock->refresh($id);
        }
        $this->stock->validateRolls(array_keys($positions));
        if (bccomp($charge, '0', 4) || bccomp($loss, '0', 4)) {
            $this->journals->post($doc, [$this->documents->account('inventory') => bcsub($charge, $loss, 4), $this->documents->account('processing_accrual') => D::negative($charge, 4), $this->documents->account('abnormal_processing_loss') => $loss]);
        }
    }

    private function postBill(Document $doc, PurchaseInvoice $h): void
    {
        $role = $this->vendor($h->payee_party_role_id);
        $this->duplicateBill($role->id, $h->supplier_invoice_number, $doc->id);
        $total = '0.0000';
        foreach ($h->lines as $line) {
            $matches = PurchaseInvoiceProcessingMatch::where('purchase_invoice_line_id', $line->id)->get();
            if ($matches->count() !== 1) {
                Guard::fail('Each contractor bill line requires one processing receipt match.');
            }
            $m = $matches->first();
            $r = ProcessingReceiptLine::with('receipt.document', 'step')->lockForUpdate()->findOrFail($m->processing_receipt_line_id);
            if ($r->receipt->document->status !== 'posted' || $r->receipt->vendor_party_role_id !== $role->id || $r->disposition !== 'accepted' || $r->receipt->document->document_date->gt($doc->document_date) || $line->processing_job_step_id !== $r->processing_job_step_id) {
                Guard::fail('Bill matching source is invalid.');
            }
            $posted = PurchaseInvoiceProcessingMatch::where('processing_receipt_line_id', $r->id)->whereHas('invoiceLine.invoice.document', fn ($q) => $q->where('status', 'posted'));
            $used = (string) (clone $posted)->sum('billed_service_quantity');
            $value = (string) (clone $posted)->sum('accrued_value_relieved');
            $prior = PurchaseInvoiceProcessingMatch::where('processing_receipt_line_id', $r->id)->whereHas('invoiceLine', fn ($q) => $q->where('document_id', $doc->id)->where('line_no', '<', $line->line_no));
            $used = bcadd($used, (string) (clone $prior)->sum('billed_service_quantity'), 6);
            $value = bcadd($value, (string) (clone $prior)->sum('accrued_value_relieved'), 4);
            $left = bcsub($r->accepted_service_quantity, $used, 6);
            if (bccomp($m->billed_service_quantity, $left, 6) > 0) {
                Guard::fail('The contractor receipt is already billed or has insufficient unmatched quantity.');
            }
            $expected = bccomp($m->billed_service_quantity, $left, 6) === 0 ? bcsub($r->service_accrual_pkr, $value, 4) : D::round(bcmul($m->billed_service_quantity, $r->step->agreed_unit_rate_pkr, 18));
            if (bccomp($expected, $line->net_amount, 4) || bccomp($expected, $m->accrued_value_relieved, 4)) {
                Guard::fail('Bill must exactly match the frozen receipt accrual; refresh the draft after another bill posts.');
            }
            $total = bcadd($total, $expected, 4);
        }
        if (bccomp($total, $h->gross_amount, 4) || bccomp($total, '0', 4) <= 0) {
            Guard::fail('Contractor bill total does not reconcile.');
        }
        $this->journals->post($doc, [$this->documents->account('processing_accrual') => $total, $this->documents->account('supplier_payable') => D::negative($total, 4)], 'operational', $role->party_id, $role->id, $h->due_on->format('Y-m-d'));
    }

    public function cancel(int $actor, int $id, int $version, string $reason): Document
    {
        validator(['reason' => $reason], ['reason' => 'required|string|min:10|max:500'])->validate();

        return $this->transaction($actor, 'processing.documents.manage', function () use ($actor, $id, $version, $reason) {
            $d = $this->find($id);
            if ($d->status !== 'draft' || $d->lock_version !== $version) {
                Guard::fail('Only an unchanged draft can be cancelled. Posted history is immutable.');
            }
            $d->update(['status' => 'cancelled', 'reason' => $reason, 'lock_version' => $d->lock_version + 1]);
            $this->documents->event($d, 'cancelled', $actor, (string) Str::uuid(), 'draft', 'cancelled', $reason);

            return $d;
        });
    }
}
