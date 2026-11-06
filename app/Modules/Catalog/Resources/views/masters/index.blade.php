@extends('module.core::layouts.app')
@section('title', $label)
@section('breadcrumb', 'Catalog / '.$label)
@section('content')
@php
    $filterCount = collect([request('status'), request('item_type'), request('category_id'), request('season')])->filter()->count();
@endphp
<div class="mx-auto max-w-7xl space-y-3">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div><h1 class="text-xl font-semibold sm:text-2xl">{{ $label }}</h1>@if($master==='items')<p class="mt-1 text-xs text-slate-500">Textile item = the actual SKU you purchase, process or stock. Quantities are managed in Inventory.</p>@endif</div>
        <div class="flex items-center gap-2">
            <a href="{{ route('catalog.overview') }}" class="rounded-lg border border-line bg-white px-3 py-2 text-xs font-medium">Setup guide</a>
            @can('create', \App\Modules\Catalog\CatalogRegistry::model($master))<a href="{{ route('catalog.create', ['master' => $master]) }}" class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white">{{ App\Modules\Catalog\Services\CatalogWorkspace::SCREENS[$master]['new'] }}</a>@endcan
        </div>
    </div>

    <details class="rounded-xl border border-line bg-white">
        <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-4 py-3 text-sm font-medium">
            <span>Search & filters</span>
            <span class="flex items-center gap-2 text-xs text-slate-500">@if($filterCount)<span class="rounded-full bg-emerald-50 px-2 py-1 text-brand">{{ $filterCount }} active</span>@endif <span aria-hidden="true">⌄</span></span>
        </summary>
        <form method="GET" class="grid gap-3 border-t border-line p-4 sm:grid-cols-2 lg:grid-cols-4">
            <label class="text-xs text-slate-500 sm:col-span-2">Search
                <input name="q" value="{{ request('q') }}" placeholder="Code or name" aria-label="Search records" class="mt-1 w-full rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink">
            </label>
            <label class="text-xs text-slate-500">Status
                <select name="status" aria-label="Status" class="mt-1 w-full rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink"><option value="">All statuses</option><option value="active" @selected(request('status')==='active')>Active</option><option value="inactive" @selected(request('status')==='inactive')>Inactive</option></select>
            </label>
            @if($master==='items')
                <label class="text-xs text-slate-500">Item classification
                    <select name="item_type" aria-label="Classification" class="mt-1 w-full rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink"><option value="">All classifications</option>@foreach(\App\Models\Item::TYPES as $key=>$title)<option value="{{ $key }}" @selected(request('item_type')===$key)>{{ $title }}</option>@endforeach</select>
                </label>
                <label class="text-xs text-slate-500">Category
                    <select name="category_id" aria-label="Category" class="mt-1 w-full rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink"><option value="">All categories</option>@foreach($categories as $category)<option value="{{ $category->id }}" @selected(request('category_id')==$category->id)>{{ $category->name }}</option>@endforeach</select>
                </label>
            @endif
            @if($master==='designs')
                <label class="text-xs text-slate-500">Collection / season
                    <select name="season" aria-label="Collection" class="mt-1 w-full rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink"><option value="">All collections</option>@foreach($collections as $collection)<option value="{{ $collection }}" @selected(request('season')===$collection)>{{ $collection }}</option>@endforeach</select>
                </label>
            @endif
            <div class="flex items-end gap-3 sm:col-span-2 lg:col-span-4"><button class="rounded-lg bg-brand px-4 py-2 text-sm font-medium text-white">Apply filters</button><a href="{{ route('catalog.index',['master'=>$master]) }}" class="px-2 py-2 text-sm text-slate-500">Clear</a></div>
        </form>
    </details>

    <section class="overflow-hidden rounded-xl border border-line bg-white" aria-label="{{ $label }} list">
        <table class="w-full table-fixed text-left text-xs">
                <colgroup><col class="w-[17%]"><col class="w-[27%]"><col class="w-[27%]"><col class="w-[12%]"><col class="w-[17%]"></colgroup>
                <thead class="bg-slate-50 text-[10px] uppercase tracking-wide text-slate-500"><tr><th class="px-2 py-2 sm:px-3">Code / SKU</th><th class="px-2 py-2 sm:px-3">Name</th><th class="px-2 py-2 sm:px-3">Definition</th><th class="px-2 py-2 sm:px-3">Status</th><th class="px-2 py-2 text-right sm:px-3">Actions</th></tr></thead>
                <tbody class="divide-y divide-line">
                    @forelse($records as $row)
                        <tr class="hover:bg-slate-50">
                            <td class="px-2 py-2 align-middle sm:px-3"><span class="block truncate font-mono text-[10px]" title="{{ $row->sku ?? $row->code ?? $row->item?->sku }}">{{ $row->sku ?? $row->code ?? $row->item?->sku }}</span></td>
                            <td class="px-2 py-2 align-middle sm:px-3"><span class="block truncate font-medium" title="{{ $row->name ?? $row->item?->name }}">{{ $row->name ?? $row->item?->name }}</span></td>
                            <td class="px-2 py-2 align-middle text-[10px] leading-4 text-slate-500 sm:px-3">
                                @switch($master)
                                    @case('items')<span class="block">{{ \App\Models\Item::TYPES[$row->item_type] }} · {{ $row->baseUnit->code }}</span><span class="block">{{ $row->category->name }} · {{ $row->is_stocked?($row->track_rolls?'Individual rolls':'Lot / quantity'):'Nonstock' }}</span>@break
                                    @case('units'){{ ucfirst($row->dimension) }} · {{ $row->decimal_places }} decimal places @break
                                    @case('categories'){{ $row->parent?->name ?? 'Top-level category' }} @break
                                    @case('designs'){{ $row->season ?? 'No collection assigned' }} @break
                                    @case('colors')@if($row->hex_code)<span class="mr-2 inline-block h-4 w-4 rounded border border-line align-middle" style="background-color: {{ preg_match('/^#[0-9A-Fa-f]{6}$/', $row->hex_code) ? $row->hex_code : '#ffffff' }}"></span>{{ $row->hex_code }}@else — @endif @break
                                    @case('variants')<span class="block">{{ $row->design->name }} · {{ $row->color->name }}</span><span class="block">{{ $row->configuration_code }} · {{ $row->piece_count }} pieces</span>@break
                                @endswitch
                            </td>
                            <td class="px-2 py-2 align-middle sm:px-3"><span class="inline-flex rounded-full px-1.5 py-1 text-[9px] {{ $row->is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500' }}">{{ $row->is_active ? 'Active' : 'Inactive' }}</span></td>
                            <td class="px-2 py-2 text-right align-middle whitespace-nowrap sm:px-3"><a href="{{ route('catalog.show',['master'=>$master,'record'=>$row->id]) }}" class="text-[10px] font-medium text-brand">View</a>@can('update',$row)<a href="{{ route('catalog.edit',['master'=>$master,'record'=>$row->id]) }}" class="ml-2 text-[10px] font-medium text-brand">Edit</a>@endcan</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-5 py-10 text-center text-sm text-slate-500">No {{ strtolower($label) }} match these filters. Clear the filters or add a new definition.</td></tr>
                    @endforelse
                </tbody>
        </table>
        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-line px-4 py-3 text-xs text-slate-500">
            <p>@if($records->total()) Showing {{ $records->firstItem() }}–{{ $records->lastItem() }} of {{ $records->total() }} @else No records @endif</p>
            @if($records->hasPages())<div>{{ $records->onEachSide(1)->links() }}</div>@endif
        </div>
    </section>
</div>
@endsection
