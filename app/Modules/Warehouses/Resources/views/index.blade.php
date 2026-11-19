@extends('module.core::layouts.app')
@section('title','Warehouses')
@section('content')
<div class="mb-6 flex items-center justify-between gap-4">
    <div><h1 class="text-2xl font-semibold">Warehouses</h1><p class="mt-2 text-sm text-slate-600">Add your warehouses and select them directly when receiving or moving stock. No internal locations are needed.</p></div>
    @can('create',App\Models\Warehouse::class)<a class="rounded-lg bg-brand px-4 py-2 text-sm text-white" href="{{ route('warehouses.create') }}">Add warehouse</a>@endcan
</div>
<div class="space-y-4">
@forelse($warehouses as $warehouse)
<section class="flex items-start justify-between gap-4 rounded-xl border border-line bg-white p-6">
    <div><h2 class="font-semibold">{{ $warehouse->code }} · {{ $warehouse->name }}</h2><p class="mt-2 text-sm">{{ $warehouse->address }}</p><p class="mt-2 text-sm text-slate-500">{{ $warehouse->is_active ? 'Active' : 'Inactive' }}</p></div>
    @can('create',App\Models\Warehouse::class)<a class="text-sm text-brand" href="{{ route('warehouses.edit',$warehouse) }}">Edit</a>@endcan
</section>
@empty<p class="rounded-xl border border-line bg-white p-6">Add a warehouse before receiving stock.</p>@endforelse
</div><div class="mt-5">{{ $warehouses->links() }}</div>
@endsection
