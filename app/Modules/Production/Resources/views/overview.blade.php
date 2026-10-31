@extends('module.core::layouts.app')
@section('title','Production - Start here')
@section('content')
@include('module.production::nav')
<h1 class="text-3xl font-semibold">From processed fabric to finished suits</h1><p class="mt-3 max-w-3xl text-sm leading-6 text-slate-500">Set up a material recipe once. Confirm a batch plan, then record what you actually consumed and produced. Each report receives output and handles measured losses in one transaction.</p>
<section class="mt-6 rounded-xl bg-emerald-50 p-5 text-sm"><strong>Before you start</strong><p class="mt-2">Create the finished SUIT item, design, color and finished variant in Textile master data. Receive real, quality-released material through Purchasing / Inventory. Open a financial year/period for the document date.</p><p class="mt-2">Example: one suit needs 6 M fabric. A 100-suit batch plans 600 M net; 10% planned loss requires 666.667 M gross. Actual losses are measured, never automatically issued.</p></section>
<div class="mt-6 grid gap-4 md:grid-cols-2">@foreach([
['production.boms','1. Material recipe / BOM','Choose the finished design/color and define each component, quantity per yield and planned loss. Publishing creates a protected version.'],
['production.batches.index','2. Production order / batch','Select the published recipe, quantity and schedule. Confirm freezes requirements; it creates no stock.'],
['production.reports.index','3. Consume materials and receive output','Choose the batch, actual source lots, output lots, measured normal/abnormal loss and any evidenced costs. Confirm partial reports as work finishes.'],
['production.progress','4. WIP and completion','Track material usage, intermediate stock, contractor custody and completed/rejected suits. Complete only after the plan is accounted for and WIP is cleared.']
] as [$target,$title,$help])<section class="rounded-xl border border-line bg-white p-6"><h2 class="font-semibold">{{ $title }}</h2><p class="mt-2 text-sm leading-6 text-slate-500">{{ $help }}</p><a class="mt-4 inline-block text-sm font-medium text-brand" href="{{ route($target) }}">Open screen &rarr;</a></section>@endforeach</div>
<p class="mt-6 text-sm text-slate-500">Use existing Processing routes/jobs for embroidery, pressing, finishing or any configured stage. Batch-linked jobs retain vendor custody and rate snapshots. Continue through Finished goods for packing, Wholesale sales for orders and Accounting & finance for money movements.</p>
@endsection
