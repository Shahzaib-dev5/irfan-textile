<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\PartyRole;
use App\Modules\Sales\Http\Requests\SalesActionRequest;
use App\Modules\Sales\Http\Requests\SalesDocumentRequest;
use App\Modules\Sales\Services\SalesService;
use App\Modules\Sales\Services\SalesWorkspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class SalesController extends Controller
{
    public function __construct(private SalesService $sales, private SalesWorkspace $workspace) {}

    public function overview()
    {
        return view('module.sales::overview');
    }

    public function index(Request $r)
    {
        $f = $r->validate(['kind' => 'nullable|in:order,dispatch,invoice,return,credit', 'status' => 'nullable|in:draft,posted,cancelled', 'q' => 'nullable|string|max:100']);

        return view('module.sales::index', ['records' => $this->workspace->documents($f), 'kind' => $f['kind'] ?? 'order']);
    }

    public function create(Request $r)
    {
        $f = $r->validate(['kind' => 'required|in:order,dispatch,invoice,return,credit', 'customer' => 'nullable|integer', 'invoice' => 'nullable|integer']);

        return view('module.sales::form', $this->workspace->form($f['kind'], isset($f['customer']) ? (int) $f['customer'] : null, isset($f['invoice']) ? (int) $f['invoice'] : null));
    }

    public function items(Request $r)
    {
        $f = $r->validate(['q' => 'nullable|string|max:100']);

        return response()->json($this->workspace->items($f['q'] ?? ''));
    }

    public function availability()
    {
        return view('module.sales::availability', ['positions' => $this->workspace->positions()]);
    }

    public function history(PartyRole $customer)
    {
        return view('module.sales::history', $this->workspace->history($customer));
    }

    public function store(SalesDocumentRequest $r)
    {
        $d = $r->input('intent') === 'confirm' ? $this->sales->confirm($r->user()->id, $r->validated()) : $this->sales->save($r->user()->id, $r->validated());

        return redirect()->route('sales.show', $d)->with('status', $d->status === 'posted' ? 'Sales entry confirmed.' : 'Saved for later. Review the preview, then confirm.');
    }

    public function show(Document $document)
    {
        Gate::authorize('sales.view', $document);

        return view('module.sales::show', $this->workspace->detail($document));
    }

    public function action(SalesActionRequest $r, Document $document, string $action)
    {
        Gate::authorize('sales.view', $document);
        $d = $r->validated();
        match ($action) {
            'post' => $this->sales->post($r->user()->id, $document->id, $d['key'] ?? '', (int) $d['lock_version']),
            'cancel' => $this->sales->cancel($r->user()->id, $document->id, (int) $d['lock_version'], $d['reason'] ?? ''),
            'close' => $this->sales->close($r->user()->id, $document->id, $d['reason'] ?? ''),
            'reserve' => $this->sales->reserve($r->user()->id, $document->id, $d),
            'release' => $this->sales->release($r->user()->id, $document->id, $d['reason'] ?? ''),
        };

        return back()->with('status', 'Sales action completed.');
    }
}
