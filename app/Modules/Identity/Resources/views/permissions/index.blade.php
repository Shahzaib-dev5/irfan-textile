@extends('module.core::layouts.app')
@section('title', 'Permission catalog')
@section('breadcrumb', 'Identity / Permissions')
@section('content')
<div class="mb-6"><h1 class="text-2xl font-semibold">Permission catalog</h1><p class="mt-1 text-sm text-slate-500">Registered application capabilities. Assign them through authorization roles.</p></div>
<div class="grid gap-4 lg:grid-cols-2">@foreach($permissions as $module => $items)<section class="rounded-xl border border-line bg-white p-5"><h2 class="mb-4 font-semibold">{{ str($module)->replace('-', ' ')->title() }}</h2><div class="divide-y divide-line">@foreach($items as $permission)<div class="flex items-start justify-between gap-4 py-3"><div><code class="text-xs font-semibold text-brand">{{ $permission->code }}</code><p class="mt-1 text-sm text-slate-500">{{ $permission->description }}</p></div><span class="whitespace-nowrap text-xs text-slate-400">{{ $permission->roles_count }} roles</span></div>@endforeach</div></section>@endforeach</div>
@endsection
