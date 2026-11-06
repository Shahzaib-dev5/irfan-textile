@props(['activeKind'=>null])
@php
$canWork=auth()->user()?->hasPermissionTo('processing.documents.view');
$inside=request()->is('processing','processing/*');
$activeKind=$inside?($activeKind??request()->route('workspace_kind')??request('kind')):null;
$links=[];
if($canWork){
if(auth()->user()?->hasPermissionTo('inventory.view'))$links[]=['physical-stock.fabric','Processed fabric inventory',request()->routeIs('physical-stock.fabric')];
$links[]=['processing.services.index','Service types',request()->routeIs('processing.services.*')];
if(auth()->user()?->hasPermissionTo('processing.documents.manage')){
$links[]=['processing.fabric.out','Fabric Out',request()->routeIs('processing.fabric.out')];
$links[]=['processing.fabric.in','Fabric In',request()->routeIs('processing.fabric.in')];
}
foreach(App\Modules\Processing\Services\ProcessingWorkspace::PAGES as $key=>$page)if($key!=='job')$links[]=['processing.'.$page['path'].'.index',$page['title'],$activeKind===$key];
array_splice($links,count($links)-1,0,[['processing.custody','Stock with vendors',request()->routeIs('processing.custody')]]);
$links[]=['processing.payables','Payable sources',request()->routeIs('processing.payables')];
$links[]=['processing.reports','Reports',request()->routeIs('processing.reports')];
}
@endphp
@if(count($links))
<div class="mt-2 rounded-xl border border-line bg-slate-50/60" x-data="{ open: @js($inside) }">
<button type="button" @click="open=!open" :aria-expanded="open" aria-controls="processing-sidebar-links" class="flex w-full items-center justify-between rounded-xl px-3 py-3 text-sm font-semibold text-ink"><span>Processing</span><span aria-hidden="true" x-text="open?'-':'+'"></span></button>
<div id="processing-sidebar-links" x-show="open" @if(!$inside) x-cloak @endif class="space-y-1 px-2 pb-3">
@foreach($links as [$target,$label,$active])<a href="{{ route($target) }}" @if($active) aria-current="page" @endif class="block rounded-lg px-3 py-2 text-sm {{ $active?'bg-emerald-100 font-semibold text-brand':'text-slate-600 hover:bg-white' }}">{{ $label }}</a>@endforeach
</div></div>
@endif

