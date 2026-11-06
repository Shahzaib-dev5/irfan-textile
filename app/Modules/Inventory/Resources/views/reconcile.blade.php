@extends('module.core::layouts.app')
@section('title','Stock reconciliation')
@section('content')
<h1 class="text-2xl font-semibold">Stock reconciliation</h1><p class="mb-6 mt-2 text-sm text-slate-500">Compare balance and FIFO projections against permanent movement, reservation and allocation histories. Physical count differences require a count adjustment document.</p>
<div class="rounded-xl border border-line bg-white p-6">@forelse($differences as $difference)<pre class="mb-3 overflow-x-auto rounded-lg bg-amber-50 p-4 text-sm">{{ json_encode($difference,JSON_PRETTY_PRINT) }}</pre>@empty<p class="text-emerald-700">Balance and FIFO projections match their histories.</p>@endforelse</div>
@if(auth()->user()->hasPermissionTo('inventory.reconcile'))<form method="POST" action="{{ route('inventory.repair') }}" class="mt-6 max-w-2xl rounded-xl border border-line bg-white p-6">@csrf<label class="block text-sm">Reason for rebuilding derived projections<textarea name="reason" required minlength="5" maxlength="1000" class="mt-2 block w-full rounded-lg border-line"></textarea></label><button class="mt-4 rounded-lg bg-brand px-4 py-2 text-sm text-white">Rebuild from history</button></form>@endif
@endsection
