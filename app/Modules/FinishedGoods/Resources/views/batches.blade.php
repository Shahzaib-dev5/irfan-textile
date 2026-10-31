@extends('module.core::layouts.app')
@section('title','Batches and actual costing')
@section('content')
@include('module.finished-goods::nav')<h1 class="text-2xl font-semibold">Batches and actual costing</h1><p class="mt-3 text-sm text-slate-500">Production receipts already created suit stock. Completing a batch checks its plan, WIP and contractor custody; it does not receive the suits again.</p><div class="mt-6 space-y-4">@forelse($records as $b)
@php
$state=app(App\Modules\Production\Services\ProductionProgress::class)->summary($b);
@endphp
<section class="rounded-xl border border-line bg-white p-5"><h2 class="font-semibold">{{ $b->document->number }} / {{ $b->bom->variant->item->sku }}</h2><p class="mt-2 text-sm">{{ $b->bom->variant->design->name }} / {{ $b->bom->variant->color->name }} / {{ $state['complete']?'Completed':'In progress' }}</p><p class="mt-2 text-sm text-slate-500">Accounted {{ $state['accounted'] }} / {{ $b->planned_output_quantity }} SUIT; retained rejects {{ $state['rejected'] }}.</p><div class="mt-4 flex flex-wrap gap-4 text-sm"><a href="{{ route('finished-goods.costing',$b->document_id) }}" class="text-brand">Actual batch costing</a>@if(auth()->user()->hasPermissionTo('production.view'))<a href="{{ route('production.show',$b->document_id) }}" class="text-brand">Production history</a>@endif @if(!$state['complete']&&auth()->user()->hasPermissionTo('production.manage'))<a href="{{ route('production.reports.create',['batch'=>$b->document_id]) }}" class="text-brand">Record remaining output / complete batch</a>@endif</div></section>@empty<p class="rounded-xl bg-white p-6 text-sm text-slate-500">No confirmed production batches yet.</p>@endforelse</div><div class="mt-5">{{ $records->links() }}</div>
@endsection
