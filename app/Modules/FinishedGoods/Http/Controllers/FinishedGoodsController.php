<?php

namespace App\Modules\FinishedGoods\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\ManufacturingBatch;
use App\Models\ProductPackSpec;
use App\Models\StockCostLayer;
use App\Models\StockPosition;
use App\Modules\FinishedGoods\Http\Requests\FinishedQualityRequest;
use App\Modules\FinishedGoods\Http\Requests\PackingActionRequest;
use App\Modules\FinishedGoods\Http\Requests\PackingRequest;
use App\Modules\FinishedGoods\Http\Requests\PackSpecificationRequest;
use App\Modules\FinishedGoods\Services\ActualCosting;
use App\Modules\FinishedGoods\Services\FinishedGoodsQuality;
use App\Modules\FinishedGoods\Services\FinishedGoodsWorkspace;
use App\Modules\FinishedGoods\Services\PackingService;
use App\Modules\FinishedGoods\Services\PackSpecificationService;
use Illuminate\Http\Request;

class FinishedGoodsController extends Controller
{
    public function overview()
    {
        $this->authorize('finished-goods.viewAny');

        return view('module.finished-goods::overview');
    }

    public function specifications(FinishedGoodsWorkspace $w)
    {
        $this->authorize('finished-goods.viewAny');

        return view('module.finished-goods::specifications', $w->choices() + ['records' => ProductPackSpec::with('item', 'lines.item.baseUnit')->latest('id')->paginate(15)]);
    }

    public function publish(PackSpecificationRequest $r, PackSpecificationService $s)
    {
        $this->authorize('finished-goods.create');
        $s->publish($r->user()->id, $r->validated());

        return to_route('finished-goods.specifications')->with('status', 'Packing specification published. Existing stock and specifications remain unchanged.');
    }

    public function index(Request $r)
    {
        $this->authorize('finished-goods.viewAny');
        $f = $r->validate(['q' => 'nullable|string|max:100', 'status' => 'nullable|in:draft,posted,cancelled']);
        $records = Document::with('type')->whereHas('type', fn ($q) => $q->where('module_code', 'finished-goods'))->when($f['q'] ?? null, fn ($q, $v) => $q->where('number', 'like', '%'.$v.'%'))->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))->latest('id')->paginate(20)->withQueryString();

        return view('module.finished-goods::index', compact('records'));
    }

    public function form(Request $r, FinishedGoodsWorkspace $w)
    {
        $this->authorize('finished-goods.create');
        $f = $r->validate(['spec' => 'nullable|integer|exists:product_pack_specs,id', 'operation' => 'nullable|in:pack,unpack']);
        $spec = empty($f['spec']) ? null : ProductPackSpec::findOrFail($f['spec']);

        return view('module.finished-goods::form', $w->choices($spec, $f['operation'] ?? 'pack'));
    }

    public function save(PackingRequest $r, PackingService $s)
    {
        $this->authorize('finished-goods.create');
        $d = $r->validated('intent') === 'confirm' ? $s->confirm($r->user()->id, $r->validated()) : $s->save($r->user()->id, $r->validated());

        return to_route('finished-goods.show', $d)->with('status', $d->status === 'posted' ? 'Packing conversion confirmed.' : 'Saved for later.');
    }

    public function show(Document $document, PackingService $s)
    {
        $this->authorize('finished-goods.view', $document);

        return view('module.finished-goods::show', ['document' => $document, 'header' => $s->header($document)]);
    }

    public function action(PackingActionRequest $r, Document $document, string $action, PackingService $s)
    {
        $this->authorize('finished-goods.view', $document);
        $d = $r->validated();
        $action === 'post' ? $s->post($r->user()->id, $document->id, $d['request_key'], $d['lock_version']) : $s->cancel($r->user()->id, $document->id, $d['lock_version'], $d['reason']);

        return to_route('finished-goods.show', $document)->with('status', 'Packing action completed.');
    }

    public function batches()
    {
        $this->authorize('finished-goods.viewAny');

        return view('module.finished-goods::batches', ['records' => ManufacturingBatch::with('document', 'bom.variant.item', 'bom.variant.design', 'bom.variant.color')->whereHas('document', fn ($q) => $q->where('status', 'posted'))->latest('document_id')->paginate(20)]);
    }

    public function costing(Document $document, ActualCosting $c)
    {
        $this->authorize('finished-goods.viewAny');
        abort_unless($document->status === 'posted' && ($document->type->code === 'production_batch' || $document->type->code === 'finished_goods_packing'), 404);

        return view('module.finished-goods::costing', $c->report($document));
    }

    public function trace(StockCostLayer $layer, ActualCosting $c)
    {
        $this->authorize('finished-goods.viewAny');
        $position = StockPosition::with('lot.item.baseUnit', 'location.warehouse')->findOrFail($layer->stock_position_id);
        abort_unless($position->lot->item->item_type === 'finished', 404);
        $c->reset();

        return view('module.finished-goods::trace', ['layer' => $layer, 'position' => $position, 'parts' => $c->acquisition($layer->id), 'cost' => $c->total($c->acquisition($layer->id)), 'trace' => $c->ancestry($layer->id)]);
    }

    public function inventory(Request $r, FinishedGoodsWorkspace $w)
    {
        $this->authorize('finished-goods.viewAny');
        $f = $r->validate(['q' => 'nullable|string|max:100', 'quality' => 'nullable|in:pending,released,held,rejected', 'warehouse' => 'nullable|integer|exists:warehouses,id']);

        return view('module.finished-goods::inventory', $w->inventory($f));
    }

    public function quality(FinishedQualityRequest $r, FinishedGoodsQuality $quality)
    {
        $this->authorize('finished-goods.viewAny');

        return back()->with('status', $quality->apply($r->user()->id, $r->validated()));
    }
}
