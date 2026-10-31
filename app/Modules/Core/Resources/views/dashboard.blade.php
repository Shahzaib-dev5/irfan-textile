@extends('module.core::layouts.app')
@section('title', 'Dashboard')
@section('breadcrumb', 'Workspace / Dashboard')
@section('content')
<div class="mb-8"><p class="text-sm font-medium text-brand">Welcome back, {{ auth()->user()->name }}</p><h1 class="mt-1 text-2xl font-semibold tracking-tight sm:text-3xl">Business overview</h1><p class="mt-2 text-sm text-slate-500">Open an authorized workspace to continue your textile operations.</p></div>
<section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3" aria-label="ERP modules">
@foreach([
['purchasing.documents.view','purchasing.overview','Purchasing','Orders, receipts, supplier returns and bills.'],
['processing.documents.view','processing.overview','Processing','Configurable contractor jobs, custody and returns.'],
['production.view','production.overview','Production','Batch plans, actual consumption, output and progress.'],
['finished-goods.view','finished-goods.overview','Finished goods','Packing, quality, finished stock and actual costing.'],
['sales.documents.view','sales.overview','Wholesale sales','Customer orders, reservations, dispatch and invoices.'],
['accounting.finance.view','finance.index','Accounting & finance','Payments, receipts, journals, outstanding bills and financial statements.'],
['inventory.view','inventory.index','Inventory','Authoritative stock history, availability and warehouse movements.']
] as [$permission,$target,$title,$description])
@if(auth()->user()->hasPermissionTo($permission))
<a href="{{ route($target) }}" class="rounded-2xl border border-line bg-white p-5"><h2 class="font-semibold">{{ $title }}</h2><p class="mt-2 text-sm leading-6 text-slate-500">{{ $description }}</p><span class="mt-4 inline-block text-sm font-medium text-brand">Open workspace &rarr;</span></a>
@endif
@endforeach
</section>
<p class="mt-6 text-sm text-slate-500">Only workspaces assigned to your account are shown. Contact your administrator for access or configuration. Payment collection is available to authorized Finance users.</p>
@endsection
