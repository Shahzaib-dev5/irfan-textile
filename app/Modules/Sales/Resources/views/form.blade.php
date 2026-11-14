@extends('module.core::layouts.app')
@section('title','New sales '.$kind)
@section('content')
<h1 class="text-2xl font-semibold">{{ $kind==='order' ? 'New sale order' : 'New '.$kind }}</h1><p class="mt-2 text-sm text-slate-500">Select the customer first. Enter actual quantities, check the source and confirm once. Payments are recorded separately by an authorized Finance user. Tax calculations are not enabled.</p>
<form method="get" class="mt-6 rounded-xl border border-line bg-white p-5"><input type="hidden" name="kind" value="{{ $kind }}">@if($directDispatch)<input type="hidden" name="direct" value="1">@endif<h2 class="font-semibold">1. Choose customer and source</h2>@if($kind==='dispatch')<div class="mt-3 flex gap-2"><a class="rounded-lg border border-line px-3 py-2 text-sm" href="{{ route('sales.create',['kind'=>'dispatch','customer'=>$customerId]) }}">From order</a><a class="rounded-lg border border-line px-3 py-2 text-sm" href="{{ route('sales.create',['kind'=>'dispatch','customer'=>$customerId,'direct'=>1]) }}">Direct dispatch</a></div><p class="mt-2 text-sm text-slate-500">{{ $directDispatch?'Dispatch actual released finished stock directly; no sales order is created. Enter the agreed selling price.':'Select an existing confirmed customer order.' }}</p>@endif<div class="mt-4 grid gap-4 sm:grid-cols-2"><label class="text-sm">Customer<select id="customer-select" required name="customer" class="mt-1 w-full rounded-lg border border-line px-3 py-2"><option value="">Choose customer</option>@foreach($customers as $c)<option value="{{ $c->id }}" @selected($customerId===$c->id)>{{ $c->party->legal_name }} / {{ $c->party->code }} / {{ $c->customerProfile->paymentTerm->name }} / {{ $c->customerProfile->credit_hold?'CREDIT HOLD':'limit PKR '.$c->customerProfile->credit_limit }}</option>@endforeach</select></label>@if($kind==='credit')<label class="text-sm">Confirmed invoice<select name="invoice" class="mt-1 w-full rounded-lg border border-line px-3 py-2"><option value="">Choose invoice after loading customer</option>@foreach($invoices as $inv)<option value="{{ $inv->document_id }}" @selected($invoiceId===$inv->document_id)>{{ $inv->document->number }} / PKR {{ $inv->net_amount }}</option>@endforeach</select></label>@endif</div><button class="mt-4 rounded-lg border border-line px-4 py-2 text-sm">Load customer sources</button></form>
@if(auth()->user()->hasPermissionTo('sales.customer.manage'))
<div class="mt-3"><button type="button" id="open-customer-dialog" class="rounded-lg border border-line px-4 py-2 text-sm">+ Add customer</button></div>
<dialog id="customer-dialog" class="w-full max-w-3xl rounded-xl border border-line bg-white p-0 text-slate-900 shadow-xl backdrop:bg-slate-950/60">
    <form id="quick-customer-form" class="space-y-4 p-5">
        <div class="flex items-start justify-between gap-4"><div><h2 class="text-xl font-semibold">Add customer</h2><p class="mt-1 text-sm text-slate-500">Customer details are saved to Parties and added to this sale order.</p></div><button type="button" id="close-customer-dialog" class="rounded-lg border border-line px-3 py-1.5">Close</button></div>
        <p id="quick-customer-error" class="hidden rounded-lg bg-rose-50 p-3 text-sm text-rose-700" role="alert"></p>
        <section class="rounded-lg border border-line p-4"><h3 class="font-medium">Party details</h3><input type="hidden" name="existing_party_id" value=""><label class="mt-3 block text-sm">Use an existing party identity (optional)<select id="existing-customer-party" class="mt-1 w-full rounded-lg border border-line px-3 py-2"><option value="">Create a new party identity</option>@foreach($eligibleParties as $existing)<option value="{{ $existing->id }}" data-party="{{ json_encode($existing->only(['code','legal_name','trading_name','party_kind','ntn','sales_tax_registration','cnic','email','phone','notes'])) }}" data-address="{{ $existing->addresses->first()?->address_line_1 }}">{{ $existing->legal_name }} / {{ $existing->code }} / {{ $existing->roles->pluck('type.name')->join(', ') }}</option>@endforeach</select><span class="mt-1 block text-xs text-slate-500">This assigns the Customer role to the shared party and preserves its saved details.</span></label><div class="mt-3 grid gap-3 sm:grid-cols-2">
            <label class="text-sm">Code<input required maxlength="40" name="party[code]" class="mt-1 w-full rounded-lg border border-line px-3 py-2"></label>
            <label class="text-sm">Legal name<input required maxlength="200" name="party[legal_name]" class="mt-1 w-full rounded-lg border border-line px-3 py-2"></label>
            <label class="text-sm">Party type<select name="party[party_kind]" class="mt-1 w-full rounded-lg border border-line px-3 py-2"><option value="organization">Organization</option><option value="individual">Individual</option></select></label>
            <label class="text-sm">Name<input maxlength="200" name="party[trading_name]" class="mt-1 w-full rounded-lg border border-line px-3 py-2"></label>
            <label class="text-sm">Phone<input maxlength="40" name="party[phone]" class="mt-1 w-full rounded-lg border border-line px-3 py-2"></label>
            <label class="text-sm">Email<input type="email" maxlength="254" name="party[email]" class="mt-1 w-full rounded-lg border border-line px-3 py-2"></label>
            <label class="text-sm">NTN<input maxlength="30" name="party[ntn]" class="mt-1 w-full rounded-lg border border-line px-3 py-2"></label><label class="text-sm">Sales tax registration<input maxlength="40" name="party[sales_tax_registration]" class="mt-1 w-full rounded-lg border border-line px-3 py-2"></label>
            <label class="text-sm">CNIC<input maxlength="25" name="party[cnic]" class="mt-1 w-full rounded-lg border border-line px-3 py-2"></label>
            <label class="text-sm sm:col-span-2">Address<input name="party[address]" class="mt-1 w-full rounded-lg border border-line px-3 py-2"></label>
            <label class="text-sm sm:col-span-2">Party notes<textarea rows="2" name="party[notes]" class="mt-1 w-full rounded-lg border border-line px-3 py-2"></textarea></label>
            <input type="hidden" name="party[is_active]" value="0">
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="party[is_active]" value="1" checked> Active</label>
        </div></section>
        <section class="rounded-lg border border-line p-4"><h3 class="font-medium">Customer settings</h3><div class="mt-3 grid gap-3 sm:grid-cols-2">
            <label class="text-sm">Payment terms<select required name="profiles[customer][payment_term_id]" class="mt-1 w-full rounded-lg border border-line px-3 py-2"><option value="">Choose terms</option>@foreach($terms as $term)<option value="{{ $term->id }}">{{ $term->name }} ({{ $term->due_days }} days)</option>@endforeach</select></label>
            @if(auth()->user()->hasPermissionTo('sales.credit.manage'))<label class="text-sm">Credit limit (PKR)<input required type="number" min="0" step="0.0001" name="profiles[customer][credit_limit]" value="0" class="mt-1 w-full rounded-lg border border-line px-3 py-2"></label><input type="hidden" name="profiles[customer][credit_hold]" value="0"><label class="flex items-center gap-2 text-sm"><input type="checkbox" name="profiles[customer][credit_hold]" value="1"> Credit hold</label>@else<input type="hidden" name="profiles[customer][credit_limit]" value="0"><input type="hidden" name="profiles[customer][credit_hold]" value="0"><p class="text-sm text-slate-500">Credit settings default to zero and require administrator permission.</p>@endif
            <label class="text-sm sm:col-span-2">Sales notes<textarea rows="2" name="profiles[customer][sales_notes]" class="mt-1 w-full rounded-lg border border-line px-3 py-2"></textarea></label>
        </div></section>
        <div class="flex justify-end gap-3"><button type="button" id="cancel-customer-dialog" class="rounded-lg border border-line px-4 py-2">Cancel</button><button id="save-quick-customer" class="rounded-lg bg-brand px-5 py-2 text-white">Save and continue</button></div>
    </form>
</dialog>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const dialog = document.getElementById('customer-dialog');
    const form = document.getElementById('quick-customer-form');
    const errorBox = document.getElementById('quick-customer-error');
    const saveButton = document.getElementById('save-quick-customer');
    const pickerForm = document.querySelector('form[method="get"]');
    const customerSelect = document.getElementById('customer-select');
    const existingPartySelect = document.getElementById('existing-customer-party');
    existingPartySelect?.addEventListener('change', () => {
        const selected = existingPartySelect.selectedOptions[0];
        form.elements.namedItem('existing_party_id').value = existingPartySelect.value;
        const identityFields = ['code', 'legal_name', 'trading_name', 'party_kind', 'ntn', 'sales_tax_registration', 'cnic', 'email', 'phone', 'notes'];
        identityFields.forEach((key) => {
            const field = form.querySelector(`[name="party[${key}]"]`);
            if (field && field.type !== 'hidden') field.readOnly = Boolean(existingPartySelect.value);
        });
        const address = form.querySelector('[name="party[address]"]');
        if (!existingPartySelect.value) {
            identityFields.forEach((key) => {
                const field = form.querySelector(`[name="party[${key}]"]`);
                if (field && field.type !== 'hidden') field.value = key === 'party_kind' ? 'organization' : '';
            });
            address.value = '';
            return;
        }
        const identity = JSON.parse(selected.dataset.party);
        Object.entries(identity).forEach(([key, value]) => {
            const field = form.querySelector(`[name="party[${key}]"]`);
            if (field) field.value = value ?? '';
        });
        address.value = selected.dataset.address ?? '';
    });
    document.getElementById('open-customer-dialog')?.addEventListener('click', () => dialog.showModal());
    document.getElementById('close-customer-dialog')?.addEventListener('click', () => dialog.close());
    document.getElementById('cancel-customer-dialog')?.addEventListener('click', () => dialog.close());
    form?.addEventListener('submit', async (event) => {
        event.preventDefault();
        errorBox.classList.add('hidden');
        saveButton.disabled = true;
        saveButton.textContent = 'Saving…';
        try {
            const response = await fetch(@js(route('sales.customers.quick-store')), {
                method: 'POST',
                headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content},
                body: new FormData(form),
            });
            const payload = await response.json();
            if (!response.ok) {
                const messages = payload.errors ? Object.values(payload.errors).flat() : [payload.message || 'Could not save this customer.'];
                throw new Error(messages.join(' '));
            }
            customerSelect.add(new Option(payload.label, payload.id, true, true));
            const invoiceSelect = pickerForm.querySelector('[name="invoice"]');
            if (invoiceSelect) invoiceSelect.value = '';
            dialog.close();
            pickerForm.requestSubmit();
        } catch (error) {
            errorBox.textContent = error.message;
            errorBox.classList.remove('hidden');
        } finally {
            saveButton.disabled = false;
            saveButton.textContent = 'Save and continue';
        }
    });
});
</script>
@endif
@if(!$customerId)<p class="mt-5 rounded-lg bg-amber-50 p-4 text-sm">Choose a customer above to continue. @if(auth()->user()->hasPermissionTo("sales.customer.manage"))Missing customer? Use Add customer here; credit settings require administrator access.@elseMissing customer? Ask a user with Customer management access to add them.@endif</p>
@elseif($kind==='credit' && !$invoiceId)<p class="mt-5 rounded-lg bg-amber-50 p-4 text-sm">Now choose the confirmed invoice above and load its source matches.</p>
@else
@php
$selectedCustomer=$customers->firstWhere('id',$customerId);
$seed=old('lines',[]);
@endphp
<form method="post" action="{{ route('sales.store') }}" x-data="salesForm(@js($seed),@js($sources),@js($positions),@js(route('sales.items')),@js(old('warehouse_id','')))" class="mt-6 space-y-5">@csrf<input type="hidden" name="kind" value="{{ $kind }}">@if($directDispatch)<input type="hidden" name="direct_dispatch" value="1">@endif<input type="hidden" name="customer_party_role_id" value="{{ $customerId }}"><input type="hidden" name="idempotency_key" value="{{ old('idempotency_key',(string)\Illuminate\Support\Str::uuid()) }}">@if($kind==='credit')<input type="hidden" name="sales_invoice_id" value="{{ $invoiceId }}">@endif
<section class="rounded-xl border border-line bg-white p-5"><h2 class="font-semibold">2. {{ $selectedCustomer->party->legal_name }} / document details</h2><p class="mt-2 text-sm text-slate-500">{{ $selectedCustomer->party->phone }} &middot; {{ $selectedCustomer->customerProfile->paymentTerm->name }}</p><div class="mt-4 grid gap-4 sm:grid-cols-2"><label class="text-sm">Document date<input required type="date" name="document_date" value="{{ old('document_date',\App\Modules\Inventory\Services\InventoryGuard::businessDate()) }}" class="mt-1 w-full rounded-lg border border-line px-3 py-2"></label>
@if(in_array($kind,['dispatch','return']))<label class="text-sm">Warehouse{{ $kind==='dispatch'?' (optional limit)':'' }}<select {{ $kind==='return'?'required':'' }} name="warehouse_id" @if($kind==='dispatch')x-model="warehouseId" @change="setWarehouse($event.target.value)"@endif class="mt-1 w-full rounded-lg border border-line px-3 py-2"><option value="">{{ $kind==='dispatch'?'All warehouses — choose per line':'Choose warehouse' }}</option>@foreach($warehouses as $w)<option value="{{ $w->id }}" @selected(old('warehouse_id')==$w->id)>{{ $w->code }} / {{ $w->name }}</option>@endforeach</select>@if($kind==='dispatch')<span class="mt-1 block text-xs text-slate-500">Leave blank to pick each line from any warehouse. Each source lot shows its warehouse and location.</span>@endif</label>@endif
@if(in_array($kind,['order','invoice']))<label class="text-sm">Payment terms<select name="payment_term_id" class="mt-1 w-full rounded-lg border border-line px-3 py-2">@foreach($terms as $term)<option value="{{ $term->id }}" @selected(old('payment_term_id',$selectedCustomer->customerProfile->payment_term_id)==$term->id)>{{ $term->name }}</option>@endforeach</select></label><label class="text-sm">Customer reference<input name="customer_reference" value="{{ old('customer_reference') }}" class="mt-1 w-full rounded-lg border border-line px-3 py-2"></label>@endif
@if($kind==='order')<label class="text-sm">Required delivery date (optional)<input type="date" name="required_on" value="{{ old('required_on') }}" class="mt-1 w-full rounded-lg border border-line px-3 py-2"></label>@endif
@if(in_array($kind,['order','dispatch','invoice']))<label class="text-sm sm:col-span-2">{{ $kind==='invoice'?'Billing':'Delivery' }} address<input required name="{{ $kind==='invoice'?'billing_address_snapshot':'ship_to_snapshot' }}" value="{{ old($kind==='invoice'?'billing_address_snapshot':'ship_to_snapshot',$selectedCustomer->party->legal_name) }}" class="mt-1 w-full rounded-lg border border-line px-3 py-2"></label>@endif
@if($kind==='invoice')<label class="text-sm">Due date (optional; defaults from terms)<input type="date" name="due_on" value="{{ old('due_on') }}" class="mt-1 w-full rounded-lg border border-line px-3 py-2"></label>@endif
@if($kind==='dispatch')<label class="text-sm">Carrier (optional)<input name="carrier" value="{{ old('carrier') }}" class="mt-1 w-full rounded-lg border border-line px-3 py-2"></label><label class="text-sm">Tracking reference (optional)<input name="tracking_reference" value="{{ old('tracking_reference') }}" class="mt-1 w-full rounded-lg border border-line px-3 py-2"></label>@endif
@if(in_array($kind,['return','credit']))<label class="text-sm sm:col-span-2">Reason<textarea required minlength="5" name="reason" class="mt-1 w-full rounded-lg border border-line px-3 py-2">{{ old('reason') }}</textarea></label>@endif
@if($kind==='credit')<label class="text-sm">Credit type<select name="credit_kind" class="mt-1 w-full rounded-lg border border-line px-3 py-2"><option value="physical_return">Physical return credit</option><option value="price_adjustment" @selected(old('credit_kind')==='price_adjustment')>Price adjustment (zero quantity)</option></select></label>@endif
</div></section>
@if($kind==='order')<section class="rounded-xl border border-line bg-white p-5"><label class="text-sm">Find finished items by SKU or name<input x-model="query" @input.debounce.300ms="search()" placeholder="Type SKU or item name" class="mt-1 w-full rounded-lg border border-line px-3 py-2"></label><button type="button" @click="search()" class="mt-2 rounded-lg border border-line px-3 py-2 text-sm">Search items</button><p class="mt-2 text-sm text-rose-600" x-text="searchError" role="status"></p><p class="mt-2 text-xs text-slate-500">Search shows up to 30 matching finished items. Choose an item separately on each line below.</p></section>@elseif(count($sources)===0)<p class="rounded-lg bg-amber-50 p-4 text-sm">No eligible sources. Confirm the previous step for this customer first. Orders must have an open balance; invoices need unbilled dispatch quantities.</p>@endif
<template x-for="(line,index) in lines" :key="line.key"><section class="rounded-xl border border-line bg-white p-5"><div class="flex items-center justify-between"><h2 class="font-semibold" x-text="'Line '+(index+1)"></h2><button type="button" @click="remove(line.key)" class="text-sm text-rose-700" :disabled="lines.length===1">Remove line</button></div><div class="mt-4 grid gap-4 sm:grid-cols-2">
@if($kind==='order')
<label class="text-sm sm:col-span-2">Finished item<select required :name="'lines['+index+'][item_id]'" x-model="line.item_id" @change="choose(line,results.find(item=>String(item.id)===line.item_id))" class="mt-1 w-full rounded-lg border border-line px-3 py-2"><option value="">Search and choose finished item</option><template x-if="line.item_id && !results.some(item=>String(item.id)===String(line.item_id))"><option :value="line.item_id" x-text="line.item_label || 'Retained item #'+line.item_id"></option></template><template x-for="item in results" :key="item.id"><option :value="item.id" x-text="item.label"></option></template></select></label>
<label class="text-sm">Quantity<input required min="0.000001" step="0.000001" type="number" :name="'lines['+index+'][quantity]'" x-model="line.quantity" class="mt-1 w-full rounded-lg border border-line px-3 py-2"></label><label class="text-sm">Sales unit<select required :name="'lines['+index+'][unit_id]'" x-model="line.unit_id" class="mt-1 w-full rounded-lg border border-line px-3 py-2"><template x-if="line.unit_id && !(line.units || []).length"><option :value="line.unit_id" x-text="'Retained unit #'+line.unit_id"></option></template><template x-for="unit in (line.units || [])" :key="unit.id"><option :value="unit.id" x-text="unit.code"></option></template></select></label><label class="text-sm">PKR per sales unit<input required min="0" step="0.00000001" type="number" :name="'lines['+index+'][unit_price]'" x-model="line.unit_price" class="mt-1 w-full rounded-lg border border-line px-3 py-2"></label><label class="text-sm">Total line discount PKR<input required min="0" step="0.0001" type="number" :name="'lines['+index+'][discount_amount]'" x-model="line.discount_amount" class="mt-1 w-full rounded-lg border border-line px-3 py-2"></label><p class="text-sm sm:col-span-2">Estimated net PKR <strong x-text="estimate(line)"></strong>. Final exact amounts are validated on confirmation.</p>
@else
@php
$sourceField=match($kind){'dispatch'=>$directDispatch?'item_id':'sales_order_line_id','credit'=>'sales_invoice_dispatch_match_id',default=>'sales_dispatch_line_id'};
@endphp
<label class="text-sm sm:col-span-2">{{ $kind==='dispatch'?($directDispatch?'Finished item':'Confirmed order line'):($kind==='credit'?'Original invoice match':'Confirmed dispatch line') }}<select required :name="'lines['+index+'][{{ $sourceField }}]'" x-model="line.{{ $sourceField }}" @if($directDispatch) @change="line.item_id=line.{{ $sourceField }}" @endif class="mt-1 w-full rounded-lg border border-line px-3 py-2"><option value="">Choose source</option><template x-for="source in sources" :key="source.id"><option :value="source.id" x-text="source.label"></option></template></select></label>
@if($kind!=='credit')<label class="text-sm">Actual quantity (base unit: whole SUIT or PACK)<input required min="1" step="1" type="number" :name="'lines['+index+'][base_quantity]'" x-model="line.base_quantity" class="mt-1 w-full rounded-lg border border-line px-3 py-2"></label>@endif
@if($kind==='dispatch')@if($directDispatch)<label class="text-sm">Selling price PKR per suit/pack<input required min="0" step="0.00000001" type="number" :name="'lines['+index+'][unit_price]'" x-model="line.unit_price" class="mt-1 w-full rounded-lg border border-line px-3 py-2"></label><label class="text-sm">Line discount PKR<input required min="0" step="0.0001" type="number" :name="'lines['+index+'][discount_amount]'" x-model="line.discount_amount" class="mt-1 w-full rounded-lg border border-line px-3 py-2"></label>@endif<label class="text-sm">Source lot / location<select required :name="'lines['+index+'][stock_position_id]'" x-model="line.stock_position_id" class="mt-1 w-full rounded-lg border border-line px-3 py-2"><option value="">Choose actual stock lot</option><template x-for="position in positionsFor(line)" :key="position.id"><option :value="position.id" x-text="position.label"></option></template></select></label><p class="text-xs text-slate-500 sm:col-span-2">Available excludes reservations. {{ $directDispatch?'Dispatch records stock, FIFO cost and sale revenue; create the invoice next, then record any payment in Finance.':'Your own order reservation is fulfilled during dispatch. Split lines when picking different lots; leave warehouse above blank to choose sources across multiple warehouses.' }}</p>@endif
@if($kind==='return')<label class="text-sm">Inspection result<select :name="'lines['+index+'][condition_code]'" x-model="line.condition_code" class="mt-1 w-full rounded-lg border border-line px-3 py-2"><option value="quarantine">Quarantine / awaiting inspection</option><option value="damaged">Damaged (quarantine)</option><option value="saleable">Inspected and saleable</option></select></label><label class="text-sm">Receiving location<select required :name="'lines['+index+'][warehouse_location_id]'" x-model="line.warehouse_location_id" class="mt-1 w-full rounded-lg border border-line px-3 py-2"><option value="">Choose matching warehouse location</option>@foreach($locations as $loc)<option value="{{ $loc->id }}">{{ $loc->warehouse->code }} / {{ $loc->code }} / {{ $loc->location_type }}</option>@endforeach</select></label><label class="text-sm">New return lot number<input required :name="'lines['+index+'][lot_number]'" x-model="line.lot_number" class="mt-1 w-full rounded-lg border border-line px-3 py-2"></label>@endif
@if($kind==='invoice')<p class="text-sm text-slate-500 sm:col-span-2">Price and discount come from the confirmed dispatch. Save for later to see the invoice preview before confirmation.</p>@endif
@if($kind==='credit')<label class="text-sm">Returned quantity to credit (0 for price adjustment)<input required min="0" step="1" type="number" :name="'lines['+index+'][credited_base_quantity]'" x-model="line.credited_base_quantity" class="mt-1 w-full rounded-lg border border-line px-3 py-2"></label><label class="text-sm">Credit amount PKR<input required min="0.0001" step="0.0001" type="number" :name="'lines['+index+'][net_amount]'" x-model="line.net_amount" class="mt-1 w-full rounded-lg border border-line px-3 py-2"></label><label class="text-sm sm:col-span-2">Physical return line (leave blank for price adjustment)<select :name="'lines['+index+'][sales_return_line_id]'" x-model="line.sales_return_line_id" class="mt-1 w-full rounded-lg border border-line px-3 py-2"><option value="">No physical return</option>@foreach($returnChoices as $rc)<option value="{{ $rc['id'] }}">{{ $rc['label'] }}</option>@endforeach</select></label>@endif
@endif
</div></section></template>
<button type="button" @click="add()" class="rounded-lg border border-line bg-white px-4 py-2 text-sm">Add line</button>
<section class="rounded-xl border border-line bg-white p-5"><h2 class="font-semibold">3. Check and confirm</h2><p class="mt-2 text-sm text-slate-500">Check customer, source, quantities and amounts. Confirm saves and finalizes this entry together. Confirmed history cannot be edited. To correct a draft, cancel and recreate it.</p><div class="mt-4 flex flex-wrap gap-3">@if(auth()->user()->hasPermissionTo('sales.documents.confirm'))<button name="intent" value="confirm" class="rounded-lg bg-brand px-5 py-3 text-sm text-white">Confirm {{ $kind }}</button>@endif<button name="intent" value="draft" class="rounded-lg border border-line px-4 py-3 text-sm">Save for later / preview</button><a href="{{ route('sales.index',['kind'=>$kind]) }}" class="px-3 py-3 text-sm text-slate-500">Cancel</a></div></section>
</form>
@endif
@endsection
