@extends('module.core::layouts.app')
@section('title', 'Company expense')
@section('content')
<div class="finance mx-auto max-w-3xl">
    <h1 class="text-2xl font-semibold">Company expense</h1>
    <p class="mt-2 text-sm text-slate-600">Record tea, meals, internet or other company running costs paid from cash or bank.</p>
    @if($errors->any())
        <div role="alert" class="mt-4 rounded-lg border border-red-300 bg-red-50 p-4 text-sm">
            @foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach
        </div>
    @endif
    <form method="POST" action="{{ route('finance.expenses.store') }}" x-data="{newAccount:@js((bool) old('new_expense_code'))}" class="mt-5 space-y-5">
        @csrf
        <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', (string) Illuminate\Support\Str::uuid()) }}">
        <section class="rounded-xl border border-line bg-white p-5 grid gap-4 md:grid-cols-2">
            <label>Expense date<input type="date" name="document_date" required value="{{ old('document_date', App\Modules\Inventory\Services\InventoryGuard::businessDate()) }}"></label>
            <label>Paid from<select name="bank_account_id" required>
                <option value="">Choose cashbox or bank</option>
                @foreach($banks as $bank)
                    <option value="{{ $bank->id }}" @selected(old('bank_account_id', $defaultCashbox)==$bank->id)>{{ $bank->name }} ({{ $bank->account_kind === 'cash' ? 'Cash' : 'Bank' }})</option>
                @endforeach
            </select></label>
            @if($banks->isEmpty())<p class="md:col-span-2 text-sm">Set up a company cashbox or bank account in Accounts & cash/bank first.</p>@endif
            <label class="md:col-span-2">Expense account<select name="expense_account_id" :disabled="newAccount" :required="!newAccount">
                <option value="">Choose expense GL code and name</option>
                @foreach($expenses as $account)<option value="{{ $account->id }}" @selected(old('expense_account_id')==$account->id)>{{ $account->code }} — {{ $account->name }}</option>@endforeach
            </select></label>
            @if(auth()->user()->hasPermissionTo('accounting.finance.configure'))
                <label class="md:col-span-2 flex items-center gap-2"><input type="checkbox" x-model="newAccount"> Add a new expense account</label>
                <fieldset x-cloak x-show="newAccount" :disabled="!newAccount" class="md:col-span-2 grid gap-4 md:grid-cols-2">
                    <label>New GL code<input name="new_expense_code" :required="newAccount" maxlength="30" value="{{ old('new_expense_code') }}" placeholder="e.g. 5300"></label>
                    <label>Expense name<input name="new_expense_name" :required="newAccount" maxlength="150" value="{{ old('new_expense_name') }}" placeholder="e.g. Staff refreshments"></label>
                </fieldset>
            @endif
            <label>Amount paid (PKR)<input name="amount" type="number" required min="0.0001" step="0.0001" value="{{ old('amount') }}"></label>
            <label>Receipt / evidence reference<input name="reference" required maxlength="100" value="{{ old('reference') }}"></label>
            <label class="md:col-span-2">What was paid for?<textarea name="description" required minlength="5" maxlength="500">{{ old('description') }}</textarea></label>
        </section>
        <p class="text-sm text-slate-600">The selected expense account is debited. The selected cashbox or bank account is credited.</p>
        <button class="rounded-lg bg-brand px-5 py-3 text-sm text-white">Confirm expense</button>
    </form>
</div>
@endsection
<style>
.finance label{display:block;font-size:.875rem;color:#475569}.finance input:not([type=hidden]):not([type=checkbox]),.finance select,.finance textarea{display:block;width:100%;margin-top:.3rem;border:1px solid #cbd5e1;border-radius:.5rem;padding:.6rem;background:white;color:#0f172a}
</style>
