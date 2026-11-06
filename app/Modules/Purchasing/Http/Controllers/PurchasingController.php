<?php

namespace App\Modules\Purchasing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\Document;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\Item;
use App\Models\PartyOpenItem;
use App\Models\PartyRole;
use App\Models\PaymentTerm;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceLine;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\PurchaseReturn;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Modules\Purchasing\Http\Requests\PurchaseActionRequest;
use App\Modules\Purchasing\Http\Requests\SavePurchaseRequest;
use App\Modules\Purchasing\Services\PurchasingService;
use App\Modules\Purchasing\Services\PurchasingWorkspace;
use Illuminate\Http\Request;

class PurchasingController extends Controller
{
    private function allowed(string $ability, mixed $subject = null): void
    {
        $this->authorize('purchasing.'.$ability, $subject ?? []);
    }

    public function index(Request $request)
    {
        $this->allowed('viewAny');
        $filters = $request->validate(['kind' => 'nullable|in:order,receipt,return,bill', 'status' => 'nullable|in:draft,posted,cancelled', 'q' => 'nullable|string|max:100', 'supplier_party_role_id' => 'nullable|integer']);
        $kind = $request->route('workspace_kind');
        if ($kind) {
            $filters['kind'] = $kind;
        }
        $page = $kind ? PurchasingWorkspace::PAGES[$kind] : null;
        $documents = Document::with('type')->whereHas('type', fn ($q) => $q->where('module_code', 'purchasing')->when($filters['kind'] ?? null, fn ($q, $kind) => $q->where('code', PurchasingService::TYPES[$kind])))->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))->when($filters['q'] ?? null, fn ($q, $term) => $q->where('number', 'like', '%'.$term.'%'))->when($filters['supplier_party_role_id'] ?? null, function ($q, $role) {
            $ids = PurchaseOrder::where('supplier_party_role_id', $role)->pluck('document_id')->merge(GoodsReceipt::where('supplier_party_role_id', $role)->pluck('document_id'))->merge(PurchaseReturn::where('supplier_party_role_id', $role)->pluck('document_id'))->merge(PurchaseInvoice::where('payee_party_role_id', $role)->pluck('document_id'));
            $q->whereIn('id', $ids);
        })->latest('id')->paginate(25)->withQueryString();
        $suppliers = $this->suppliers();

        return view('module.purchasing::index', compact('documents', 'suppliers', 'kind', 'page'));
    }

    public function overview()
    {
        $this->allowed('viewAny');
        $recent = Document::with('type')->whereHas('type', fn ($q) => $q->where('module_code', 'purchasing'))->latest('id')->limit(6)->get();

        return view('module.purchasing::overview', compact('recent'));
    }

    private function workspaceKind(Request $request, string $kind): void
    {
        abort_if($request->route('workspace_kind') && $request->route('workspace_kind') !== $kind, 404);
    }

    private function suppliers()
    {
        return PartyRole::with('party')->whereHas('supplierProfile')->where('is_active', true)->whereHas('party', fn ($q) => $q->where('is_active', true))->get();
    }

    public function form(Request $request, PurchasingService $service, PurchasingWorkspace $workspace, ?Document $document = null)
    {
        $document ??= new Document;
        $this->allowed($document->exists ? 'update' : 'create', $document->exists ? $document : null);
        $kind = $document->exists ? $service->kind($document) : $request->route('workspace_kind') ?? $request->query('kind', 'order');
        abort_unless(isset(PurchasingService::HEADERS[$kind]), 404);
        $this->workspaceKind($request, $kind);
        $page = PurchasingWorkspace::PAGES[$kind];
        $header = $document->exists ? $service->header($document) : null;
        $context = $workspace->context($request, $kind, $header, $document->exists);
        $directReceiptReason = $document->exists && $kind === 'receipt' ? AuditEvent::where('document_id', $document->id)->where('action', 'purchase_direct_receipt_reason')->latest('id')->value('after_json->reason') : null;
        if ($document->exists && $service->workflow($document) === 'submitted') {
            abort(403, 'Return the submitted order to draft before editing.');
        }
        $suppliers = $this->suppliers();
        $warehouses = Warehouse::where('is_active', true)->get();
        $locations = WarehouseLocation::with('warehouse')->where('is_active', true)->whereIn('location_type', ['internal', 'quarantine'])->whereHas('warehouse', fn ($q) => $q->where('is_active', true))->get();
        $items = Item::with('baseUnit')->where('is_stocked', true)->where('is_active', true)->get();
        $units = Unit::where('is_active', true)->get();
        $terms = PaymentTerm::all();
        $orderLines = PurchaseOrderLine::with(['order.document', 'order.supplier.party', 'item'])->whereHas('order.document', fn ($q) => $q->where('status', 'posted'))->get();
        $receiptLines = GoodsReceiptLine::with(['receipt.document', 'receipt.supplier.party', 'item.baseUnit', 'position.lot', 'position.location', 'position.roll'])->whereHas('receipt.document', fn ($q) => $q->where('status', 'posted'))->get();
        $choices = $workspace->choices($kind, $header, $context, $orderLines, $receiptLines, $locations, $service);
        $positions = $choices['positions'];

        return view('module.purchasing::form', $choices + $context + compact('document', 'header', 'kind', 'page', 'suppliers', 'warehouses', 'items', 'units', 'terms', 'directReceiptReason'));
    }

    public function save(SavePurchaseRequest $request, PurchasingService $service, ?Document $document = null)
    {
        $this->allowed($document?->exists ? 'update' : 'create', $document?->exists ? $document : null);
        if ($document?->exists) {
            $this->workspaceKind($request, $service->kind($document));
        }
        $confirm = $request->validated('intent') === 'confirm';
        $saved = $confirm ? $service->confirm($request->user()->id, $request->validated(), $document?->id) : $service->save($request->user()->id, $request->validated(), $document?->id);

        return redirect()->route(PurchasingWorkspace::route($service->kind($saved), 'show'), $saved)->with('status', $confirm ? 'Purchase document confirmed.' : 'Saved for later. Confirm when ready.');
    }

    public function show(Request $request, Document $document, PurchasingService $service, PurchasingWorkspace $workspace)
    {
        $this->allowed('view', $document);
        $kind = $service->kind($document);
        $this->workspaceKind($request, $kind);
        $page = PurchasingWorkspace::PAGES[$kind];
        $header = $service->header($document);
        $header->load('supplier.party');
        $document->load(['journal.lines.account', 'events']);
        $workflow = $service->workflow($document);
        $closed = $kind === 'order' && $service->closed($document->id);

        return view('module.purchasing::show', $workspace->next($document, $kind, $header, $service) + compact('document', 'kind', 'page', 'header', 'workflow', 'closed', 'service'));
    }

    public function action(PurchaseActionRequest $request, PurchasingService $service, Document $document, string $action)
    {
        $data = $request->validated();
        $id = $document->id;
        $actor = $request->user()->id;
        $version = (int) ($data['lock_version'] ?? -1);
        match ($action) {
            'confirm' => $service->confirmExisting($actor, $id, $data['request_key'], $version),
            'submit' => $service->submit($actor, $id, $version, $data['request_key']), 'post' => $service->post($actor, $id, $data['request_key'], $version), 'cancel' => $service->cancel($actor, $id, $version, $data['reason']), 'return_to_draft','close' => $service->orderDecision($actor, $id, $version, $action, $data['reason']), 'reject_delivery' => $service->rejectedDelivery($actor, $id, $data)
        };

        return redirect()->route(PurchasingWorkspace::route($service->kind($document), 'show'), $document)->with('status', 'Purchase action completed.');
    }

    public function history(Request $request)
    {
        $this->allowed('viewAny');
        $data = $request->validate(['item_id' => 'nullable|integer', 'supplier_party_role_id' => 'nullable|integer']);
        $prices = PurchaseOrderLine::with(['item', 'order.supplier.party', 'order.document'])->whereHas('order.document', fn ($q) => $q->where('status', 'posted'))->when($data['item_id'] ?? null, fn ($q, $id) => $q->where('item_id', $id))->when($data['supplier_party_role_id'] ?? null, fn ($q, $id) => $q->whereHas('order', fn ($o) => $o->where('supplier_party_role_id', $id)))->latest('id')->paginate(25)->withQueryString();
        $billPrices = PurchaseInvoiceLine::where('line_kind', 'stock')->with(['item', 'invoice.document', 'invoice.supplier.party'])->whereHas('invoice.document', fn ($q) => $q->where('status', 'posted'))->when($data['item_id'] ?? null, fn ($q, $id) => $q->where('item_id', $id))->when($data['supplier_party_role_id'] ?? null, fn ($q, $id) => $q->whereHas('invoice', fn ($i) => $i->where('payee_party_role_id', $id)))->latest('id')->paginate(25, ['*'], 'bills_page')->withQueryString();

        $items = Item::orderBy('sku')->get();
        $suppliers = PartyRole::with('party')->whereHas('supplierProfile')->get();

        return view('module.purchasing::history', compact('prices', 'billPrices', 'items', 'suppliers'));
    }

    public function payables()
    {
        $this->allowed('viewAny');
        $sources = PartyOpenItem::with(['journalLine.account', 'journalLine.party', 'journalLine.document'])->whereHas('journalLine.document.type', fn ($q) => $q->where('code', 'purchase_invoice')->where('module_code', 'purchasing'))->whereHas('journalLine.account', fn ($q) => $q->where('control_type', 'party_payable'))->latest('id')->paginate(25);

        return view('module.purchasing::payables', compact('sources'));
    }
}
