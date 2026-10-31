@extends('module.core::layouts.app')
@section('title','WIP & batch progress')
@section('content')
@include('module.production::nav')<h1 class="text-2xl font-semibold">WIP & batch progress</h1><form class="mt-5 flex flex-wrap gap-3"><select required name="batch" aria-label="Production batch" class="max-w-full rounded-lg border border-line p-2">@foreach($batches as $b)<option value="{{ $b->document_id }}" @selected($batch?->document_id===$b->document_id)>{{ $b->document->number }} / {{ $b->bom->variant->item->sku }}</option>@endforeach</select><button class="rounded-lg border border-line px-4 py-2">Load progress</button></form>
@if($batch)<div class="mt-6">@include('module.production::progress-table')</div><a class="mt-5 inline-block text-sm text-brand" href="{{ route('production.show',$batch->document_id) }}">Open batch history &rarr;</a>@else<p class="mt-6 rounded-xl bg-white p-5 text-sm text-slate-500">Confirm a production batch to view its progress.</p>@endif
@endsection
