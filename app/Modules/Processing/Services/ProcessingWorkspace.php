<?php

namespace App\Modules\Processing\Services;

use App\Models\AccountingPeriod;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\ProcessingDispatch;
use App\Models\ProcessingDispatchLine;
use App\Models\ProcessingJob;
use App\Models\ProcessingJobStep;
use App\Models\ProcessingReceipt;
use App\Models\ProcessingReceiptLine;
use App\Models\ProcessingRouteVersion;
use App\Models\PurchaseInvoiceProcessingMatch;
use App\Models\StockPosition;
use App\Models\VendorService;
use App\Models\VendorServiceRate;
use App\Models\WarehouseLocation;
use App\Modules\Inventory\Services\InventoryGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Read-only guidance and choices. Posting services remain authoritative. */
class ProcessingWorkspace
{
    public const PAGES = [
        'job' => ['path' => 'jobs', 'title' => 'Processing jobs', 'new' => 'New job', 'help' => 'Choose a published route, assign a vendor to each stage and review the agreed rates. Confirm the job to plan the work; it does not move fabric.'],
        'dispatch' => ['path' => 'dispatches', 'title' => 'Fabric Out history', 'new' => 'Fabric Out', 'help' => 'Fabric sent to vendors, including direct handovers from another vendor. Open an entry to receive its outstanding fabric.'],
        'receipt' => ['path' => 'receipts', 'title' => 'Fabric In history', 'new' => 'Fabric In', 'help' => 'Accepted processing results, returned fabric and direct transfers to the next vendor. Partial receipts leave the remainder with the original vendor.'],
        'bill' => ['path' => 'bills', 'title' => 'Contractor bills', 'new' => 'New contractor bill', 'help' => 'Select an accepted processing receipt and enter the contractor invoice. The saved job rate determines the charge. Confirming records the amount owed, not a payment.'],
    ];

    public static function route(string $kind, string $action = 'index'): string
    {
        return 'processing.'.self::PAGES[$kind]['path'].'.'.$action;
    }

    public function overview(): array
    {
        $date = InventoryGuard::businessDate();
        $year = FinancialYear::whereDate('starts_on', '<=', $date)->whereDate('ends_on', '>=', $date)->where('is_closed', false)->first();

        return [
            'company' => Company::whereKey(1)->exists(),
            'calendar' => $year && AccountingPeriod::where('financial_year_id', $year->id)->whereDate('starts_on', '<=', $date)->whereDate('ends_on', '>=', $date)->where('status', 'open')->exists(),
            'vendors' => VendorServiceRate::whereDate('effective_from', '<=', $date)->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>', $date))->whereHas('vendorService', fn ($q) => $q->where('is_active', true)->whereHas('vendor.role', fn ($q) => $q->where('is_active', true)->whereHas('party', fn ($q) => $q->where('is_active', true)))->whereHas('processingService', fn ($q) => $q->where('is_active', true)))->exists(),
            'locations' => WarehouseLocation::where('is_active', true)->where('location_type', 'contractor')->whereHas('warehouse', fn ($q) => $q->where('is_active', true))->exists(),
            'routes' => ProcessingRouteVersion::where('state', 'published')->where('is_active', true)->exists(),
            'stock' => StockPosition::whereHas('location', fn ($q) => $q->where('location_type', 'internal')->where('is_active', true))->whereHas('lot', fn ($q) => $q->where('quality_status', 'released'))->whereHas('balance', fn ($q) => $q->whereRaw('on_hand_quantity > reserved_quantity'))->exists(),
        ];
    }

    public function form(Request $request, string $kind, ProcessingService $service): array
    {
        $f = $request->validate(['batch' => 'nullable|integer|exists:manufacturing_batches,document_id', 'route' => 'nullable|integer|min:1', 'job' => 'nullable|integer|min:1', 'step' => 'nullable|integer|min:1', 'dispatch' => 'nullable|integer|min:1', 'receipt' => 'nullable|integer|min:1']);
        $routes = ProcessingRouteVersion::with('steps.service', 'steps.inputItem.baseUnit', 'steps.outputItem')->where('state', 'published')->where('is_active', true)->get();
        $selectedRoute = $routes->firstWhere('id', (int) old('processing_route_version_id', $f['route'] ?? 0));
        $jobs = ProcessingJob::with('document', 'lines.service', 'lines.inputItem.baseUnit', 'lines.outputItem', 'lines.billingUnit', 'lines.vendorService.vendor.role.party')->whereHas('document', fn ($q) => $q->where('status', 'posted'))->latest('document_id')->get();
        $jobId = (int) old('processing_job_document_id', $f['job'] ?? 0);
        $dispatches = ProcessingDispatch::with('document', 'job.document', 'vendor.role.party')->whereHas('document', fn ($q) => $q->where('status', 'posted'))->when($jobId, fn ($q) => $q->where('processing_job_document_id', $jobId))->latest('document_id')->get();
        $selectedDispatch = $dispatches->firstWhere('document_id', (int) ($f['dispatch'] ?? 0));
        if ($kind === 'receipt' && ! $selectedDispatch && ! empty(old('lines.0.processing_dispatch_line_id'))) {
            $dispatchId = ProcessingDispatchLine::find(old('lines.0.processing_dispatch_line_id'))?->document_id;
            $selectedDispatch = $dispatches->firstWhere('document_id', $dispatchId);
        }
        abort_if(! empty($f['dispatch']) && ! $selectedDispatch, 404);
        if (! $selectedDispatch && $kind === 'receipt' && $jobId && $dispatches->count() === 1) {
            $selectedDispatch = $dispatches->first();
        }
        $selectedJob = $jobs->firstWhere('document_id', $selectedDispatch?->processing_job_document_id ?? $jobId);
        abort_if($jobId && ! $selectedJob, 404);
        $selectedStep = $selectedJob?->lines->firstWhere('id', (int) ($f['step'] ?? old('lines.0.processing_job_step_id', 0)));
        abort_if(! empty($f['step']) && ! $selectedStep, 404);
        abort_if(! empty($f['route']) && ! $selectedRoute, 404);
        if (! $selectedStep && $selectedJob?->lines->count() === 1) {
            $selectedStep = $selectedJob->lines->first();
        }
        $capabilities = VendorService::with('processingService.billingUnit', 'vendor.role.party', 'rates')->where('is_active', true)->whereHas('processingService', fn ($q) => $q->where('is_active', true))->whereHas('vendor.role', fn ($q) => $q->where('is_active', true)->whereHas('party', fn ($q) => $q->where('is_active', true)))->get();
        $locations = WarehouseLocation::with('warehouse', 'contractor.role.party')->where('is_active', true)->whereHas('warehouse', fn ($q) => $q->where('is_active', true))->get();
        $vendor = $kind === 'dispatch' ? $selectedStep?->vendorService->vendor->role : ($kind === 'receipt' ? $selectedDispatch?->vendor->role : null);
        $progress = $selectedJob ? $service->jobProgress($selectedJob) : [];
        $stock = $selectedStep && $kind === 'dispatch' ? $this->stock($selectedStep, $progress[$selectedStep->id]) : [];
        $dispatchLines = $selectedDispatch ? $this->dispatchLines($selectedDispatch) : [];
        $receipts = ProcessingReceipt::with('document', 'vendor.role.party')->whereHas('document', fn ($q) => $q->where('status', 'posted'))->whereHas('lines', fn ($q) => $q->where('disposition', 'accepted')->where('accepted_service_quantity', '>', 0))->latest('document_id')->get();
        $receiptId = (int) ($f['receipt'] ?? 0);
        if (! $receiptId && $kind === 'bill' && old('lines.0.processing_receipt_line_id')) {
            $receiptId = (int) ProcessingReceiptLine::find(old('lines.0.processing_receipt_line_id'))?->document_id;
        }
        $selectedReceipt = $receipts->firstWhere('document_id', $receiptId);
        abort_if(! empty($f['receipt']) && ! $selectedReceipt, 404);
        $billLines = $selectedReceipt ? $this->billLines($selectedReceipt) : [];
        if ($kind === 'bill') {
            $vendor = $selectedReceipt?->vendor->role;
        }
        $contractorLocations = $locations->where('location_type', 'contractor')->where('contractor_vendor_party_role_id', $vendor?->id)->values();
        $initialLines = match ($kind) {
            'dispatch' => [['processing_job_step_id' => $selectedStep?->id, 'contractor_location_id' => $contractorLocations->count() === 1 ? $contractorLocations->first()->id : '']],
            'receipt' => [['processing_dispatch_line_id' => count($dispatchLines) === 1 ? $dispatchLines[0]['id'] : '', 'normal_loss_quantity' => '0', 'abnormal_loss_quantity' => '0', 'quality_status' => 'pending']],
            'bill' => [['processing_receipt_line_id' => count($billLines) === 1 ? $billLines[0]['id'] : '']],
            default => [],
        };

        return compact('routes', 'selectedRoute', 'jobs', 'selectedJob', 'selectedStep', 'capabilities', 'locations', 'vendor', 'dispatches', 'selectedDispatch', 'receipts', 'selectedReceipt', 'stock', 'dispatchLines', 'billLines', 'initialLines', 'contractorLocations', 'progress');
    }

    private function stock(ProcessingJobStep $step, array $progress): array
    {
        $budget = bcsub($step->planned_input_quantity, bcsub($progress['issued'], $progress['rejected'], 6), 6);
        $positions = StockPosition::with('lot.item.baseUnit', 'location.warehouse', 'roll')->select('stock_positions.*')
            ->selectSub(DB::table('stock_movements')->selectRaw('COALESCE(SUM(quantity_delta),0)')->whereColumn('stock_position_id', 'stock_positions.id'), 'ledger_quantity')
            ->selectSub(DB::table('stock_reservations')->selectRaw('COALESCE(SUM(quantity-fulfilled_quantity-released_quantity),0)')->whereColumn('stock_position_id', 'stock_positions.id'), 'ledger_reserved')
            ->whereHas('lot', fn ($q) => $q->where('item_id', $step->input_item_id)->where('quality_status', 'released')->where(fn ($q) => $q->whereNull('expires_on')->orWhereDate('expires_on', '>=', InventoryGuard::businessDate()))->whereHas('item', fn ($q) => $q->where('is_active', true)->where('is_stocked', true)->whereHas('baseUnit', fn ($q) => $q->where('is_active', true))))
            ->whereHas('location', fn ($q) => $q->where('location_type', 'internal')->where('is_active', true)->whereHas('warehouse', fn ($q) => $q->where('is_active', true)))->get();
        $eligible = collect();
        $used = collect();
        if ($step->line_no > 1) {
            $previous = ProcessingJobStep::where('document_id', $step->document_id)->where('line_no', $step->line_no - 1)->firstOrFail();
            $eligible = ProcessingReceiptLine::where(fn ($q) => $q->where(fn ($q) => $q->where('processing_job_step_id', $previous->id)->where('disposition', 'accepted'))->orWhere(fn ($q) => $q->where('processing_job_step_id', $step->id)->where('disposition', 'rejected')))->whereHas('receipt.document', fn ($q) => $q->where('status', 'posted'))->selectRaw('output_stock_position_id,SUM(output_quantity) AS qty')->groupBy('output_stock_position_id')->pluck('qty', 'output_stock_position_id');
            $used = ProcessingDispatchLine::where('processing_job_step_id', $step->id)->whereHas('dispatch.document', fn ($q) => $q->where('status', 'posted'))->selectRaw('source_stock_position_id,SUM(quantity) AS qty')->groupBy('source_stock_position_id')->pluck('qty', 'source_stock_position_id');
        }
        $rows = [];
        foreach ($positions as $p) {
            $onHand = bcadd($p->ledger_quantity, '0', 6);
            $available = bcsub($onHand, $p->ledger_reserved, 6);
            $limit = bccomp($available, $budget, 6) < 0 ? $available : $budget;
            if ($step->line_no > 1) {
                $stageLimit = bcsub((string) $eligible->get($p->id, '0'), (string) $used->get($p->id, '0'), 6);
                $limit = bccomp($limit, $stageLimit, 6) < 0 ? $limit : $stageLimit;
            }
            if (bccomp($limit, '0', 6) <= 0 || ($p->roll && (! $p->roll->is_active || bccomp($limit, $onHand, 6) < 0))) {
                continue;
            }
            $rows[] = ['id' => $p->id, 'label' => $p->lot->item->sku.' / '.$p->lot->lot_number.' / '.($p->roll?->roll_number ?? 'Unrolled').' / '.$p->location->warehouse->name.' / '.$p->location->name, 'available' => $limit, 'onHand' => $onHand, 'unit' => $p->lot->item->baseUnit->code, 'places' => $p->lot->item->baseUnit->decimal_places, 'roll' => (bool) $p->roll];
        }

        return $rows;
    }

    public function dispatchLines(ProcessingDispatch $dispatch): array
    {
        $lines = $dispatch->lines()->with('step.service', 'step.inputItem.baseUnit', 'step.outputItem', 'step.billingUnit', 'contractor.roll')->get();
        $closed = ProcessingReceiptLine::whereIn('processing_dispatch_line_id', $lines->pluck('id'))->whereHas('receipt.document', fn ($q) => $q->where('status', 'posted'))->selectRaw('processing_dispatch_line_id,SUM(source_quantity) AS qty')->groupBy('processing_dispatch_line_id')->pluck('qty', 'processing_dispatch_line_id');
        $rows = [];
        foreach ($lines as $l) {
            $remaining = bcsub($l->quantity, (string) $closed->get($l->id, '0'), 6);
            if (bccomp($remaining, '0', 6) <= 0) {
                continue;
            }
            $rows[] = ['id' => $l->id, 'label' => 'Line '.$l->line_no.' / '.$l->step->service->name.' / '.($l->contractor->roll?->roll_number ?? 'Unrolled'), 'issued' => $l->quantity, 'closed' => bcsub($l->quantity, $remaining, 6), 'available' => $remaining, 'unit' => $l->step->inputItem->baseUnit->code, 'rate' => $l->step->agreed_unit_rate_pkr, 'billingUnit' => $l->step->billingUnit->code, 'sameUnit' => $l->step->billing_unit_id === $l->step->outputItem->base_unit_id, 'normalFraction' => $l->step->permitted_normal_loss_fraction, 'input' => $l->step->inputItem->sku, 'output' => $l->step->outputItem->sku, 'trackRolls' => (bool) $l->step->outputItem->track_rolls, 'places' => $l->step->inputItem->baseUnit->decimal_places];
        }

        return $rows;
    }

    public function billLines(ProcessingReceipt $receipt): array
    {
        $lines = $receipt->lines()->with('step.service', 'step.billingUnit')->where('disposition', 'accepted')->where('service_accrual_pkr', '>', 0)->get();
        $used = PurchaseInvoiceProcessingMatch::whereIn('processing_receipt_line_id', $lines->pluck('id'))->whereHas('invoiceLine.invoice.document', fn ($q) => $q->where('status', 'posted'))->selectRaw('processing_receipt_line_id,SUM(billed_service_quantity) AS qty')->groupBy('processing_receipt_line_id')->pluck('qty', 'processing_receipt_line_id');
        $rows = [];
        foreach ($lines as $l) {
            $remaining = bcsub($l->accepted_service_quantity, (string) $used->get($l->id, '0'), 6);
            if (bccomp($remaining, '0', 6) <= 0) {
                continue;
            }
            $rows[] = ['id' => $l->id, 'label' => 'Line '.$l->line_no.' / '.$l->step->service->name, 'available' => $remaining, 'accepted' => $l->accepted_service_quantity, 'billed' => bcsub($l->accepted_service_quantity, $remaining, 6), 'rate' => $l->step->agreed_unit_rate_pkr, 'unit' => $l->step->billingUnit->code, 'places' => $l->step->billingUnit->decimal_places];
        }

        return $rows;
    }
}
