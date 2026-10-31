<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\BillOfMaterial;
use App\Models\Document;
use App\Models\ManufacturingBatch;
use App\Modules\Production\Http\Requests\BomRequest;
use App\Modules\Production\Http\Requests\ProductionActionRequest;
use App\Modules\Production\Http\Requests\ProductionRequest;
use App\Modules\Production\Services\BomService;
use App\Modules\Production\Services\ProductionProgress;
use App\Modules\Production\Services\ProductionService;
use App\Modules\Production\Services\ProductionWorkspace;
use Illuminate\Http\Request;

class ProductionController extends Controller
{
    public function overview()
    {
        $this->authorize('production.viewAny');

        return view('module.production::overview');
    }

    public function progress(Request $r, ProductionProgress $p)
    {
        $this->authorize('production.viewAny');
        $f = $r->validate(['batch' => 'nullable|integer|exists:manufacturing_batches,document_id']);
        $batches = ManufacturingBatch::with('document', 'bom.variant.item')->whereHas('document', fn ($q) => $q->where('status', 'posted'))->latest('document_id')->get();
        $batch = $batches->firstWhere('document_id', (int) ($f['batch'] ?? $batches->first()?->document_id));
        abort_if(! empty($f['batch']) && ! $batch, 404);

        return view('module.production::progress', ['batches' => $batches, 'batch' => $batch, 'state' => $batch ? $p->summary($batch) : null]);
    }

    public function index(Request $r)
    {
        $this->authorize('production.viewAny');
        $kind = $r->route('workspace_kind');
        $f = $r->validate(['q' => 'nullable|string|max:100', 'status' => 'nullable|in:draft,posted,cancelled']);
        $documents = Document::with('type')->whereHas('type', fn ($q) => $q->where('module_code', 'production')->where('code', 'production_'.$kind))->when($f['status'] ?? null, fn ($q, $s) => $q->where('status', $s))->when($f['q'] ?? null, fn ($q, $term) => $q->where('number', 'like', '%'.$term.'%'))->latest('id')->paginate(20)->withQueryString();

        return view('module.production::index', compact('documents', 'kind'));
    }

    public function boms(ProductionWorkspace $w)
    {
        $this->authorize('production.viewAny');

        return view('module.production::boms', $w->choices() + ['records' => BillOfMaterial::with('variant.item', 'variant.design', 'variant.color', 'lines.item.baseUnit')->latest('id')->paginate(10)]);
    }

    public function saveBom(BomRequest $r, BomService $s)
    {
        $this->authorize('production.create');
        $s->create($r->user()->id, $r->validated());

        return to_route('production.boms')->with('status', 'New BOM version published. Existing plans are unchanged.');
    }

    public function form(Request $r, ProductionWorkspace $w, ProductionProgress $p)
    {
        $this->authorize('production.create');
        $kind = $r->route('workspace_kind');
        $f = $r->validate(['batch' => 'nullable|integer|exists:manufacturing_batches,document_id']);
        $batches = $w->batches();
        $batch = $kind === 'report' ? $batches->firstWhere('document_id', (int) old('manufacturing_batch_document_id', $f['batch'] ?? 0)) : null;
        abort_if(! empty($f['batch']) && ! $batch, 404);
        $state = $batch ? $p->summary($batch) : null;

        return view('module.production::form', $w->choices() + compact('kind', 'batches', 'batch', 'state'));
    }

    public function save(ProductionRequest $r, ProductionService $s)
    {
        $this->authorize('production.create');
        $confirm = $r->validated('intent') === 'confirm';
        $doc = $confirm ? $s->confirm($r->user()->id, $r->validated()) : $s->save($r->user()->id, $r->validated());

        return to_route('production.show', $doc)->with('status', $doc->status === 'posted' ? 'Production document confirmed.' : 'Saved for later. Confirm when ready.');
    }

    public function show(Document $document, ProductionService $s, ProductionProgress $p)
    {
        $this->authorize('production.view', $document);
        $kind = $s->kind($document);
        $header = $s->header($document);
        $batch = $kind === 'batch' ? $header : $header->batch;
        $state = $p->summary($batch);
        $document->load('events', 'journal.lines.account');

        return view('module.production::show', compact('document', 'kind', 'header', 'batch', 'state'));
    }

    public function action(ProductionActionRequest $r, Document $document, string $action, ProductionService $s)
    {
        $this->authorize('production.view', $document);
        $d = $r->validated();
        if ($action === 'post') {
            $s->post($r->user()->id, $document->id, $d['request_key'], $d['lock_version']);
        } else {
            $s->cancel($r->user()->id, $document->id, $d['lock_version'], $d['reason']);
        }

        return to_route('production.show', $document)->with('status', 'Production action completed.');
    }
}
