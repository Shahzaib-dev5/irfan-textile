@extends('module.core::layouts.app')
@section('title', 'Party management')
@section('breadcrumb', 'Parties')
@section('content')
<div class="mx-auto max-w-7xl space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div><p class="text-xs font-semibold uppercase tracking-widest text-brand">Shared directory</p><h1 class="mt-1 text-2xl font-semibold">Parties</h1><p class="mt-1 text-sm text-slate-500">Manage suppliers, vendors and customers from one shared record.</p></div>
        @if(auth()->user()->hasPermissionTo('purchasing.supplier.manage') || auth()->user()->hasPermissionTo('processing.vendor.manage') || auth()->user()->hasPermissionTo('sales.customer.manage'))
            <a href="{{ route('parties.create') }}" class="rounded-lg bg-brand px-4 py-2.5 text-sm font-semibold text-white">Add party</a>
        @endif
    </div>
    <form method="GET" class="flex flex-wrap gap-3 rounded-xl border border-line bg-white p-4">
        <input name="q" value="{{ request('q') }}" placeholder="Search name, code or phone" class="min-w-64 flex-1 rounded-lg border border-line px-3 py-2 text-sm">
        <select name="status" class="rounded-lg border border-line px-3 py-2 text-sm"><option value="">All statuses</option><option value="active" @selected(request('status')==='active')>Active</option><option value="inactive" @selected(request('status')==='inactive')>Inactive</option></select>
        <button class="rounded-lg border border-line px-4 py-2 text-sm">Search</button>
    </form>
    <div class="overflow-hidden rounded-xl border border-line bg-white"><div class="overflow-x-auto"><table class="w-full text-left text-sm">
        <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500"><tr><th class="px-5 py-3">Party</th><th class="px-5 py-3">Code</th><th class="px-5 py-3">Phone / email</th><th class="px-5 py-3">Business roles</th><th class="px-5 py-3">Status</th><th class="px-5 py-3 text-right">Actions</th></tr></thead>
        <tbody class="divide-y divide-line">
        @forelse($parties as $party)
            @php($visible=$party->roles->filter(fn($r)=>in_array($r->type->code,$visibleRoles,true)))
            @continue($visible->isEmpty())
            @php($canManage=$party->roles->every(fn($r)=>auth()->user()->hasPermissionTo(match($r->type->code){'supplier'=>'purchasing.supplier.manage','vendor'=>'processing.vendor.manage','customer'=>'sales.customer.manage',default=>'invalid.permission'})))
            <tr>
                <td class="px-5 py-4"><div class="font-medium">{{ $party->trading_name ?: $party->legal_name }}</div><div class="text-xs text-slate-500">{{ $party->legal_name }}</div></td>
                <td class="px-5 py-4 font-mono text-xs">{{ $party->code }}</td>
                <td class="px-5 py-4">{{ $party->phone ?: '—' }}<div class="text-xs text-slate-500">{{ $party->email }}</div></td>
                <td class="px-5 py-4"><div class="flex flex-wrap gap-1">@foreach($visible as $role)<span class="rounded-full bg-slate-100 px-2 py-1 text-xs">{{ ucfirst($role->type->code) }}{{ !$role->is_active ? ' (inactive)' : '' }}</span>@endforeach</div></td>
                <td class="px-5 py-4"><span class="rounded-full px-2.5 py-1 text-xs {{ $party->is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500' }}">{{ $party->is_active ? 'Active' : 'Inactive' }}</span></td>
                <td class="px-5 py-4 text-right">
                    @if($canManage)
                        <a href="{{ route('parties.edit',$party) }}" class="font-medium text-brand">Edit</a>
                        <form method="POST" action="{{route('parties.deactivate',$party)}}" class="inline" onsubmit="return confirm('Deactivate this party and all its roles? History will be retained.')">@csrf @method('DELETE')<button class="ml-3 text-rose-700">Delete</button></form>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="6" class="px-5 py-12 text-center text-slate-500">No parties match this search.</td></tr>
        @endforelse
        </tbody>
    </table></div><div class="border-t border-line px-5 py-4">{{ $parties->links() }}</div></div>
</div>
@endsection
