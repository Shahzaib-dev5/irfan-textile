# Fabric cutting, converted receipts and actual receipt pricing

Implemented follow-up, 2026-11-18. This supersedes the previous same-unit-only
Fabric Out/In and mandatory dispatch-rate guidance. It adds no business phase.

## Operator flow

- Fabric Out may leave Agreed PKR rate blank. The job records **Rate pending**;
  blank never means a free service. An explicit zero means an agreed free service.
- To send existing meter stock as cut pieces, choose **Cut meter stock into pieces
  before sending**. Quantity above is consumed M. Enter actual whole PCS, meters
  retained in those pieces, normal/abnormal cutting loss, and measurement evidence.
  Example: 100 M consumed, 20 PCS produced, 95 M retained, 5 M normal loss.
  Confirm atomically posts a CUT conversion followed by the PCS dispatch. A failure
  in the dispatch rolls back the cutting, derived items, stock and accounting.
- For fabric sent in M and returned as PCS, select PCS in Fabric In. Enter source
  M closed, actual PCS returned, actual retained M and measured losses. Source M
  closed = retained M + normal M loss + abnormal M loss. A partial receipt leaves
  outstanding custody in M. No fixed meter-per-piece ratio is invented.
- Record the actual service rate and billing unit on Fabric In: M, PCS, SUIT, SET
  or THAAN. If billing in the returned stock unit, billable quantity is the output
  quantity; otherwise enter the inspected billable quantity and evidence. Meter
  billing for an M-to-PCS conversion uses actual retained meters. Each receipt
  freezes its rate, unit, quantity and accrual, even when later receipts have a
  different rate. Bills match that receipt snapshot, not the job's initial rate.
- Rejected returns retain original units/items and have no service charge or loss.
  Accepted and released converted PCS can move directly to a different vendor;
  the next dispatch rate may also remain pending.
- Converted stock remains processed fabric. Complete-suit assembly stays in
  Production. Component count/laat labels are descriptive evidence, not another
  editable stock balance.

## Stock and accounting

Original item units and posted history are preserved. `fabric_measurement_items`
maps an original meter identity to a separate PCS identity; converted identities
have no roll tracking or copied meter-unit conversions. Cutting and receipts carry
source costs through FIFO allocation links. Normal loss is absorbed into output;
abnormal loss is expensed. Service cost accrues once on receipt and increases
output value. Matched bills transfer accrual to payable without replaying stock.
Stage progress aggregates accepted source-equivalent quantities so M and PCS are
never added together. Receipt screens, reports and PDFs identify actual units.

## Additive schema

Migration `2026_11_18_000001_add_fabric_conversion_and_receipt_rates` adds:

- `processing_job_steps.rate_pending` (default false).
- Nullable `processing_receipt_lines.billing_unit_id` and `unit_rate_pkr`. Existing
  receipts fall back to their frozen job rates; existing posting hashes remain
  valid. New rows store both snapshot fields, with paired-null/nonnegative checks.
- `fabric_measurement_items`: source/output item and unit foreign keys; unique
  source/unit and unique output identity.
- `fabric_cuttings`: document/source/output foreign keys, consumed M, actual PCS,
  retained M, normal/abnormal loss, input/abnormal costs and evidence. Checks enforce
  positive whole pieces, conservation, nonnegative costs and distinct positions.
- `fabric_cutting` document type, prefix CUT, with immutable posted stock history.

No reset, deletion or update of original quantities/rates. The migration is
forward-only; recovery requires an approved backup. Applied locally after a 359740-byte private backup; three historical processing
payload hashes and all operational row counts were verified unchanged. Checks
are recorded in TESTING.md and DEPLOYMENT.md.

## Files and routes

Core files: FabricCutting, FabricMeasurement, FabricFlowService, ProcessingService,
ProcessingWorkspace, FabricFlowRequest, FabricFlowController, ProcessingJobStep,
ProcessingReceiptLine, InventoryService/Controller, TransactionDocument, the new
migration, fabric-form/vendor/cutting/show/reports/custody views, and feature/browser
tests. Existing `/processing/fabric-out`, `/processing/fabric-in` and POST
`/processing/fabric-flows` are reused. New read-only
`/processing/cuttings/{document}` shows measurements and costs; the dispatch and
inventory history link to it. No new permission: existing authorized processing
post permissions and company transaction locking apply.

## Limits and review

The new physical conversion is M to whole PCS only. There is no generic reverse
PCS-to-M conversion or automatic PCS-to-SUIT assembly. Pre-dispatch cutting takes
warehouse stock and cannot be linked to manufacturing WIP; use existing Production
for that. Tracked meter rolls must be cut/issued whole. Meter-only dyeing cannot
receive pre-cut PCS; choose the applicable service (including PCS embroidery).
Advanced published routes retain their same-unit planning rules. Posted CUT records
have no standalone edit/reverse action; do not use inventory adjustments to rewrite
them. Older saved receipt entries whose fields no longer match the new form should
be replaced with a new entry; posted history is retained. Exact MySQL 8.4,
concurrent-worker and full-suite checks have not been performed.

Suggested commit: `feat(processing): convert fabric to pieces and price actual receipts`.
Ready for operator review after the recorded checks; Phase 12 boundary retained.
