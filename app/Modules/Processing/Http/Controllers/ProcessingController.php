<?php

namespace App\Modules\Processing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\Item;
use App\Models\PartyOpenItem;
use App\Models\PartyRole;
use App\Models\ProcessingDispatch;
use App\Models\ProcessingDispatchLine;
use App\Models\ProcessingFabricFlow;
use App\Models\ProcessingJob;
use App\Models\ProcessingReceipt;
use App\Models\ProcessingReceiptLine;
use App\Models\ProcessingRouteVersion;
use App\Models\ProcessingService as Capability;
use App\Models\PurchaseInvoiceLine;
use App\Models\Unit;
use App\Modules\Processing\Http\Requests\ProcessingActionRequest;
use App\Modules\Processing\Http\Requests\ProcessingRequest;
use App\Modules\Processing\Services\ProcessingRoutes;
use App\Modules\Processing\Services\ProcessingService;
use App\Modules\Processing\Services\ProcessingWorkspace;
use Illuminate\Http\Request;

class ProcessingController extends Controller
{
    public function index(Request $r)
    {
        $this->authorize('processing.viewAny');
        $f = $r->validate(['kind' => 'nullable|in:job,dispatch,receipt,bill', 'status' => 'nullable|in:draft,posted,cancelled', 'q' => 'nullable|string|max:100']);
        $kind = $r->route('workspace_kind');
        if ($kind) {
            $f['kind'] = $kind;
        }
        $page = $kind ? ProcessingWorkspace::PAGES[$kind] : null;
        $documents = Document::with('type')->whereHas('type', fn ($q) => $q->where('module_code', 'processing')->when($f['kind'] ?? null, fn ($q, $k) => $q->where('code', 'processing_'.$k)))->when($f['status'] ?? null, fn ($q, $s) => $q->where('status', $s))->when($f['q'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q->where('number', 'like', '%'.$s.'%')->orWhere('external_reference', 'like', '%'.$s.'%')))->latest('id')->paginate(25)->withQueryString();

        return view('module.processing::index', compact('documents', 'kind', 'page'));
    }

    public function overview(ProcessingWorkspace $workspace)
    {
        $this->authorize('processing.viewAny');
        $checks = $workspace->overview();
        $recent = Document::with('type')->whereHas('type', fn ($q) => $q->where('module_code', 'processing'))->latest('id')->limit(6)->get();
        $fabricDrafts = ProcessingFabricFlow::where('status', 'draft')->latest('id')->get();

        return view('module.processing::overview', compact('checks', 'recent', 'fabricDrafts'));
    }

    public function form(Request $r, ProcessingWorkspace $workspace, ProcessingService $service)
    {
        $this->authorize('processing.create');
        $f = $r->validate(['kind' => 'nullable|in:job,dispatch,receipt,bill']);
        $kind = $r->route('workspace_kind') ?? ($f['kind'] ?? 'job');
        $page = ProcessingWorkspace::PAGES[$kind];
        $data = $workspace->form($r, $kind, $service);

        return view('module.processing::form', $data + compact('kind', 'page'));
    }

    public function save(ProcessingRequest $r, ProcessingService $s)
    {
        $this->authorize('processing.create');
        $confirm = $r->validated('intent') === 'confirm';
        $d = $confirm ? $s->confirm($r->user()->id, $r->validated()) : $s->save($r->user()->id, $r->validated());

        return redirect()->route(ProcessingWorkspace::route($s->kind($d), 'show'), $d)->with('status', $confirm ? 'Processing document confirmed.' : 'Saved for later. Confirm when ready.');
    }

    public function show(Document $document, ProcessingService $service, ProcessingWorkspace $workspace)
    {
        $this->authorize('processing.view', $document);
        $kind = $service->kind($document);
        abort_if(request()->route('workspace_kind') && request()->route('workspace_kind') !== $kind, 404);
        $header = $service->header($document);
        $document->load('events', 'journal.lines.account');
        if ($kind === 'job') {
            $header->load('route', 'lines.service', 'lines.inputItem.baseUnit', 'lines.outputItem', 'lines.vendorService.vendor.role.party', 'lines.billingUnit');
        } elseif ($kind === 'bill') {
            $header->load('supplier.party');
        } else {
            $header->load('job.document', 'vendor.role.party', 'lines.step.service');
            if ($kind === 'dispatch') {
                $header->load('lines.source.lot.item.baseUnit', 'lines.source.roll', 'lines.contractor.location');
            } else {
                $header->load('lines.output.lot.item.baseUnit', 'lines.output.roll', 'lines.step.billingUnit', 'lines.dispatchLine.dispatch.document');
            }
        }
        $related = $kind === 'job' ? Document::with('type')->whereIn('id', ProcessingDispatch::where('processing_job_document_id', $document->id)->pluck('document_id')->merge(ProcessingReceipt::where('processing_job_document_id', $document->id)->pluck('document_id'))->merge(PurchaseInvoiceLine::whereIn('processing_job_step_id', $header->lines->pluck('id'))->pluck('document_id')))->orderBy('id')->get() : collect();

        $page = ProcessingWorkspace::PAGES[$kind];
        $progress = $kind === 'job' ? $service->jobProgress($header) : [];

        $canReceive = $kind === 'dispatch' && $document->status === 'posted' && count($workspace->dispatchLines($header)) > 0;
        $canBill = $kind === 'receipt' && $document->status === 'posted' && count($workspace->billLines($header)) > 0;
        $nextStep = $kind === 'receipt' ? $header->job->lines->firstWhere('line_no', $header->lines->max('step.line_no') + 1) : null;
        $fabricFlow = ProcessingFabricFlow::with('onwardDocument')->where('document_id', $document->id)->orWhere('onward_document_id', $document->id)->first();

        return view('module.processing::show', compact('document', 'header', 'kind', 'service', 'related', 'progress', 'page', 'canReceive', 'canBill', 'nextStep', 'fabricFlow'));
    }

    public function action(ProcessingActionRequest $r, Document $document, string $action, ProcessingService $s)
    {
        $this->authorize('processing.view', $document);
        $d = $r->validated();
        if ($action === 'post') {
            $s->post($r->user()->id, $document->id, $d['request_key'], $d['lock_version']);
        } else {
            $s->cancel($r->user()->id, $document->id, $d['lock_version'], $d['reason'] ?? '');
        }

        return redirect()->route(ProcessingWorkspace::route($s->kind($document), 'show'), $document)->with('status', 'Processing action completed.');
    }

    public function routes()
    {
        $this->authorize('processing.viewAny');
        $routes = ProcessingRouteVersion::with('steps.service', 'steps.inputItem', 'steps.outputItem')->latest('id')->paginate(15);
        $items = Item::with('baseUnit')->where('is_active', true)->where('is_stocked', true)->get();
        $services = Capability::with('billingUnit')->where('is_active', true)->get();

        return view('module.processing::routes', compact('routes', 'services', 'items'));
    }

    public function types()
    {
        $this->authorize('processing.viewAny');
        $services = Capability::with('billingUnit')->get();
        $units = Unit::where('is_active', true)->get();

        return view('module.processing::types', compact('services', 'units'));
    }

    public function createRoute(Request $r, ProcessingRoutes $s)
    {
        $route = $s->create($r->user()->id, $r->all());

        return redirect()->route('processing.routes')->with('status', 'Draft route version '.$route->version_number.' created. Review and publish it.');
    }

    public function publish(Request $r, int $route, ProcessingRoutes $s)
    {
        $s->publish($r->user()->id, $route);

        return back()->with('status', 'Route published. Changes require a new version.');
    }

    public function createService(Request $r, ProcessingRoutes $s)
    {
        $s->service($r->user()->id, $r->all());

        return back()->with('status', 'Processing type created. Assign vendor capabilities and publish vendor rates next.');
    }

    public function custody(Request $r, ProcessingService $service)
    {
        $this->authorize('processing.viewAny');
        $f = $r->validate(['vendor' => 'nullable|integer', 'job' => 'nullable|integer']);
        $lines = ProcessingDispatchLine::with('dispatch.document', 'dispatch.vendor.role.party', 'step.service', 'contractor.location.warehouse', 'contractor.lot.item', 'contractor.roll')->whereHas('dispatch.document', fn ($q) => $q->where('status', 'posted'))->when($f['vendor'] ?? null, fn ($q, $v) => $q->whereHas('dispatch', fn ($q) => $q->where('vendor_party_role_id', $v)))->when($f['job'] ?? null, fn ($q, $j) => $q->whereHas('dispatch', fn ($q) => $q->where('processing_job_document_id', $j)))->latest('id')->paginate(25)->withQueryString();

        $vendors = PartyRole::with('party')->whereHas('type', fn ($q) => $q->where('code', 'vendor'))->get();
        $jobs = ProcessingJob::with('document')->whereHas('document', fn ($q) => $q->where('status', 'posted'))->latest('document_id')->get();

        return view('module.processing::custody', compact('lines', 'service', 'vendors', 'jobs'));
    }

    public function reports(Request $r)
    {
        $this->authorize('processing.viewAny');
        $f = $r->validate(['from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:from']);
        $lines = ProcessingReceiptLine::with('receipt.document', 'receipt.vendor.role.party', 'step.service', 'step.billingUnit', 'output.lot.item')->whereHas('receipt.document', fn ($q) => $q->where('status', 'posted')->when($f['from'] ?? null, fn ($q, $v) => $q->whereDate('document_date', '>=', $v))->when($f['to'] ?? null, fn ($q, $v) => $q->whereDate('document_date', '<=', $v)))->latest('id')->paginate(25)->withQueryString();

        return view('module.processing::reports', compact('lines'));
    }

    public function payables()
    {
        $this->authorize('processing.viewAny');
        $sources = PartyOpenItem::with('journalLine.party', 'journalLine.document')->whereHas('journalLine.document.type', fn ($q) => $q->where('code', 'processing_bill'))->latest('id')->paginate(25);

        return view('module.processing::payables', compact('sources'));
    }
}
