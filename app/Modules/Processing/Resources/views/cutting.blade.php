@extends('module.core::layouts.app')
@section('title','Fabric cutting')
@section('content')
@include('module.processing::nav')
<h1 class="text-2xl font-semibold">Fabric cutting / {{ $document->number }}</h1>
<p class="my-4 text-sm text-slate-500">{{ $document->document_date->format('d M Y') }} / {{ $source->location->warehouse->name }} / {{ $document->status }}</p>
<dl class="grid gap-4 rounded-xl border border-line bg-white p-6 text-sm md:grid-cols-2">
<div><dt>Source fabric / lot</dt><dd>{{ $source->lot->item->name }} / {{ $source->lot->lot_number }}</dd></div>
<div><dt>Result / lot</dt><dd>{{ $output->lot->item->name }} / {{ $output->lot->lot_number }}</dd></div>
<div><dt>Consumed</dt><dd>{{ $cutting->source_quantity }} M</dd></div>
<div><dt>Cut pieces</dt><dd>{{ $cutting->output_quantity }} PCS</dd></div>
<div><dt>Retained meters</dt><dd>{{ $cutting->retained_meters }} M</dd></div>
<div><dt>Normal / abnormal loss</dt><dd>{{ $cutting->normal_loss }} / {{ $cutting->abnormal_loss }} M</dd></div>
<div><dt>Input cost</dt><dd>PKR {{ $cutting->input_cost_pkr }}</dd></div>
<div><dt>Abnormal loss cost</dt><dd>PKR {{ $cutting->abnormal_cost_pkr }}</dd></div>
<div class="md:col-span-2"><dt>Measurement evidence</dt><dd>{{ $cutting->evidence }}</dd></div>
</dl>
<a class="mt-5 inline-block text-brand" href="{{ route('inventory.movements',['position_id'=>$output->id]) }}">View piece stock ledger</a>
@endsection
