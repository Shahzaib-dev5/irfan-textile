@extends('module.core::layouts.app')
@section('title','Storage setup')
@section('content')
<h1 class="mb-6 text-2xl font-semibold">{{ $entry->exists ? 'Edit':'Add' }} {{ $location ? 'location':'warehouse' }}</h1>
<form method="POST" class="max-w-3xl space-y-5 rounded-xl border border-line bg-white p-6" action="{{ $entry->exists ? route('warehouses.update',['record'=>$entry->id,'location'=>(int)$location]):route('warehouses.store',['location'=>(int)$location]) }}">@csrf @if($entry->exists)@method('PUT')@endif
<div class="grid gap-5 sm:grid-cols-2">@foreach(['code'=>'Code','name'=>'Name'] as $field=>$label)<label class="text-sm">{{ $label }}<input required name="{{ $field }}" value="{{ old($field,$entry->$field) }}" class="mt-1 block w-full rounded-lg border-line" maxlength="{{ $field==='code' ? 30:120 }}"></label>@endforeach</div>
@if($location)
<label class="block text-sm">Warehouse<select required name="warehouse_id" class="mt-1 block w-full rounded-lg border-line">@foreach($warehouses as $warehouse)<option value="{{ $warehouse->id }}" @selected(old('warehouse_id',$entry->warehouse_id)==$warehouse->id)>{{ $warehouse->code }} · {{ $warehouse->name }}</option>@endforeach</select></label>
<label class="block text-sm">Storage type<select name="location_type" class="mt-1 block w-full rounded-lg border-line">@foreach(['internal','contractor','transit','quarantine'] as $type)<option @selected(old('location_type',$entry->location_type)===$type)>{{ $type }}</option>@endforeach</select></label>
<label class="block text-sm">Contractor vendor (contractor locations only)<select name="contractor_vendor_party_role_id" class="mt-1 block w-full rounded-lg border-line"><option value="">No contractor</option>@foreach($vendors as $vendor)<option value="{{ $vendor->id }}" @selected(old('contractor_vendor_party_role_id',$entry->contractor_vendor_party_role_id)==$vendor->id)>{{ $vendor->party->legal_name }} · role #{{ $vendor->id }}</option>@endforeach</select></label>
@else<label class="block text-sm">Address<textarea name="address" class="mt-1 block w-full rounded-lg border-line" maxlength="500">{{ old('address',$entry->address) }}</textarea></label>@endif
<label class="block text-sm">Status<select name="is_active" class="mt-1 block w-full rounded-lg border-line"><option value="1" @selected(old('is_active',$entry->is_active))>Active</option><option value="0" @selected(!old('is_active',$entry->is_active))>Inactive</option></select></label>
<div class="flex gap-4"><button class="rounded-lg bg-brand px-5 py-2 text-sm text-white">Save setup</button><a class="py-2 text-sm text-slate-500" href="{{ route('warehouses.index') }}">Cancel</a></div></form>
@endsection
