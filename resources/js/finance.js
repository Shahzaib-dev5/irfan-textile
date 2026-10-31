// Display calculations use exact four-decimal units. Posting is revalidated by FinanceService.
const units = value => {
    if (!/^\d*(\.\d*)?$/.test(String(value || '0'))) return 0n;
    const [whole, fraction = ''] = String(value || '0').split('.');
    return BigInt(whole || '0') * 10000n + BigInt((fraction + '0000').slice(0, 4));
};
const decimal = value => `${value / 10000n}.${String(value % 10000n).padStart(4, '0')}`;
export function financeGuide(data) {
    return {
        ...data, role: '', account: '', mode: data.kind === 'settlement' ? 'settlement' : 'money',
        bank: '', method: 'bank_transfer', amount: '', bill: '', credit: '', applied: '',
        init() {
            const old = data.old || {};
            const allocation = old.allocations?.[0];
            this.role = String(old.party_role_id || '');
            this.account = String(old.settlement_account_id || '');
            if (allocation) {
                const item = this.items.find(i => i.id === String(allocation.debit_open_item_id));
                this.role = item?.role || this.role;
                this.account = item?.account || this.account;
                this.mode = 'settlement';
            }
            this.chooseRole(false);
            this.bank = String(old.bank_account_id || '');
            this.method = old.method || 'bank_transfer';
            this.amount = old.amount || '';
            this.bill = String(old.apply_to_open_item_id || (allocation ? (this.customer ? allocation.debit_open_item_id : allocation.credit_open_item_id) : '') || '');
            this.credit = String((allocation ? (this.customer ? allocation.credit_open_item_id : allocation.debit_open_item_id) : '') || '');
            this.applied = old.apply_amount || allocation?.amount || '';
        },
        get customer() { return this.roles.find(r => r.id === this.role)?.customer || false; },
        get controls() { return this.role ? this.accounts.filter(a => a.customer === this.customer) : []; },
        get scoped() { return this.items.filter(i => i.role === this.role && i.account === this.account); },
        get bills() { return this.scoped.filter(i => i.debit === this.customer); },
        get credits() { return this.scoped.filter(i => i.debit !== this.customer); },
        get moneyKind() { return data.kind === 'settlement' ? (this.customer ? 'receipt' : 'payment') : data.kind; },
        get normalFlow() { return (this.moneyKind === 'receipt') === this.customer; },
        get targets() { return this.normalFlow ? this.bills : this.credits; },
        get selectedBill() { return this.items.find(i => i.id === this.bill); },
        get selectedCredit() { return this.credits.find(i => i.id === this.credit); },
        sum(items) { return decimal(items.reduce((total, i) => total + units(i.amount), 0n)); },
        money(value) { return `${value} PKR`; },
        label(item) { return `${item.number} · ${item.date} · remaining ${item.amount} PKR`; },
        chooseRole(reset = true) {
            if (!this.controls.some(a => a.id === this.account)) this.account = this.controls.length === 1 ? this.controls[0].id : '';
            if (reset) this.clearSelection();
        },
        clearSelection() { this.bill = ''; this.credit = ''; this.applied = ''; },
        switchMode() { this.clearSelection(); },
        chooseBank() {
            this.method = this.banks.find(b => b.id === this.bank)?.kind === 'cash' ? 'cash' : 'bank_transfer';
        },
        suggest() {
            const target = this.selectedBill;
            const available = this.mode === 'settlement' ? this.selectedCredit?.amount : this.amount;
            this.applied = target && available && units(available) > 0n ? decimal(units(target.amount) < units(available) ? units(target.amount) : units(available)) : '';
        },
        get unapplied() {
            if (!this.amount || units(this.amount) < 0n) return '0.0000';
            const remaining = units(this.amount) - units(this.bill ? this.applied : '0');
            return decimal(remaining > 0n ? remaining : 0n);
        },
    };
}
