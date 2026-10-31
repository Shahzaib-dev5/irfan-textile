<div class="mb-6 flex flex-wrap items-center gap-3 text-sm">
    <a class="rounded-lg border border-line bg-white px-4 py-2" href="{{ route('inventory.index') }}">Stock availability</a>
    <a class="rounded-lg border border-line bg-white px-4 py-2" href="{{ route('inventory.documents.index') }}">Documents</a>
    <a class="rounded-lg border border-line bg-white px-4 py-2" href="{{ route('inventory.movements') }}">Movement ledger</a>
    <a class="rounded-lg border border-line bg-white px-4 py-2" href="{{ route('inventory.reconciliation') }}">Reconciliation</a>
    @if(auth()->user()->hasPermissionTo('warehouses.view'))<a class="rounded-lg border border-line bg-white px-4 py-2" href="{{ route('warehouses.index') }}">Warehouses</a>@endif
</div>
