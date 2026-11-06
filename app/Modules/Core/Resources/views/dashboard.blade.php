@extends('module.core::layouts.app')
@section('title', 'Dashboard')
@section('breadcrumb', 'Dashboard')
@section('content')
<div class="mb-7"><p class="text-sm font-medium text-brand">Welcome back, {{ auth()->user()->name }}</p><h1 class="mt-1 text-2xl font-semibold tracking-tight sm:text-3xl">Business dashboard</h1><p class="mt-2 text-sm text-slate-500">Live summary from posted sales, stock, and finance records · last six months.</p></div>

<div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
    @if($canSales)<article class="rounded-2xl border border-line bg-white p-5"><p class="text-sm text-slate-500">Sales invoiced · 6 months</p><p class="mt-2 text-2xl font-semibold">PKR {{ number_format($salesTotal, 0) }}</p><p class="mt-1 text-xs text-slate-400">Posted invoices only</p></article>@endif
    @if($canInventory)<article class="rounded-2xl border border-line bg-white p-5"><p class="text-sm text-slate-500">Finished suits in stock</p><p class="mt-2 text-2xl font-semibold">{{ number_format($finishedSuits, 0) }} <span class="text-sm font-medium text-slate-500">SUIT</span></p><p class="mt-1 text-xs text-slate-400">Current quantity from stock ledger</p></article><article class="rounded-2xl border border-line bg-white p-5"><p class="text-sm text-slate-500">Active warehouses</p><p class="mt-2 text-2xl font-semibold">{{ number_format($warehouseCount) }}</p><p class="mt-1 text-xs text-slate-400">Configured storage locations</p></article>@endif
    @if($canFinance)<article class="rounded-2xl border border-line bg-white p-5"><p class="text-sm text-slate-500">Cash movement · 6 months</p><p class="mt-2 text-xl font-semibold text-emerald-700">+ PKR {{ number_format($cashInTotal, 0) }}</p><p class="mt-1 text-xl font-semibold text-rose-700">- PKR {{ number_format($cashOutTotal, 0) }}</p><p class="mt-1 text-xs text-slate-400">Posted receipts and payments</p></article>@endif
</div>

<div class="mt-5 grid gap-5 xl:grid-cols-2">
    @if($canSales)
    <section class="rounded-2xl border border-line bg-white p-5"><div class="flex items-start justify-between"><div><h2 class="font-semibold">Sales trend</h2><p class="mt-1 text-xs text-slate-500">Posted invoice total by month · PKR</p></div><a href="{{ route('sales.index') }}" class="text-sm text-brand">Open sales</a></div>
        @php($salesMax=max(1,$salesByMonth->max('amount')))
        <div class="mt-6 grid h-48 grid-cols-6 items-end gap-3">@foreach($salesByMonth as $month)<div class="flex h-full flex-col items-center justify-end gap-2"><span class="text-[10px] text-slate-500">{{ $month['amount'] ? number_format($month['amount']/1000,0).'k' : '—' }}</span><div class="w-full rounded-t-md bg-emerald-500" style="height: {{ max(3, ($month['amount'] / $salesMax) * 140) }}px"></div><span class="text-xs text-slate-500">{{ $month['label'] }}</span></div>@endforeach</div>
    </section>
    @endif
    @if($canFinance)
    <section class="rounded-2xl border border-line bg-white p-5"><div class="flex items-start justify-between"><div><h2 class="font-semibold">Cash flow</h2><p class="mt-1 text-xs text-slate-500">Posted receipts and payments · PKR</p></div><a href="{{ route('finance.index') }}" class="text-sm text-brand">Open finance</a></div>
        @php($cashMax=max(1,$cashByMonth->max(fn($m)=>max($m['in'],$m['out']))))
        <div class="mt-6 grid h-48 grid-cols-6 items-end gap-3">@foreach($cashByMonth as $month)<div class="flex h-full flex-col items-center justify-end gap-2"><div class="flex h-[140px] w-full items-end justify-center gap-1"><div title="In PKR {{ number_format($month['in'],2) }}" class="w-1/3 rounded-t bg-emerald-500" style="height: {{ max(2, ($month['in'] / $cashMax) * 130) }}px"></div><div title="Out PKR {{ number_format($month['out'],2) }}" class="w-1/3 rounded-t bg-rose-400" style="height: {{ max(2, ($month['out'] / $cashMax) * 130) }}px"></div></div><span class="text-xs text-slate-500">{{ $month['label'] }}</span></div>@endforeach</div>
        <div class="mt-3 flex gap-4 text-xs text-slate-500"><span><i class="mr-1 inline-block h-2 w-2 rounded-full bg-emerald-500"></i>Cash in</span><span><i class="mr-1 inline-block h-2 w-2 rounded-full bg-rose-400"></i>Cash out</span></div>
    </section>
    @endif
    @if($canInventory)
    <section class="rounded-2xl border border-line bg-white p-5 xl:col-span-2"><div class="flex items-start justify-between"><div><h2 class="font-semibold">Stock on hand</h2><p class="mt-1 text-xs text-slate-500">Ledger quantity grouped by each item's base unit; different units are not combined.</p></div><a href="{{ route('inventory.index') }}" class="text-sm text-brand">Open inventory</a></div>
        @if($stockByUnit->isEmpty())<p class="mt-5 text-sm text-slate-500">No posted stock movements yet.</p>@else<div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">@foreach($stockByUnit as $unit)<div class="rounded-xl bg-slate-50 p-4"><p class="text-xs uppercase tracking-wide text-slate-500">{{ $unit->code }}</p><p class="mt-1 text-xl font-semibold">{{ number_format((float)$unit->quantity, 2) }}</p></div>@endforeach</div>@endif
    </section>
    @endif
</div>

<section class="mt-7"><h2 class="mb-3 text-lg font-semibold">Open a workspace</h2><div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
@foreach([
['purchasing.documents.view','purchasing.orders.index','Purchasing','Orders, receipts, supplier returns and bills.'],
['processing.documents.view','processing.jobs.index','Processing','Configurable contractor jobs, custody and returns.'],
['production.view','production.batches.index','Production','Batch plans, actual consumption, output and progress.'],
['finished-goods.view','finished-goods.index','Finished goods','Packing, quality, finished stock and actual costing.'],
['sales.documents.view','sales.index','Wholesale sales','Customer orders, reservations, dispatch and invoices.'],
['accounting.finance.view','finance.index','Accounting & finance','Payments, receipts, journals, outstanding bills and financial statements.'],
['inventory.view','inventory.index','Inventory','Authoritative stock history, availability and warehouse movements.']
] as [$permission,$target,$title,$description])
@if(auth()->user()->hasPermissionTo($permission))<a href="{{ route($target) }}" class="rounded-2xl border border-line bg-white p-5 hover:border-emerald-300"><h3 class="font-semibold">{{ $title }}</h3><p class="mt-2 text-sm leading-6 text-slate-500">{{ $description }}</p><span class="mt-4 inline-block text-sm font-medium text-brand">Open workspace ?</span></a>@endif
@endforeach
</div></section>
<p class="mt-6 text-xs text-slate-400">Figures use posted records only. Stock quantities are grouped by base unit and never added across incompatible units.</p>
@endsection

