import test from 'node:test';
import assert from 'node:assert/strict';
import { salesForm } from '../../resources/js/sales.js';

test('sales form keeps entered fields and independent stable line keys',()=>{
 const f=salesForm([{item_id:'3',quantity:'4',unit_price:'100',discount_amount:'5'}]);f.init();const first=f.lines[0].key;
 f.add();const second=f.lines[1].key;f.remove(first);f.add();assert.equal(f.lines[0].key,second);assert.notEqual(f.lines[1].key,first);assert.notEqual(f.lines[1].key,second);
 f.remove(second);f.remove(f.lines[0].key);assert.equal(f.lines.length,1);
});
test('sales source filtering never suggests another order item and selecting item retains quantities',()=>{
 const f=salesForm([],[{id:7,item_id:3}],[{id:1,item_id:3},{id:2,item_id:4}]);f.init();const row=f.lines[0];row.sales_order_line_id='7';assert.deepEqual(f.positionsFor(row).map(p=>p.id),[1]);row.quantity='4';f.choose(row,{id:3,unit_id:8,label:'SKU-3',units:[{id:8,code:'SUIT'}]});assert.equal(row.quantity,'4');assert.equal(row.unit_id,'8');f.choose(row,undefined);assert.equal(row.item_id,'3');
});
test('latest search wins when requests complete out of order',async()=>{
 const oldFetch=globalThis.fetch;let resolveFirst;globalThis.fetch=async(url)=>url.includes('first')?new Promise(r=>{resolveFirst=r;}):{ok:true,json:async()=>[{id:2}]};
 try {const f=salesForm([],[],[],'/sales/items');f.query='first';const first=f.search();f.query='second';await f.search();resolveFirst({ok:true,json:async()=>[{id:1}]});await first;assert.deepEqual(f.results,[{id:2}]);} finally {globalThis.fetch=oldFetch;}
});
