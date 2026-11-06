@if(auth()->user()?->hasPermissionTo('inventory.view'))
    @php($physicalInside = request()->routeIs('physical-stock.*'))
    <div class="mt-2 rounded-xl border border-line bg-slate-50/60" x-data="{ open: @js($physicalInside) }">
        <button type="button" @click="open = !open" :aria-expanded="open" aria-controls="physical-stock-sidebar-links" class="flex w-full items-center justify-between rounded-xl px-3 py-3 text-sm font-semibold text-ink">
            <span>Physical Suit Stock</span><span x-text="open ? '-' : '+'" aria-hidden="true"></span>
        </button>
        <div id="physical-stock-sidebar-links" x-show="open" @if(!$physicalInside) x-cloak @endif class="space-y-1 px-2 pb-3">
            @foreach(['physical-stock.index' => 'Physical suit stock', 'physical-stock.fabric' => 'Processed fabric'] as $physicalRoute => $physicalLabel)
                <a href="{{ route($physicalRoute) }}" @if(request()->routeIs($physicalRoute)) aria-current="page" @endif class="block rounded-lg px-3 py-2 text-sm {{ request()->routeIs($physicalRoute) ? 'bg-emerald-100 font-semibold text-brand' : 'text-slate-600 hover:bg-white' }}">{{ $physicalLabel }}</a>
            @endforeach
            @if(auth()->user()->hasPermissionTo('inventory.manage'))
                <a href="{{ route('physical-stock.create') }}" @if(request()->routeIs('physical-stock.create')) aria-current="page" @endif class="block rounded-lg px-3 py-2 text-sm {{ request()->routeIs('physical-stock.create') ? 'bg-emerald-100 font-semibold text-brand' : 'text-slate-600 hover:bg-white' }}">Add ready suits</a>
                <a href="{{ route('inventory.documents.create', ['kind' => 'transfer']) }}" class="block rounded-lg px-3 py-2 text-sm text-slate-600 hover:bg-white">Warehouse transfer</a>
                <a href="{{ route('inventory.documents.create', ['kind' => 'adjustment']) }}" class="block rounded-lg px-3 py-2 text-sm text-slate-600 hover:bg-white">Count correction / damage</a>
            @endif
            <a href="{{ route('inventory.movements') }}" class="block rounded-lg px-3 py-2 text-sm text-slate-600 hover:bg-white">Stock ledger</a>
            @if(auth()->user()->hasPermissionTo('finished-goods.view'))
                <a href="{{ route('finished-goods.inventory') }}" class="block rounded-lg px-3 py-2 text-sm text-slate-600 hover:bg-white">Inspect / release ready stock</a>
            @endif
            @if(auth()->user()->hasPermissionTo('sales.documents.view'))
                <a href="{{ route('sales.index') }}" class="block rounded-lg px-3 py-2 text-sm text-slate-600 hover:bg-white">Wholesale</a>
            @endif
        </div>
    </div>
@endif
