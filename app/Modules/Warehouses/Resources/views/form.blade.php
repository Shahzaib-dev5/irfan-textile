@extends('module.core::layouts.app')
@section('title','Warehouse setup')
@section('content')
<h1 class="mb-6 text-2xl font-semibold">{{ $entry->exists ? 'Edit warehouse' : 'Add warehouse' }}</h1>
<form method="POST" class="max-w-3xl space-y-5 rounded-xl border border-line bg-white p-6" action="{{ $entry->exists ? route('warehouses.update',$entry) : route('warehouses.store') }}">
    @csrf @if($entry->exists) @method('PUT') @endif
    <p class="text-sm">Create a warehouse directly. No racks or internal locations are required.</p>
    @foreach(['code'=>'Code','name'=>'Name'] as $field=>$label)
    <label class="block text-sm">{{ $label }}<input required name="{{ $field }}" value="{{ old($field,$entry->$field) }}" maxlength="{{ $field==='code'?30:120 }}" class="mt-1 block w-full rounded-lg border-line"></label>
    @endforeach
    <label class="block text-sm">Status<select name="is_active" class="mt-1 block w-full rounded-lg border-line"><option value="1" @selected(old('is_active',$entry->is_active))>Active</option><option value="0" @selected(!old('is_active',$entry->is_active))>Inactive</option></select></label>
    <label class="block text-sm">Address<textarea name="address" class="mt-1 block w-full rounded-lg border-line" maxlength="500">{{ old('address',$entry->address) }}</textarea></label>
    <button class="rounded-lg bg-brand px-5 py-2 text-sm text-white">Save</button>
    <a class="ml-3 text-sm text-slate-500" href="{{ route('warehouses.index') }}">Cancel</a>
</form>
@endsection
