<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\Item;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\StockPosition;
use App\Models\StockReservation;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Modules\Inventory\Http\Requests\InventoryActionRequest;
use App\Modules\Inventory\Http\Requests\SaveInventoryRequest;
use App\Modules\Inventory\Services\InventoryInquiry;
use App\Modules\Inventory\Services\InventoryService;
use Illuminate\Http\Request;

class InventoryController extends Controller
{
    public function index(Request $request, InventoryInquiry $inquiry)
    {
        $this->authorize('viewAny', Document::class);
        $positions = $inquiry->positions($request->only('q', 'warehouse_id', 'quality_status'));
        $warehouses = Warehouse::orderBy('name')->get();

        return view('module.inventory::stock', compact('positions', 'warehouses'));
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
        $items = Item::with('baseUnit')->where('is_stocked', true)->where('is_active', true)->orderBy('sku')->get();
        $locations = WarehouseLocation::with('warehouse')->where('is_active', true)->orderBy('warehouse_id')->orderBy('code')->get();
        $positions = StockPosition::with(['lot.item.baseUnit', 'roll', 'location.warehouse'])->orderBy('id')->get();

        return view('module.inventory::form', compact('document', 'kind', 'items', 'locations', 'positions'));
    }

    public function save(SaveInventoryRequest $request, InventoryService $service, ?Document $document = null)
    {
        $this->authorize($document?->exists ? 'update' : 'create', $document?->exists ? $document : Document::class);
        $saved = $service->save($request->user()->id, $request->validated(), $document?->id);

        return redirect()->route('inventory.documents.show', $saved)->with('status', 'Draft saved. Stock changes only when posted.');
    }

    public function show(Document $document)
    {
        abort_unless($document->type->module_code === 'inventory', 404);
        $this->authorize('viewAny', Document::class);
        $document->load(['type', 'adjustment.lines.position.lot.item', 'adjustment.lines.position.location', 'transfer.lines.source.lot.item', 'transfer.lines.source.location', 'transfer.lines.destination.location', 'movements.position.lot.item', 'movements.position.location', 'events', 'journal.lines.account']);
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
