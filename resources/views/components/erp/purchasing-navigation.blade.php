@props(['activeKind'=>null])
@php
$canWork=auth()->user()?->hasPermissionTo('purchasing.documents.view');
$canSuppliers=auth()->user()?->hasPermissionTo('purchasing.supplier.view');
$inside=request()->is('purchasing','purchasing/*');
$activeKind=$inside?($activeKind??request()->route('workspace_kind')??request('kind')):null;
$links=[];
if($canWork)$links[]=['purchasing.overview','Overview / Start here',request()->routeIs('purchasing.overview')];
if($canSuppliers)$links[]=['supplier.index','Suppliers',request()->routeIs('supplier.*')];
if($canWork){
foreach(App\Modules\Purchasing\Services\PurchasingWorkspace::PAGES as $key=>$page)$links[]=['purchasing.'.$page['path'].'.index',$page['title'],$activeKind===$key];
$links[]=['purchasing.payables','Payable sources',request()->routeIs('purchasing.payables')];
$links[]=['purchasing.history','Prices & history',request()->routeIs('purchasing.history')];
}
@endphp
@if(count($links))
<div class="mt-2 rounded-xl border border-line bg-slate-50/60" x-data="{ open: @js($inside) }"><button type="button" @click="open=!open" :aria-expanded="open" aria-controls="purchasing-sidebar-links" class="flex w-full items-center justify-between rounded-xl px-3 py-3 text-sm font-semibold text-ink"><span>Purchasing</span><span aria-hidden="true" x-text="open?'-':'+'"></span></button><div id="purchasing-sidebar-links" x-show="open" @if(!$inside) x-cloak @endif class="space-y-1 px-2 pb-3">@foreach($links as [$target,$label,$active])<a href="{{ route($target) }}" @if($active) aria-current="page" @endif class="block rounded-lg px-3 py-2 text-sm {{ $active?'bg-emerald-100 font-semibold text-brand':'text-slate-600 hover:bg-white' }}">{{ $label }}</a>@endforeach</div></div>
@endif
