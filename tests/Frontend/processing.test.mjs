import { test } from 'node:test';
import assert from 'node:assert/strict';
import { receiptPreview, lineWarnings, processingDocument } from '../../resources/js/processing.js';
const meters = { id: 1, available: '100', rate: '2', sameUnit: true, normalFraction: '0.05', places: 6 };
test('partial meter receipt preserves custody and previews the frozen rate', () => {
  const p=receiptPreview({source_quantity:'60',output_quantity:'57',normal_loss_quantity:'3',abnormal_loss_quantity:'0'},meters);
  assert.equal(p.difference,0); assert.equal(p.remaining,40); assert.equal(p.charge,114); assert.equal(p.allowance,3);
});
test('non-meter service uses inspected service count rather than fabric meters', () => {
  assert.equal(receiptPreview({source_quantity:'100',output_quantity:'100',accepted_service_quantity:'20'},{...meters,sameUnit:false,rate:'5'}).charge,100);
});
test('rejected services never preview a processing charge', () => {
  assert.equal(receiptPreview({disposition:'rejected',source_quantity:'40',output_quantity:'40'},meters).charge,0);
});
test('unbalanced quantities and excess normal loss produce guidance', () => {
  const warnings=lineWarnings('receipt',{source_quantity:'60',output_quantity:'50',normal_loss_quantity:'5',abnormal_loss_quantity:'0'},meters);
  assert.equal(warnings.length,2);
  assert.equal(lineWarnings('receipt',{source_quantity:'101',output_quantity:'101'},meters).length,1);
});
test('whole-roll and available-quantity restrictions are visible before posting', () => {
  assert.equal(lineWarnings('dispatch',{quantity:'40'},{...meters,roll:true,onHand:'100'}).length,1);
  assert.deepEqual(lineWarnings('dispatch',{quantity:'100'},{...meters,roll:true,onHand:'100'}),[]);
  assert.equal(lineWarnings('bill',{quantity:'41'},{...meters,available:'40'}).length,1);
});
test('line addition keeps selection defaults and removal does not reuse keys', () => {
  const form=processingDocument({kind:'dispatch',choices:[],defaults:{processing_job_step_id:4,contractor_location_id:2},lines:[{quantity:'30'}]});
  form.init(); form.add(); assert.equal(form.lines[1].processing_job_step_id,4);
  const first=form.lines[0].rowId; form.remove(0); form.add(); assert.notEqual(form.lines[1].rowId,first);
});
test('saved line values survive initialization; a rejected selection clears loss and billed count', () => {
  const form=processingDocument({kind:'receipt',choices:[meters],defaults:{},lines:[{processing_dispatch_line_id:'1',source_quantity:'40',output_quantity:'36',normal_loss_quantity:'2',abnormal_loss_quantity:'2',lot_number:'KEEP'}]});
  form.init(); assert.equal(form.lines[0].lot_number,'KEEP'); assert.equal(form.preview(form.lines[0]).charge,72);
  form.lines[0].disposition='rejected'; form.rejection(form.lines[0]); assert.equal(form.lines[0].normal_loss_quantity,'0'); assert.equal(form.lines[0].abnormal_loss_quantity,'0'); assert.equal(form.amount(form.lines[0]),0);
});

test('duplicate source lines show their combined over-allocation', () => {
 const form=processingDocument({kind:'bill',choices:[meters],defaults:{},lines:[{processing_receipt_line_id:'1',quantity:'60'},{processing_receipt_line_id:'1',quantity:'60'}]});
 form.init(); assert.equal(form.warnings(form.lines[0]).length,1);
});
