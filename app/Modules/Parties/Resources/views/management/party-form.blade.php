@extends('module.core::layouts.app')
@section('title', ($party->exists ? 'Edit ' : 'Add ').'party')
@section('breadcrumb', 'Parties')
@section('content')
@php
    $existingRoles = $party->roles->mapWithKeys(fn ($r) => [$r->type->code => $r]);
    $roleNames = ['supplier' => 'Supplier', 'vendor' => 'Vendor', 'customer' => 'Customer'];
@endphp
<div class="mx-auto max-w-7xl space-y-4">
    <div><a href="{{ route('parties.index') }}" class="text-sm text-brand">← Back to parties</a><h1 class="mt-3 text-2xl font-semibold">{{ $party->exists ? 'Edit' : 'Add' }} party</h1><p class="mt-1 text-sm text-slate-500">Enter the party once and select its business roles.</p></div>
    @if($errors->any())<div class="rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800"><p class="font-semibold">Please review these fields.</p><ul class="mt-2 list-inside list-disc">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    <form method="POST" action="{{ $party->exists ? route('parties.update', $party) : route('parties.store') }}" class="space-y-5">
        @csrf
        @if($party->exists)@method('PUT')@endif
        <section class="rounded-xl border border-line bg-white p-5">
            <h2 class="font-semibold">Party details</h2>
            <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <label class="text-sm">Code<input required name="party[code]" value="{{ old('party.code', $party->code) }}" class="mt-1 w-full rounded-lg border border-line px-3 py-2"></label>
                <label class="text-sm">Legal name<input required name="party[legal_name]" value="{{ old('party.legal_name', $party->legal_name) }}" class="mt-1 w-full rounded-lg border border-line px-3 py-2"></label>
                <label class="text-sm">Name<input name="party[trading_name]" value="{{ old('party.trading_name', $party->trading_name) }}" class="mt-1 w-full rounded-lg border border-line px-3 py-2"></label>
                <label class="text-sm">Phone<input name="party[phone]" value="{{ old('party.phone', $party->phone) }}" class="mt-1 w-full rounded-lg border border-line px-3 py-2"></label>
                <label class="text-sm">CNIC<input name="party[cnic]" value="{{ old('party.cnic', $party->cnic) }}" class="mt-1 w-full rounded-lg border border-line px-3 py-2"></label>
                <label class="flex items-center gap-2 self-center text-sm"><input type="hidden" name="party[is_active]" value="0"><input type="checkbox" name="party[is_active]" value="1" @checked(old('party.is_active', $party->is_active ?? true))> Active</label>
                <label class="text-sm lg:col-span-2">Address<input name="party[address]" value="{{ old('party.address', $party->addresses->firstWhere('is_active', true)?->address_line_1) }}" class="mt-1 w-full rounded-lg border border-line px-3 py-2"></label>
                <label class="text-sm lg:col-span-3">Party Notes<textarea name="party[notes]" rows="2" class="mt-1 w-full rounded-lg border border-line px-3 py-2">{{ old('party.notes', $party->notes) }}</textarea></label>
                <input type="hidden" name="party[party_kind]" value="{{ old('party.party_kind', $party->party_kind ?? 'organization') }}">
            </div>
        </section>
        <section class="rounded-xl border border-line bg-white p-5">
            <h2 class="font-semibold">Party type</h2>
            <div class="mt-3 grid gap-3 sm:grid-cols-3">
                @foreach($manageableRoles as $role)
                    <label class="flex items-center gap-2 rounded-lg border border-line p-3 text-sm"><input type="checkbox" name="roles[]" value="{{ $role }}" @checked(old('roles') ? in_array($role, old('roles', [])) : ($existingRoles->has($role) || !$party->exists))> {{ $roleNames[$role] }}</label>
                @endforeach
                @foreach($existingRoles as $role => $row)
                    @if(!in_array($role, $manageableRoles, true))<span class="rounded-lg bg-slate-50 p-3 text-sm">{{ $roleNames[$role] ?? ucfirst($role) }} (managed in its module)</span>@endif
                @endforeach
            </div>
            <div class="mt-3 grid gap-3 lg:grid-cols-3">
            @foreach($manageableRoles as $role)
                <details data-role-panel="{{ $role }}" class="rounded-lg border border-line p-4">
                    <summary class="cursor-pointer text-sm font-medium">{{ $roleNames[$role] }} settings</summary>
                    <div class="role-fields mt-3 grid gap-3 md:grid-cols-2">
                        <label class="block text-sm">Payment terms<select name="profiles[{{ $role }}][payment_term_id]" data-required="true" class="mt-1 w-full rounded-lg border border-line px-3 py-2"><option value="">Choose terms</option>@foreach($paymentTerms as $term)<option value="{{ $term->id }}" @selected(old('profiles.'.$role.'.payment_term_id', $existingRoles->get($role)?->{ $role.'Profile' }?->payment_term_id) == $term->id)>{{ $term->name }} ({{ $term->due_days }} days)</option>@endforeach</select></label>
                        @if($role === 'supplier')<label class="block text-sm">Purchasing notes<textarea name="profiles[supplier][purchase_notes]" class="mt-1 w-full rounded-lg border border-line px-3 py-2">{{ old('profiles.supplier.purchase_notes', $existingRoles->get('supplier')?->supplierProfile?->purchase_notes) }}</textarea></label>
                        @elseif($role === 'vendor')
                            <label class="block text-sm">Contractor notes<textarea name="profiles[vendor][contractor_notes]" class="mt-1 w-full rounded-lg border border-line px-3 py-2">{{ old('profiles.vendor.contractor_notes', $existingRoles->get('vendor')?->vendorProfile?->contractor_notes) }}</textarea></label>
                            <div class="md:col-span-2"><p class="text-sm">Processing services</p><div class="mt-2 grid gap-2 sm:grid-cols-2">@foreach($services as $service)<label class="flex items-center gap-2 rounded border border-line p-2 text-sm"><input type="checkbox" name="profiles[vendor][service_ids][]" value="{{ $service->id }}" @checked(in_array($service->id, old('profiles.vendor.service_ids', $existingRoles->get('vendor')?->vendorProfile?->services->pluck('processing_service_id')->all() ?? [])))>{{ $service->name }} ({{ $service->billingUnit->code }})</label>@endforeach</div></div>
                        @else
                            @if(auth()->user()->hasPermissionTo('sales.credit.manage'))<label class="block text-sm">Credit limit (PKR)<input type="number" min="0" step="0.0001" name="profiles[customer][credit_limit]" value="{{ old('profiles.customer.credit_limit', $existingRoles->get('customer')?->customerProfile?->credit_limit ?? '0.0000') }}" class="mt-1 w-full rounded-lg border border-line px-3 py-2"></label><label class="flex items-center gap-2 text-sm"><input type="hidden" name="profiles[customer][credit_hold]" value="0"><input type="checkbox" name="profiles[customer][credit_hold]" value="1" @checked(old('profiles.customer.credit_hold', $existingRoles->get('customer')?->customerProfile?->credit_hold ?? false))> Credit hold</label>@else<input type="hidden" name="profiles[customer][credit_limit]" value="{{ $existingRoles->get('customer')?->customerProfile?->credit_limit ?? '0' }}"><input type="hidden" name="profiles[customer][credit_hold]" value="{{ $existingRoles->get('customer')?->customerProfile?->credit_hold ? '1' : '0' }}"><p class="text-sm text-slate-500">Credit settings require administrator permission.</p>@endif
                            <label class="block text-sm">Sales notes<textarea name="profiles[customer][sales_notes]" class="mt-1 w-full rounded-lg border border-line px-3 py-2">{{ old('profiles.customer.sales_notes', $existingRoles->get('customer')?->customerProfile?->sales_notes) }}</textarea></label>
                        @endif
                    </div>
                </details>
            @endforeach
            </div>
        </section>
        <div class="flex justify-end gap-3"><a href="{{ route('parties.index') }}" class="rounded-lg border border-line px-4 py-2.5 text-sm">Cancel</a><button class="rounded-lg bg-brand px-5 py-2.5 text-sm font-semibold text-white">Save party</button></div>
    </form>
</div>
<script>
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('input[name="roles[]"][type="checkbox"]').forEach((checkbox) => {
        const panel = document.querySelector(`[data-role-panel="${checkbox.value}"]`);
        if (!panel) return;
        const sync = () => {
            panel.hidden = !checkbox.checked;
            panel.querySelectorAll('input,select,textarea').forEach((field) => {
                field.disabled = !checkbox.checked;
                if (field.dataset.required) field.required = checkbox.checked;
            });
        };
        checkbox.addEventListener('change', sync);
        sync();
    });
    @foreach($existingRoles as $role => $row)
        @if(!in_array($role, $manageableRoles, true))
            const preservedRole = document.createElement('input');
            preservedRole.type = 'hidden'; preservedRole.name = 'roles[]'; preservedRole.value = @js($role);
            document.querySelector('form').appendChild(preservedRole);
        @endif
    @endforeach
});
</script>
@endsection
