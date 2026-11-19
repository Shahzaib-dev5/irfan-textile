# Simple Fabric Out / Fabric In and direct vendor handover

## Fabric conversion and actual receipt rates (2026-11-18)

Fabric Out supports atomic warehouse M-to-PCS cutting and optional pending rates.
Fabric In supports actual PCS returns against dispatched M, retained-meter/loss
reconciliation, and per-receipt actual rate/unit snapshots used by vendor bills.
Original stock and rates are retained. This supersedes earlier same-unit-only and
mandatory dispatch-rate guidance below. Schema, workflows, files, limits and review
status: [Fabric conversion](FABRIC_CONVERSION.md).

User-authorized Processing follow-up, 2026-10-27. This extends the implemented
Processing workflow after Phase 12; it does not start another business phase.

## Operator guide

1. Assign the vendor's required processing services. Published rates are optional
   legacy contracts; new Fabric Out entries can leave rates pending.
2. Open Fabric Out. Choose vendor/service, available fabric and quantity. Optionally
   cut warehouse meter stock into actual PCS before sending; consumed meters,
   retained meters, losses and evidence must reconcile. Confirm creates the output
   identity, route/job and dispatch atomically, including cutting when selected.
   Vendor custody is provisioned automatically within existing warehouse storage.
3. Open Fabric In against an outstanding dispatch. Record source quantity closed,
   actual returned stock unit/quantity, losses, laat and inspection evidence. For
   M-to-PCS returns, separately record retained meters. Unreceived source quantity
   remains with the vendor. Rejected returns retain the original item and unit.
4. Enter the actual rate and billing unit. Each receipt freezes its own pricing;
   a pending dispatch rate must be finalized here. Billable quantity is automatic
   when it uses the returned stock unit, otherwise enter inspected billing evidence.
5. Choose a warehouse or direct handover to another vendor. Handover requires
   accepted and released output; the new dispatch can also have a pending rate.
   Both documents post in one transaction, with no internal warehouse movement.
6. Vendor bills match the receipt's frozen accrual; Finance payments settle its
   payable. Bills never add stock or accrue the same service a second time.

## Units and identities

M-to-whole-PCS conversion is now supported explicitly; see
[FABRIC_CONVERSION.md](FABRIC_CONVERSION.md) for field examples and database impact.
Other unchanged stock units retain their original meaning. No universal roll length,
meter-to-piece or component-to-suit factor is invented. Tracked meter rolls must be
issued/cut whole. Component/laat counts describe inspection; assembly of complete
suits remains in Production.

Derived output identities distinguish source, service, fabric type, color, component
and design. Converted PCS identities have no roll tracking or copied meter
conversions. Original identities, source costs and custody lineage remain preserved.
Stage progress uses source-equivalent quantities to avoid summing meters and pieces.

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
clear compiled views and verify prior-row fingerprints. Existing installations
do not need a seeder for the conversion follow-up. See TESTING.md and PROJECT_STATE.md for actual verification/activation
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
