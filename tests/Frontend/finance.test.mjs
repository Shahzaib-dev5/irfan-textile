import { test } from 'node:test';
import assert from 'node:assert/strict';
import { financeGuide } from '../../resources/js/finance.js';

const fixture = (kind = 'payment', old = {}) => financeGuide({kind, old,
    roles: [{id:'1',customer:false},{id:'2',customer:false},{id:'3',customer:true}],
    accounts: [{id:'21',customer:false},{id:'12',customer:true}],
    banks: [{id:'1',kind:'cash'},{id:'2',kind:'bank'}],
    items: [
        {id:'10',role:'1',account:'21',debit:false,amount:'100000.0000'},
        {id:'11',role:'1',account:'21',debit:true,amount:'30000.0000'},
        {id:'12',role:'2',account:'21',debit:true,amount:'50000.0000'},
        {id:'13',role:'3',account:'12',debit:true,amount:'900.0000'},
        {id:'14',role:'3',account:'12',debit:false,amount:'200.0000'},
    ]});

test('supplier scope excludes other roles and suggests advance adjustment without cash', () => {
    const f = fixture(); f.init(); f.role = '1'; f.chooseRole();
    assert.equal(f.account, '21');
    assert.equal(f.sum(f.credits), '30000.0000');
    assert.deepEqual(f.bills.map(i=>i.id), ['10']);
    f.mode='settlement'; f.bill='10'; f.credit='11'; f.suggest();
    assert.equal(f.applied,'30000.0000');
    f.role='2'; f.chooseRole();
    assert.equal(f.bill,''); assert.equal(f.credit,''); assert.equal(f.applied,'');
});
test('new payment is capped at bill and excess remains on account with exact decimals', () => {
    const f=fixture(); f.init(); f.role='1'; f.chooseRole();
    f.amount='100000.0001'; f.bill='10'; f.suggest();
    assert.equal(f.applied,'100000.0000'); assert.equal(f.unapplied,'0.0001');
    f.bill=''; f.suggest(); assert.equal(f.unapplied,'100000.0001');
    f.bank='1'; f.chooseBank(); assert.equal(f.method,'cash');
    f.bank='2'; f.chooseBank(); assert.equal(f.method,'bank_transfer');
});
test('customer receipts and refunds choose the correct side; validation restores settlement', () => {
    const f=fixture('receipt'); f.init(); f.role='3'; f.chooseRole();
    assert.equal(f.account,'12'); assert.deepEqual(f.targets.map(i=>i.id),['13']);
    const refund=fixture('payment'); refund.init(); refund.role='3'; refund.chooseRole();
    assert.deepEqual(refund.targets.map(i=>i.id),['14']);
    const restored=fixture('settlement',{allocations:[{debit_open_item_id:13,credit_open_item_id:14,amount:'100'}]});
    restored.init(); assert.equal(restored.role,'3'); assert.equal(restored.bill,'13'); assert.equal(restored.credit,'14'); assert.equal(restored.applied,'100');
});
