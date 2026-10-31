# Simple Fabric Out / Fabric In and direct vendor handover

User-authorized Processing follow-up, 2026-10-27. This extends the implemented
Processing workflow after Phase 12; it does not start another business phase.

## Operator guide

1. Keep shared Vendors & rates. Assign the required service and publish a dated
   PKR contract. For new embroidery work use **Embroidery (per suit)** and SUIT.
   Existing PCS embroidery contracts and their history are not converted.
2. Open **Fabric Out**. Choose vendor, one of their services, agreed rate, vendor
   location, available fabric/lot/roll and actual stock-unit quantity. Enter fabric
   type, color/shade, component (dupatta, shirt, suit, etc.) and design as relevant.
   Confirm creates the expected processed-fabric identity and supporting one-stage
   route/job automatically, then posts the dispatch in the same transaction.
   Vendor location may be left blank. The system reuses the vendor's active custody
   location or creates a warehouse named `<Vendor> — Vendor custody` with one
   `Vendor-held fabric` contractor location automatically.
3. Open **Fabric In**, select the outstanding vendor/dispatch line and record actual
   quantity closed, returned physical quantity, losses, laat reference, component
   count and inspected billing quantity. Accepted output uses the frozen expected
   item automatically. Unchanged rejected returns retain the original input item
   and cannot incur processing charges or loss.
4. Choose **Our warehouse** and an actual receiving location, or **Directly to
   another vendor** and their service, rate and contractor location. Direct handover
   requires accepted, inspected and released output and a different vendor. Confirm
   after that vendor receives the fabric. A smaller receipt transfers only that
   portion; the rest stays with the first vendor.
5. The direct handover records the processed output at the original vendor's
   contractor location and immediately dispatches it to the next vendor inside one
   atomic transaction. No internal warehouse movement occurs. Output at the first
   vendor is zero after confirmation; the next dispatch owns the new custody layer.
6. Receive from the calender/press vendor using the same Fabric In screen. This may
   go to the warehouse or another vendor. Sequence is selected for each transaction.
7. Use the existing matched Vendor bills and Accounting & finance payments. Every
   service accrues once on receipt; its bill clears that accrual to the party payable.
   Existing PDFs include the new fabric/laat descriptions and onward document link.

## Units and identities

Physical stock retains the source fabric's base unit (normally meters). A roll is
selected by its recorded identity and measured stock quantity; no universal roll
length is invented. Tracked rolls must still be dispatched whole.

Laat is a batch reference, not a unit conversion. A receipt can record 34 or 66
dupattas without calling them complete suits. Component count is inspection evidence
on the receipt, not a second editable stock balance. For example 66 returned
components and an explicitly agreed 22 billable suits at PKR 500 accrue PKR 11,000.
No component-to-suit ratio is inferred. Actual physical quantities must reconcile
separately. Assembly and cross-dimension manufacturing remain in Production.

Derived output SKUs distinguish source identity, service, fabric type, color,
component and design. They retain base unit, roll tracking, fabric specification
and configured alternate-unit conversions. Source items are never reclassified.
Later processing preserves source genealogy and descriptions. Receipt descriptions
cannot silently substitute a different agreed identity. A service result is
processed fabric; it does not become a finished suit automatically.

## Implementation and migration impact

One additive migration, `2026_10_27_000001_create_processing_fabric_flows.php`, adds
only `processing_fabric_flows`: unique request key and input hash, immutable posted
details, draft/posted state, creator and restricted document/onward-document FKs.
CHECKs enforce valid kinds, status/document consistency and onward links. No old
table, document hash, balance, journal or stock movement is rewritten. Down migration
refuses destructive history removal.

`ProcessingFabricSetupSeeder` adds only the per-suit embroidery service when absent.
No vendor, contract rate, location, quantity, user or permission is fabricated.

Existing company mutex, stock/FIFO services, period checks, permissions and atomic
Confirm remain authoritative. The whole Fabric Out / receipt-plus-handover is one
transaction. Failure of an onward rate, location, period, quality or stock check
rolls back the complete operation, including automatically prepared masters/jobs.
Retry with identical input returns the existing flow; changed-key reuse is rejected.
The narrow handover context permits only the freshly received output positions;
ordinary inventory/dispatch endpoints cannot pick arbitrary contractor stock.

## Files and screens

- New: FabricFlowService, FabricFlowController, FabricFlowRequest,
  ProcessingFabricFlow model, migration and scoped setup seeder.
- New Blade: fabric-form, fabric-vendor and fabric-details.
- Updated: ProcessingService handover orchestration, ProcessingController,
  ProcessingWorkspace, routes, overview/index/show/custody, sidebar navigation,
  TransactionDocument PDF projection and DatabaseSeeder.
- New tests: ProcessingFabricFlowTest and fabric-browser.mjs. Updated existing
  workspace/browser expectations and concurrency worker/test.
- Routes: GET `/processing/fabric-out`, GET `/processing/fabric-in`, GET
  `/processing/fabric-drafts/{flow}`, POST `/processing/fabric-flows`.
- Historical/Production route/job screens remain under the advanced overview links;
  daily navigation uses Fabric Out, Fabric In and their history screens.

## Limits and deployment

One selected stock position per Fabric Out and one dispatch cost-layer line per
Fabric In. Different lots/components can be entered separately with the same laat
reference. Physical meters and billable/component counts are entered independently;
this is not a component-quantity stock ledger or a meter-to-suit manufacturing
conversion. No roll splitting, transport-in-transit state, or automatic complete-suit
assembly is introduced. Saved entries can be confirmed; changed details require a
new entry. Existing advanced draft/document correction rules remain.

Confirm uses the existing route-management, job-approval and stock-posting grants;
the fully authorized admin already has these. Wholesale remains denied. An open
financial year/period is still required; this follow-up does not change the calendar.

Back up the configured application database privately, apply only the new migration,
run `ProcessingFabricSetupSeeder`, clear compiled views and verify prior-row
fingerprints. See TESTING.md and PROJECT_STATE.md for actual verification/activation
results. Exact MySQL 8.4 validation remains separate from the local MariaDB environment.

Suggested commit: `feat(processing): simplify fabric flows and add direct vendor handovers`.
Ready for operator review after the recorded checks. Stop after this follow-up.

## Actual completion results

Activated locally as migration batch 11 after a verified 293,632-byte SQL backup.
116 existing table fingerprints matched; new flow-history table empty; one per-suit
service configuration added. Seven real GET screens returned 200. Final focused
checks: 51 tests / 340 assertions plus one independent competing-handover race /
10 assertions (52 distinct cases / 350 assertions), existing/new desktop/mobile
browser checks, Vite 69-module build, Pint, Blade/routes and relative links passed.
See PROJECT_STATE.md and TESTING.md for evidence and limits. Ready for operator
review; no next phase started.
