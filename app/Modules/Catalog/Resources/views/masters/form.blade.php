@extends('module.core::layouts.app')
@section('title', $label)
@section('breadcrumb', 'Catalog / '.$label)
@section('content')
<div class="mx-auto max-w-6xl space-y-6">
    @include('module.catalog::masters.nav')
    <div class="flex flex-wrap items-end justify-between gap-3"><div><a href="{{ route('catalog.index', ['master'=>$master]) }}" class="text-sm text-brand">Back to {{ $label }}</a><h1 class="mt-3 text-2xl font-semibold">{{ $master==='items'?($readOnly?$record->name:($record->exists?'Edit textile item':'New textile item')):($readOnly?'View ':($record->exists?'Edit ':'New ')).$label }}</h1></div>@if($readOnly)@can('update',$record)<a class="rounded-lg bg-brand px-4 py-2 text-sm text-white" href="{{route('catalog.edit',['master'=>$master,'record'=>$record->id])}}">Edit record</a>@endcan @endif</div>
    @if($errors->any())<div role="alert" class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800"><p class="font-semibold">Please correct the following:</p><ul class="mt-2 list-inside list-disc">@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></div>@endif
    <form method="POST" action="{{ $record->exists ? route('catalog.update',['master'=>$master,'record'=>$record->id]) : route('catalog.store',['master'=>$master]) }}" class="space-y-6" @if($master==='items') x-data="catalogItem(@js($itemConfig))" @endif>
        @csrf @if($record->exists)@method('PUT')@endif
        @if($master==='items')
        @include('module.catalog::masters.item-fields')
        @else
        <section class="rounded-xl border border-line bg-white p-6"><h2 class="font-semibold">{{ $record->exists?'Definition details':'Enter the details' }}</h2>{{-- <p class="mt-2 text-sm leading-6 text-slate-500">{{ App\Modules\Catalog\Services\CatalogWorkspace::SCREENS[$master]['help'] }}</p> --}}
            @if($master==='units')<p class="mt-2 text-sm text-slate-500">Choose a stable code and measurement basis. Count units use zero decimal places; existing unit codes and measurement definitions are protected.</p>@endif
            @if($master==='variants')<p class="mt-2 text-sm text-slate-500">A finished SKU has one design/color configuration. Piece count describes the fabric components of one complete suit.</p>@endif
            <div class="mt-5 grid gap-5 sm:grid-cols-2">@foreach($fields as $name=>$field)<x-erp.master-field :name="$name" :field="$field" :value="$record->getAttribute($name)" :options="$choices[$name] ?? []" :read-only="$readOnly" :help="App\Modules\Catalog\Services\CatalogWorkspace::HELP[$name] ?? null" :locked="$master==='units' && $record->exists && in_array($name,['code','dimension','decimal_places'])" />@endforeach</div>
            <datalist id="collection-labels">@foreach($collections as $collection)<option value="{{ $collection }}">@endforeach</datalist>
        </section>

        @endif
        @unless($readOnly)<div class="flex justify-end gap-3"><a href="{{route('catalog.index',['master'=>$master])}}" class="rounded-lg border border-line px-4 py-2.5 text-sm">Cancel</a><button @if($master==='items') :disabled="!type" @endif class="rounded-lg bg-brand px-5 py-2.5 text-sm font-semibold text-white disabled:opacity-50">{{ $master==='items'?'Save item':'Save record' }}</button></div>@endunless
    </form>
    @if($master==='items' && $record->exists)
<section class="rounded-xl border border-line bg-white p-5"><h2 class="font-semibold">Need another measurement unit?</h2><p class="mt-2 text-sm text-slate-500">Most fabric items only need meters. Add compatible alternate units on the separate conversion screen when required.</p><a class="mt-3 inline-block text-sm font-medium text-brand" href="{{ route('catalog.conversions.index',$record) }}">{{ $readOnly?'View':'Manage' }} alternate units &rarr;</a></section>
<section class="rounded-xl border border-line bg-white p-5"><h2 class="font-semibold">What to do next</h2><p class="mt-2 text-sm text-slate-500">The item definition is saved separately from stock quantities.</p><div class="mt-4 flex flex-wrap gap-3">
@if($record->item_type==='finished')<a class="rounded-lg border border-line px-4 py-2 text-sm text-brand" href="{{ route('catalog.index',['master'=>'variants']) }}">Define design / color variant</a>@endif
@if(in_array($record->item_type,['grey_fabric','trim','packaging']) && auth()->user()->hasPermissionTo('purchasing.documents.view'))<a class="rounded-lg border border-line px-4 py-2 text-sm text-brand" href="{{ route('purchasing.orders.index') }}">Open purchase orders</a>@endif
@if($record->isFabric() && auth()->user()->hasPermissionTo('processing.documents.view'))<a class="rounded-lg border border-line px-4 py-2 text-sm text-brand" href="{{ route('processing.routes') }}">Use in a processing route</a>@endif
@if($record->is_stocked && auth()->user()->hasPermissionTo('inventory.view'))<a class="rounded-lg border border-line px-4 py-2 text-sm text-brand" href="{{ route('inventory.index') }}">Check stock in Inventory</a>@endif
</div></section>
@endif
</div>
@endsection
