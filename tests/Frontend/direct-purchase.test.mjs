import { test } from 'node:test';
import assert from 'node:assert/strict';
import { directPurchase } from '../../resources/js/direct-purchase.js';
const units=[{id:1,code:'M',decimal_places:3},{id:2,code:'PCS',decimal_places:0},{id:3,code:'THAAN',decimal_places:0},{id:4,code:'SET',decimal_places:0},{id:5,code:'KG',decimal_places:3}];
const blank={mode:'new',item_id:'',unit_id:'',quantity:'',rate_quantity:'',rate_unit_id:'',unit_cost:'',new_item:{item_type:'processed_fabric',sku:'',name:''}};
test('fabric measurements include M PCS SET THAAN while material can use KG',()=>{const f=directPurchase({units,items:[],blank,lines:[]});assert.deepEqual(units.filter(u=>f.compatible(blank,u)).map(u=>u.code),['M','PCS','THAAN','SET']);assert.ok(f.compatible({...blank,new_item:{item_type:'trim'}},units[4]));});
test('rate basis determines total without double counting secondary measurements',()=>{const f=directPurchase({units,items:[],blank,lines:[{unit_id:'4',quantity:'120',rate_unit_id:'3',rate_quantity:'10',unit_cost:'12000',thaan_count:10,set_count:120,measured_meters:600}]});assert.equal(f.total(),120000);f.lines[0].rate_unit_id='4';f.lines[0].unit_cost='1000';assert.equal(f.total(),120000);});
test('new lines keep measurement and item fields independent',()=>{const f=directPurchase({units,items:[],blank,lines:[]});f.add();f.add();f.lines[0].new_item.name='Lawn';f.lines[0].unit_id='2';f.changeUnit(f.lines[0]);assert.equal(f.lines[0].rate_unit_id,'2');assert.equal(f.lines[1].new_item.name,'');assert.equal(blank.unit_id,'');});
