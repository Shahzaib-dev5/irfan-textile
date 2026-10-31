@if(auth()->user()?->hasPermissionTo('finished-goods.view'))
@php
$inside=request()->is('finished-goods','finished-goods/*');
@endphp
<div class="mt-2 rounded-xl border border-line bg-slate-50/60" x-data="{open:@js($inside)}"><button type="button" @click="open=!open" :aria-expanded="open" aria-controls="finished-goods-sidebar-links" class="flex w-full items-center justify-between rounded-xl px-3 py-3 text-sm font-semibold"><span>Finished goods</span><span x-text="open?'-':'+'"></span></button><div id="finished-goods-sidebar-links" x-show="open" @if(!$inside) x-cloak @endif class="space-y-1 px-2 pb-3">@foreach(['finished-goods.overview'=>'Overview / Start here','finished-goods.specifications'=>'Packing specifications','finished-goods.index'=>'Packing / recovery records','finished-goods.batches'=>'Batches & actual costing','finished-goods.inventory'=>'Finished stock & quality'] as $route=>$label)<a href="{{ route($route) }}" class="block rounded-lg px-3 py-2 text-sm {{ request()->routeIs($route)?'bg-emerald-100 font-semibold text-brand':'text-slate-600 hover:bg-white' }}">{{ $label }}</a>@endforeach</div></div>
@endif
