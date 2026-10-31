<?php

namespace App\Modules\Processing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\ProcessingDispatchLine;
use App\Models\ProcessingFabricFlow;
use App\Models\StockPosition;
use App\Models\VendorService;
use App\Models\WarehouseLocation;
use App\Modules\Processing\Http\Requests\FabricFlowRequest;
use App\Modules\Processing\Services\FabricFlowService;
use App\Modules\Processing\Services\ProcessingService;
use Illuminate\Http\Request;

class FabricFlowController extends Controller
{
    public function form(Request $request, ProcessingService $processing, ?ProcessingFabricFlow $flow = null)
    {
        $this->authorize('processing.create');
        $f = $request->validate(['line' => 'nullable|integer', 'dispatch' => 'nullable|integer']);
        $kind = $flow?->kind ?? $request->route('flow_kind');
        abort_unless(in_array($kind, ['in', 'out'], true), 404);
        if ($flow?->status === 'posted') {
            return redirect()->route('processing.show', $flow->document_id);
        }
        $values = $flow?->details ?? [];
        $capabilities = VendorService::with('vendor.role.party', 'processingService.billingUnit', 'rates')->where('is_active', true)->whereHas('processingService', fn ($q) => $q->where('is_active', true))->whereHas('vendor.role', fn ($q) => $q->where('is_active', true)->whereHas('party', fn ($q) => $q->where('is_active', true)))->get();
        $vendors = $capabilities->map(fn ($c) => $c->vendor->role)->unique('id');
        $locations = WarehouseLocation::with('warehouse')->where('is_active', true)->whereHas('warehouse', fn ($q) => $q->where('is_active', true))->get();
        $stock = StockPosition::with('lot.item.baseUnit', 'roll', 'location.warehouse', 'balance')->whereHas('balance', fn ($q) => $q->whereRaw('on_hand_quantity > reserved_quantity'))->whereHas('location', fn ($q) => $q->where('location_type', 'internal')->where('is_active', true))->whereHas('lot', fn ($q) => $q->where('quality_status', 'released')->whereHas('item', fn ($q) => $q->whereIn('item_type', ['grey_fabric', 'processed_fabric'])->where('is_active', true)))->get();
        $dispatchLines = ProcessingDispatchLine::with('dispatch.document', 'dispatch.vendor.role.party', 'step.service', 'step.inputItem.baseUnit', 'step.outputItem', 'step.billingUnit')->whereHas('dispatch.document', fn ($q) => $q->where('status', 'posted'))->latest('id')->get()->filter(fn ($line) => bccomp($processing->outstanding($line), '0', 6) > 0);
        $selectedId = old('processing_dispatch_line_id', $values['processing_dispatch_line_id'] ?? $f['line'] ?? null);
        if (! $selectedId && ! empty($f['dispatch'])) {
            $selectedId = $dispatchLines->firstWhere('document_id', (int) $f['dispatch'])?->id;
        }
        $selectedLine = $dispatchLines->firstWhere('id', (int) $selectedId);
        if ($selectedId && ! $selectedLine) {
            abort(422, 'This Fabric Out line is fully received or unavailable. Open an outstanding line.');
        }
        if ($selectedLine && ! $flow) {
            $specs = app(FabricFlowService::class)->specifications($selectedLine);
            foreach (['fabric_type', 'color', 'design_reference', 'component_type'] as $attribute) {
                $values[$attribute] = $specs[$attribute] ?? '';
            }
        }

        return view('module.processing::fabric-form', compact('kind', 'flow', 'values', 'capabilities', 'vendors', 'locations', 'stock', 'dispatchLines', 'selectedLine', 'processing'));
    }

    public function store(FabricFlowRequest $request, FabricFlowService $service)
    {
        $flow = $service->execute($request->user()->id, $request->validated());

        return $flow->status === 'posted'
            ? redirect()->route('processing.show', $flow->document_id)->with('status', $flow->onward_document_id ? 'Processing received and handed directly to the next vendor.' : 'Fabric '.$flow->kind.' confirmed.')
            : redirect()->route('processing.fabric.draft', $flow)->with('status', 'Saved for later. Review and Confirm when ready.');
    }
}
