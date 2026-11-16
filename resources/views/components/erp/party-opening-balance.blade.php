@props(['roles'])
@php($direction = old('opening.direction', 'none'))
<section class="rounded-xl border border-line bg-white p-5" data-party-opening>
    <h2 class="font-semibold">Old unpaid balance <span class="text-sm font-normal text-slate-500">(optional)</span></h2>
    <p class="mt-1 text-sm text-slate-500">Use once for an unpaid bill or verified balance from before ERP start. Enter only the amount still unpaid. Future bills are entered through Sales, Purchasing or Processing.</p>
    <input type="hidden" name="opening[idempotency_key]" value="{{ old('opening.idempotency_key', (string) Illuminate\Support\Str::uuid()) }}">
    <label class="mt-4 block text-sm">Is any old amount unpaid?
        <select name="opening[direction]" class="mt-1 w-full rounded-lg border border-line px-3 py-2" data-opening-direction>
            <option value="none" @selected($direction === 'none')>No old unpaid balance</option>
            <option value="receive" @selected($direction === 'receive')>We will receive money from this party</option>
            <option value="pay" @selected($direction === 'pay')>We will pay money to this party</option>
        </select>
    </label>
    <fieldset class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3" data-opening-fields>
        <label class="text-sm">Business role
            <select name="opening[role]" class="mt-1 w-full rounded-lg border border-line px-3 py-2" data-opening-role>
                <option value="">Choose role</option>
                @foreach($roles as $role)<option value="{{ $role }}" @selected(old('opening.role') === $role)>{{ ucfirst($role) }}</option>@endforeach
            </select>
        </label>
        <label class="text-sm">Unpaid amount (PKR)<input type="number" name="opening[amount]" min="0.0001" step="0.0001" value="{{ old('opening.amount') }}" class="mt-1 w-full rounded-lg border border-line px-3 py-2"></label>
        <label class="text-sm">Old invoice / balance schedule reference<input name="opening[reference]" maxlength="100" value="{{ old('opening.reference') }}" class="mt-1 w-full rounded-lg border border-line px-3 py-2"></label>
        <label class="text-sm">Opening date<input type="date" name="opening[document_date]" value="{{ old('opening.document_date', App\Modules\Inventory\Services\InventoryGuard::businessDate()) }}" class="mt-1 w-full rounded-lg border border-line px-3 py-2"><span class="mt-1 block text-xs text-slate-500">Your ERP cut-off date in an open accounting period.</span></label>
        <label class="text-sm">Original due date<input type="date" name="opening[due_on]" value="{{ old('opening.due_on') }}" class="mt-1 w-full rounded-lg border border-line px-3 py-2"></label>
        <p class="self-center text-sm text-slate-500">Saving the party also confirms this opening balance. Later, receive or pay it through Finance. For invoice-by-invoice tracking of more old bills, use Finance → Old unpaid balances.</p>
    </fieldset>
</section>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const section = document.querySelector('[data-party-opening]');
    const form = section.closest('form');
    const direction = section.querySelector('[data-opening-direction]');
    const fields = section.querySelector('[data-opening-fields]');
    const role = section.querySelector('[data-opening-role]');
    const sync = () => {
        const enabled = direction.value !== 'none';
        fields.hidden = !enabled;
        fields.disabled = !enabled;
        fields.querySelectorAll('input,select').forEach(field => field.required = enabled);
        const selectedRoles = [...form.querySelectorAll('input[name="roles[]"]:checked')].map(input => input.value);
        const eligible = [...role.options].filter(option => {
            if (!option.value) return false;
            const matches = selectedRoles.includes(option.value) && (direction.value === 'receive' ? option.value === 'customer' : ['supplier', 'vendor'].includes(option.value));
            option.disabled = !matches;
            return matches;
        });
        if (role.selectedOptions[0]?.disabled) role.value = '';
        if (enabled && eligible.length === 1) role.value = eligible[0].value;
    };
    direction.addEventListener('change', sync);
    form.querySelectorAll('input[name="roles[]"]').forEach(input => input.addEventListener('change', sync));
    sync();
});
</script>
