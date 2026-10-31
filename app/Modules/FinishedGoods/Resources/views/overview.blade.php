@extends('module.core::layouts.app')
@section('title','Finished goods - Start here')
@section('content')
@include('module.finished-goods::nav')
<h1 class="text-3xl font-semibold">Finished suits, packing and actual cost</h1><p class="mt-3 max-w-3xl text-sm leading-6 text-slate-500">Production has already received your suits into stock. Pack only what you physically have, and follow the actual source cost through to the warehouse.</p>
<section class="mt-6 rounded-xl bg-emerald-50 p-5 text-sm"><h2 class="font-semibold">Before you start</h2><p class="mt-2">Create individual SUIT items and design/color variants in Textile master data. Complete or partially receive production through Production reports, inspect the lots, and receive actual packing supplies. Keep the document date in an open financial period.</p><p class="mt-2">Create a different finished item with unit PACK for the packed product. A specification describes its actual suit and supply contents; PACK is never a hidden conversion from meters or suits.</p></section>
<div class="mt-6 grid gap-4 md:grid-cols-2">@foreach([
['finished-goods.batches','1. Review completed suits and batch cost','View production progress and original output cost. Completion uses the existing Production report and creates no duplicate receipt.'],
['finished-goods.specifications','2. Publish a packing specification','Example: 1 PACK consumes 1 specific SUIT and 1 bag (PCS). Assortments may contain several design/color SKUs.'],
['finished-goods.index','3. Record packing or full recovery','Select actual input lots and receiving storage. Confirm once to consume inputs and receive outputs. Container quantities fully represent the new pack lot.'],
['finished-goods.inventory','4. Inspect finished inventory and trace value','Filter lots and quality, inspect/reject goods or record actual damage with Inventory authorization. Follow source batches, jobs and material lots.']
] as [$target,$title,$help])<section class="rounded-xl border border-line bg-white p-6"><h2 class="font-semibold">{{ $title }}</h2><p class="mt-3 text-sm leading-6 text-slate-500">{{ $help }}</p><a href="{{ route($target) }}" class="mt-4 inline-block text-sm font-medium text-brand">Open screen &rarr;</a></section>@endforeach</div>
<p class="mt-6 text-sm text-slate-500">Actual cost may include fabric, configured processing services, materials, packaging and eligible posted labor/overhead. A missing source stays missing; no estimated charge is added. Warehouse relocation uses existing Inventory transfers.</p>
@endsection
