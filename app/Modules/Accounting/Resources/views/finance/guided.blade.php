@extends('module.core::layouts.app')
@section('title', 'Party payments & adjustments')
@section('content')
@include('module.accounting::finance.nav')
<div class="finance" x-data="financeGuide(@js($guide))">
    <h1 class="text-3xl font-semibold">{{ $kind === 'receipt' ? 'Receive money' : ($kind === 'settlement' ? 'Adjust an advance / existing credit' : 'Pay a supplier or vendor') }}</h1>
    <p class="mt-3 text-slate-500">Choose the party, review what is unpaid, then record money or adjust an advance already recorded.</p>
    <noscript><p>This guided form requires JavaScript. Enable JavaScript or <a href="{{ route('finance.create', ['kind' => $kind, 'advanced' => 1]) }}">use the standard form</a>.</p></noscript>
    <form method="POST" action="{{ route('finance.store') }}">
        @csrf
        <input type="hidden" name="kind" :value="mode === 'settlement' ? 'settlement' : moneyKind">
        <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', (string) Illuminate\Support\Str::uuid()) }}">
        <section class="panel">
            <h2 class="font-semibold">1. Who is this for?</h2>
            <div class="mt-4 grid gap-4 md:grid-cols-2">
                <label>Supplier, processing vendor or customer
                    <select x-model="role" @change="chooseRole()" required>
                        <option value="">Choose party and role</option>
                        <template x-for="r in roles" :key="r.id"><option :value="r.id" x-text="r.name"></option></template>
                    </select>
                </label>
                <label x-show="controls.length > 1">Account for this party
                    <select x-model="account" @change="clearSelection()" :required="controls.length > 1">
                        <option value="">Choose account</option>
                        <template x-for="a in controls" :key="a.id"><option :value="a.id" x-text="a.name"></option></template>
                    </select>
                </label>
            </div>
            <p class="mt-3 text-sm" x-show="role && !controls.length">No matching payable / receivable account is configured. Configure an active posting account in Accounts & cash / bank.</p>
            <div x-show="role && account" class="mt-4 grid gap-4 md:grid-cols-2" aria-live="polite">
                <div class="rounded-lg bg-slate-50 p-4"><p x-text="customer ? 'Invoices still to collect' : 'Bills still to pay'"></p><strong x-text="money(sum(bills))"></strong></div>
                <div class="rounded-lg bg-slate-50 p-4"><p>Available advance / existing credit</p><strong x-text="money(sum(credits))"></strong></div>
            </div>
            <p class="mt-3 text-sm text-slate-500">Remaining amounts for the selected role and account, before this entry. Available credit may include advances, returns or opening credit.</p>
        </section>
        <section class="panel" x-show="role && account">
            <h2 class="font-semibold">2. What do you want to do?</h2>
            <label class="mt-3"><input type="radio" value="money" x-model="mode" @change="switchMode()"> <span x-text="moneyKind === 'payment' ? 'Record money paid now (or an earlier payment not yet recorded)' : 'Record money received now (or an earlier receipt not yet recorded)'"></span></label>
            <label class="mt-3"><input type="radio" value="settlement" x-model="mode" @change="switchMode()"> Adjust an advance / credit already recorded — no new money movement</label>
            <p class="mt-3 text-sm" x-show="credits.length && mode === 'money'">This party has available advance / credit. To use it against a bill, choose the adjustment option above. Record a new payment only for additional money actually paid.</p>
        </section>
        <section class="panel" x-show="role && account">
            <h2 class="font-semibold">3. Enter the details</h2>
            <div class="mt-4 grid gap-4 md:grid-cols-2">
                <label>Transaction date<input type="date" name="document_date" required value="{{ old('document_date', App\Modules\Inventory\Services\InventoryGuard::businessDate()) }}"></label>
                <label>Reference / evidence<input name="reference" maxlength="100" value="{{ old('reference') }}"></label>
                <label class="md:col-span-2">Reason / description<textarea name="description" required minlength="5" maxlength="500">{{ old('description') }}</textarea></label>
            </div>
            <fieldset :disabled="mode !== 'money'" x-show="mode === 'money'" class="mt-4 grid gap-4 md:grid-cols-2">
                <input type="hidden" name="party_role_id" :value="role">
                <input type="hidden" name="settlement_account_id" :value="account">
                <label x-text="moneyKind === 'payment' ? 'Pay from this cash / bank account' : 'Receive into this cash / bank account'"></label><div></div>
                <label>Cash / bank<select name="bank_account_id" x-model="bank" @change="chooseBank()" required><option value="">Choose account</option>@foreach($banks as $b)<option value="{{ $b->id }}">{{ $b->name }} ({{ $b->account_kind }})</option>@endforeach</select></label>
                <label>Payment method<select name="method" x-model="method"><option value="cash">Cash</option><option value="bank_transfer">Bank transfer</option><option value="cheque">Cheque</option><option value="other">Other</option></select></label>
                @if($banks->isEmpty())<p class="md:col-span-2 text-sm">No cash / bank account configured. <a class="text-brand" href="{{ route('finance.accounts') }}">Set up your actual cash box or bank account</a> first.</p>@endif
                <label>Total money <span x-text="moneyKind === 'payment' ? 'paid' : 'received'"></span> (PKR)<input name="amount" type="number" min="0.0001" step="0.0001" x-model="amount" @input="suggest()" required></label>
                <label x-text="normalFlow ? 'Bill / invoice to pay against' : 'Existing item to refund'"></label>
                <label class="md:col-span-2">Apply to<select name="apply_to_open_item_id" x-model="bill" @change="suggest()"><option value="">No bill — keep as advance / on account</option><template x-for="i in targets" :key="i.id"><option :value="i.id" x-text="label(i)"></option></template></select></label>
                <label x-show="bill">Amount used against selected item (PKR)<input name="apply_amount" type="number" min="0.0001" step="0.0001" x-model="applied" :disabled="mode !== 'money' || !bill" :required="mode === 'money' && !!bill"></label>
                <p class="text-sm self-center" aria-live="polite">Left on account after this payment / receipt: <strong x-text="money(unapplied)"></strong>. This is not a second payment.</p>
            </fieldset>
            <fieldset :disabled="mode !== 'settlement'" x-show="mode === 'settlement'" class="mt-4 grid gap-4 md:grid-cols-2">
                <p class="md:col-span-2 text-sm">Use money or credit already recorded. Your bank / cash balance will not change.</p>
                <label>Available advance / credit<select x-model="credit" @change="suggest()" required><option value="">Choose existing advance / credit</option><template x-for="i in credits" :key="i.id"><option :value="i.id" x-text="label(i)"></option></template></select></label>
                <label>Unpaid bill / invoice<select x-model="bill" @change="suggest()" required><option value="">Choose bill / invoice</option><template x-for="i in bills" :key="i.id"><option :value="i.id" x-text="label(i)"></option></template></select></label>
                <input type="hidden" name="allocations[0][debit_open_item_id]" :value="customer ? bill : credit">
                <input type="hidden" name="allocations[0][credit_open_item_id]" :value="customer ? credit : bill">
                <label>Advance / credit to use (PKR)<input name="allocations[0][amount]" type="number" min="0.0001" step="0.0001" x-model="applied" required></label>
                <p class="text-sm self-center" x-show="!credits.length || !bills.length">You need both an available advance / credit and an unpaid bill for this role and account. Record the missing source first.</p>
            </fieldset>
        </section>
        <section class="panel">
            <p class="mb-3 text-sm">Review the party, date and amount. Confirm saves once; posted corrections require a reversal.</p>
            <button :disabled="!role || !account || (mode === 'settlement' && (!bill || !credit))" x-text="mode === 'settlement' ? 'Confirm advance adjustment' : (moneyKind === 'payment' ? 'Confirm payment' : 'Confirm receipt')">Confirm</button>
            <a class="ml-4 text-sm" href="{{ route('finance.index') }}">Cancel</a>
            <a class="ml-4 text-sm text-brand" href="{{ route('finance.create', ['kind' => $kind, 'advanced' => 1]) }}">Advanced form / multiple allocations</a>
        </section>
    </form>
</div>
@endsection
<style>
.finance label{display:block;font-size:.875rem;color:#475569}.finance input:not([type=hidden]):not([type=radio]),.finance select,.finance textarea{width:100%;border:1px solid #cbd5e1;border-radius:.5rem;padding:.6rem;background:white;color:#0f172a}.finance .panel{background:white;border:1px solid #e2e8f0;border-radius:.8rem;padding:1.25rem;margin-top:1rem}.finance button{padding:.6rem 1rem;border-radius:.5rem;background:#126b5b;color:white}.finance button:disabled{opacity:.5;cursor:not-allowed}
.finance input[type=radio]{width:auto!important;display:inline-block!important;margin-right:.5rem}
</style>
