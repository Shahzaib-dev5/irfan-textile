<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\Document;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\PartyRole;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\StockPosition;
use App\Models\StockReservation;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Modules\Inventory\Http\Requests\DirectFabricPurchaseRequest;
use App\Modules\Inventory\Http\Requests\InventoryActionRequest;
use App\Modules\Inventory\Http\Requests\SaveInventoryRequest;
use App\Modules\Inventory\Services\DirectFabricPurchase;
use App\Modules\Inventory\Services\InventoryInquiry;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Inventory\Services\PurchaseMeasurement;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class InventoryController extends Controller
{
    public function index(Request $request, InventoryInquiry $inquiry)
    {
        $this->authorize('viewAny', Document::class);
        $filters = $request->only('q', 'warehouse_id', 'quality_status');
        $positions = $inquiry->positions($filters);
        $summary = $inquiry->availabilitySummary($filters);
        $itemGroups = $inquiry->itemBreakdown($filters);
        $warehouses = Warehouse::orderBy('name')->get();

        return view('module.inventory::stock', compact('positions', 'warehouses', 'summary', 'itemGroups'));
    }

    public function directFabricPurchase(Request $request)
    {
        abort_unless($request->user()->hasPermissionTo('purchasing.documents.manage')
            && $request->user()->hasPermissionTo('purchasing.receipts.post'), 403);
        $items = Item::with('baseUnit', 'textileDefinition')->whereIn('item_type', PurchaseMeasurement::TYPES)->where('is_stocked', true)->where('is_active', true)->orderBy('id')->get()->unique(fn ($item) => $item->textile_definition_id ? 'definition-'.$item->textile_definition_id : 'item-'.$item->id)->values();
        $units = Unit::where('is_active', true)->where('dimension', '<>', 'service')->whereNotIn('code', ['SUIT', 'PACK', 'ROLL'])->orderBy('name')->get();
        $suppliers = PartyRole::with('party')->where('is_active', true)->whereHas('type', fn ($q) => $q->where('code', 'supplier'))->whereHas('supplierProfile')->get()->sortBy('party.legal_name');
        $locations = WarehouseLocation::with('warehouse')->where('is_active', true)->whereIn('location_type', ['internal', 'quarantine'])->whereHas('warehouse', fn ($q) => $q->where('is_active', true))->get();
        $categories = ItemCategory::where('is_active', true)->orderBy('name')->get();
        $banks = $request->user()->hasPermissionTo('accounting.finance.post')
            ? BankAccount::where('is_active', true)->with('ledgerAccount')->orderBy('name')->get()
            : collect();
        $key = (string) Str::uuid();

        return view('module.inventory::direct-fabric-purchase', compact('items', 'suppliers', 'locations', 'categories', 'banks', 'key', 'units'));
    }

    public function storeDirectFabricPurchase(DirectFabricPurchaseRequest $request, DirectFabricPurchase $service)
    {
        $result = $service->confirm($request->user()->id, $request->validated());

        return redirect()->route('purchasing.show', $result['bill'] ?? $result['receipt'])->with('status', $result['bill'] ? 'Direct purchase confirmed. Stock and supplier bill posted'.($result['payment'] ? ', and payment allocated.' : '.') : 'Stock received. Bill pending: use Create supplier bill when the invoice arrives.');
    }

    public function documents(Request $request)
    {
        $this->authorize('viewAny', Document::class);
        $documents = Document::with('type')->whereHas('type', fn ($q) => $q->where('module_code', 'inventory'))->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))->when($request->filled('q'), fn ($q) => $q->where('number', 'like', '%'.$request->string('q').'%'))->latest('id')->paginate(25)->withQueryString();

        return view('module.inventory::documents', compact('documents'));
    }

    public function form(Request $request, ?Document $document = null)
    {
        $this->authorize($document?->exists ? 'update' : 'create', $document?->exists ? $document : Document::class);
        $document ??= new Document;
        if ($document->exists) {
            abort_unless($document->type->module_code === 'inventory', 404);
        }
        $kind = $document->exists ? ($document->transfer ? 'transfer' : 'adjustment') : ($request->query('kind') === 'transfer' ? 'transfer' : 'adjustment');
        abort_if($kind === 'transfer' && ! $request->user()->hasPermissionTo('inventory.post'), 403);
        $items = Item::with('baseUnit')->where('is_stocked', true)->where('is_active', true)->orderBy('sku')->get();
        $locations = WarehouseLocation::with('warehouse')->where('is_active', true)->orderBy('warehouse_id')->orderBy('code')->get();
        $positions = StockPosition::with(['lot.item.baseUnit', 'roll', 'location.warehouse'])->orderBy('id')->get();

        return view('module.inventory::form', compact('document', 'kind', 'items', 'locations', 'positions'));
    }

    public function save(SaveInventoryRequest $request, InventoryService $service, ?Document $document = null)
    {
        $this->authorize($document?->exists ? 'update' : 'create', $document?->exists ? $document : Document::class);
        $transfer = $request->validated('kind') === 'transfer';
        $saved = $transfer
            ? $service->confirmTransfer($request->user()->id, $request->validated(), $document?->id)
            : $service->save($request->user()->id, $request->validated(), $document?->id);

        return redirect()->route('inventory.documents.show', $saved)->with('status', $transfer ? 'Warehouse transfer confirmed. Stock has moved.' : 'Draft saved. Stock changes only when posted.');
    }

    public function show(Document $document)
    {
        abort_unless($document->type->module_code === 'inventory', 404);
        $this->authorize('viewAny', Document::class);
        $document->load(['type', 'adjustment.lines.position.lot.item', 'adjustment.lines.position.location', 'transfer.lines.source.lot.item', 'transfer.lines.source.location', 'transfer.lines.destination.location', 'movements.position.lot.item', 'movements.position.location', 'events', 'journal.lines.account']);
        if ($document->type->code === 'fabric_cutting') {
            return redirect()->route('processing.cutting.show', $document);
        }
        $reservations = StockReservation::where('document_id', $document->id)->get();

        return view('module.inventory::show', compact('document', 'reservations'));
    }

    public function action(InventoryActionRequest $request, InventoryService $service, Document $document, string $action)
    {
        $data = $request->validated();
        $actor = $request->user()->id;
        $version = (int) ($data['lock_version'] ?? -1);
        $target = $document;
        match ($action) {
            'post' => $service->post($actor, $document->id, $data['posting_key'], $version), 'cancel' => $service->cancel($actor, $document->id, $version, $data['reason']), 'reserve' => $service->reserve($actor, $document->id, $version, $data['expires_at'] ?? null), 'release' => $service->release($actor, $document->id, $version, $data['reason']), 'reverse' => $target = $service->reverse($actor, $document->id, $data['document_date'], $data['reason'], $data['posting_key'])
        };

        return redirect()->route('inventory.documents.show', $target)->with('status', ucfirst($action).' completed.');
    }

    public function movements(Request $request)
    {
        $this->authorize('viewAny', Document::class);
        $data = $request->validate(['position_id' => 'nullable|integer|exists:stock_positions,id', 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:from']);
        $movements = StockMovement::with(['document.type', 'position.lot.item', 'position.location.warehouse', 'position.roll'])->when($data['position_id'] ?? null, fn ($q, $id) => $q->where('stock_position_id', $id))->when($data['from'] ?? null, fn ($q, $date) => $q->whereHas('document', fn ($d) => $d->whereDate('document_date', '>=', $date)))->when($data['to'] ?? null, fn ($q, $date) => $q->whereHas('document', fn ($d) => $d->whereDate('document_date', '<=', $date)))->latest('id')->paginate(50)->withQueryString();

        return view('module.inventory::movements', compact('movements'));
    }

    public function reconcile(InventoryInquiry $inquiry)
    {
        $this->authorize('viewAny', Document::class);

        return view('module.inventory::reconcile', ['differences' => $inquiry->reconciliation()]);
    }

    public function repair(Request $request, InventoryInquiry $inquiry)
    {
        $data = $request->validate(['reason' => 'required|string|min:5|max:1000']);
        $inquiry->repair($request->user()->id, $data['reason']);

        return back()->with('status', 'Projections rebuilt from immutable history.');
    }

    public function quality(Request $request, InventoryService $service, StockLot $lot)
    {
        $data = $request->validate(['quality_status' => 'required|in:pending,released,held,rejected', 'reason' => 'required|string|min:5|max:1000']);
        $service->quality($request->user()->id, $lot->id, $data['quality_status'], $data['reason']);

        return back()->with('status', 'Lot quality updated under audit.');
    }
}
