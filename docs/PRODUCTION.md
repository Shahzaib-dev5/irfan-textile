# Phase 9 Manufacturing and Production

## Physical Suit Stock follow-up (2026-10-28)

The authorized [Physical Suit Stock workflow](PHYSICAL_SUIT_STOCK.md) simplifies ready-suit
entry, adds processed-fabric inventory and dated customer deliveries/debit-credit
history. Existing stock/FIFO/journals remain authoritative; no migration or duplicate
balances. Production and packing remain under Advanced Manufacturing. The guide
records files, routes, ledger effects, deployment and limits; see TESTING.md for
verification results.

## Phase 10 integration (2026-10-26)

Finished Goods now consumes actual released SUIT outputs for packing, follows
batch/job/material cost lineage and reports original acquisition cost/per-suit
values separately from current stock. Existing Production completion still owns
final plan/WIP/custody checks and receives no duplicate finished stock. The private
cost distribution algorithm is extracted unchanged to shared StockCostDistribution.
See [FINISHED_GOODS_COSTING.md](FINISHED_GOODS_COSTING.md). Earlier packing-is-future
notes describe the prior Phase 9 scope.

## Local activation follow-up (2026-10-26)

The user explicitly authorized running the migration. It is now applied to local
irfan_textile as batch 7, following a completed private SQL backup. Scoped
ProductionSetupSeeder enables the existing admin's five Production permissions,
preserves other-role grants and records one grant audit event. Seven actual
Production pages returned HTTP 200; existing operational counts/document hashes
were unchanged. Configuration/routes/views were cleared. The setup and workspace
suite passed 5 tests / 71 assertions. Source additions are ProductionSetupSeeder
and ProductionSetupTest; current suite has 178 cases (no new full 178-case run).
See DEPLOYMENT.md for the repeatable scoped upgrade commands. Earlier no-live-write
statements describe the initial implementation, superseded by this authorized
local activation. MySQL 8.4 and company acceptance remain outstanding.

Implemented 2026-10-24; final review 2026-10-26 (Asia/Karachi). This phase
supersedes older Production-is-future notes. Scope stops after Phase 9.

## Architecture decisions and migration impact

Implement the nine approved IP29-IP37 tables exactly as specified, using the
existing documents, stock, FIFO, value and journal infrastructure. Add one nullable
`processing_jobs.manufacturing_batch_document_id` FK to manufacturing_batches for
relational integration. Existing rows remain NULL; no prior posting/history is
rewritten. Additive migration only; no reset or seed against the application DB.
No new plan/order identity: manufacturing_batches is the production order/plan.

A confirmed batch freezes its BOM/material requirements, variant/design/color and
schedule via the published immutable BOM/variant identity. Reports consume actual
stock and receive finished/intermediate/by-product lots atomically. Material sent
to contractors remains company-owned through existing Processing jobs/custody.
No generic money-only WIP account or editable stock/cost total is introduced.

WIP is explicitly received intermediate stock plus related contractor work; stock
in storage remains available material until actual conversion. Reports with only
intermediate output do not count finished suits. Retained physically rejected
finished output is separately identified by rejected lot quality and cannot be
picked/sold as good stock. Unrecoverable losses are input wastage subsets, never
another stock issue. Report notes explain retained defects. Packing/sales and
rework/disposal automation remain later scope.

Finished output cannot exceed the frozen planned quantity, including retained
rejected suits. Every reported finished unit must be backed cumulatively by BOM
net requirements after measured wastage. Different dimensions are never added
as if meters equal suits. Authorized same-unit substitutions and extra inputs
require explicit reasons; intermediate inputs reference their own traceable lots.
Final reports require planned output accounted, no outstanding contractor custody
and no remaining production intermediate lots. No reports/dispatches may follow
an effective final report. Cost follows actual FIFO; normal loss is absorbed,
abnormal loss expensed and output fractions explicitly total one. Eligible labor/
overhead/conversion costs come only from posted configured source debit lines,
locked and capped across reports; no fabricated payroll/expense record is created.


## Operator guide: prerequisites and screen order

1. **Company settings / Financial years:** an open year and period must cover
   every batch/report date. Existing login and Production permissions are required.
2. **Textile master data:** create fabric/material items in their true base units,
   the finished stocked item in SUIT, its design/color and finished variant. One
   finished SKU represents one design/color/configuration. Item masters hold no stock.
3. **Purchasing / Inventory:** receive real material into internal company storage
   and release quality before consumption. Use the existing audited opening stock
   mechanism for real cutover stock, not a fabricated purchase. Legacy contractor
   cutover remains the separate future proposal; this phase does not import it.
4. **Production > BOM / Material recipes:** choose the finished variant, recipe
   output yield and each component's net quantity per yield. Example: yield 1 SUIT,
   dyed fabric 6 M, planned loss 0.10. Publish the recipe. For changes, publish a
   new version; old batches retain their earlier requirements.
5. **Production > Production orders / batches > New:** choose the published
   recipe, planned complete suits, optional dates/instructions and Confirm. A
   100-suit plan freezes 600 M net and 666.667 M gross when meter precision is 3.
   Planned loss is a planning allowance, not an automatic inventory issue.
6. If external work is needed, open the batch and choose **Coordinate embroidery /
   finishing / other processing**. Select a published configurable route and
   appropriate vendors/rates in the existing Processing screens. Dispatch and
   receive there. Fabric remains company owned; source lots must return to internal
   released storage before consumption. No fixed processing sequence is imposed.
7. **Production > Consumption & output reports > New:** load the batch. Select
   actual input lot/roll, the matching frozen requirement and actual quantity.
   Enter output kind, finished SKU (or intermediate/scrap item), storage, new lot,
   quantity, quality and cost share. A single output uses share 1. Record observed
   loss under its input line, and eligible actual source costs only if available.
   Confirm records consumption and output together. Save for later is optional.
8. Leave **Complete batch** unchecked for partial work. Example: 300 M consumed,
   50 suits received today; another 300 M and 50 suits later. Each is a separate
   posted report, not an edit of an earlier report. A real 10 M loss within 310 M
   consumed is recorded as 310 M input and 10 M wastage, not 320 M issues.
9. Tick **Complete batch** on the last report. If all output was already received
   without that tick, open a new report: the screen offers a completion-only report
   with no more consumption/output/cost. Completion refuses remaining intermediate
   WIP or outstanding linked vendor custody. Rejected physical suits count toward
   accounted output, are shown separately and remain blocked from picking.
10. Use **WIP & batch progress** for actual vs planned materials, remaining WIP by
    lot/location/unit, related contractor custody, finished cost inputs and history.
    Open any batch/report for **Download PDF**, clean print and audit details.

Tracked rolls keep their physical identity. Cutting/consuming part of a roll at
its existing location is distinct from pretending one roll lives at two locations.
Transfers/contractor dispatch retain the existing whole-roll rule. Any tracked
fabric output requires a new roll identifier.

## Transaction, ownership and cost boundaries

All business writes execute within the existing company-row mutex and database
transaction. Confirm wraps preparation and posting atomically; invalid output,
stock, cost, calendar or completion rolls back everything. New documents use
stable idempotency keys and deterministic confirmation keys; replaying the same
input returns the same posted document. Changed input under the same key and
competing final reports are rejected. Draft rows are checked again on posting.

StockLedger remains the only stock/FIFO/value/cost-link writer. Each ordinary
report issues actual source layers once and receives valued output layers once.
ProductionWastageLine records a subset of those inputs, never another issue.
Layer links conserve every rounded input cost and every output allocation. Normal
loss remains in output value; abnormal loss goes to the configured expense.
Pure inventory-to-inventory conversions with no net value change need no artificial
zero journal. Any actual expense/absorption change gets one balanced journal
sidecar on the source report, not another financial document.

Per-output cost is actual consumed FIFO value less abnormal expensed loss,
allocated by explicit fractions, plus evidenced direct cost allocations. Existing
processed-fabric value already includes its carried contractor charges; do not
add them again. Source-journal debit capacity is checked against all posted
absorptions under the same lock, and absorbed source reversal is blocked. A
simple 300 M at PKR 10/M -> 50 suits produces PKR 3,000 output, PKR 60/suit before
other actual costs. Adding evidenced labor PKR 500 makes PKR 3,500, PKR 70/suit.
The progress page reports total finished cost inputs; separate commercial margin
and packing/finished-goods reporting remain later scope.

WIP follows received intermediate layers through transfer/processing cost links,
not an editable batch quantity. Quantities keep their own units. The inquiry has
a 5,000-layer lineage guard. Input lineage also prevents a same-batch intermediate being counted against the
frozen raw-material requirement a second time: consume it as Extra / intermediate
input with an authorized reason. WIP from another batch is rejected even after
transfers/processing. Cross-batch WIP reallocation is not implemented. Ordinary
finished subassemblies may still be separate BOM components.

Batch plans do not reserve stock automatically; use
existing Inventory reservation services where business policy requires it.

## Completion report

### 1. Features implemented

Published immutable recipes and versioning; confirmed batch plans with frozen
material requirements and finished design/color allocation; partial/final actual
consumption/output; same-unit intermediate WIP, recoverable scrap, measured
normal/abnormal losses and retained rejected suits; actual cost absorption;
configurable Processing batch links; WIP/custody/history inquiry; guided Blade
screens, permissions, direct Confirm/optional Save and batch/report PDFs.

### 2. Files created and modified

New production files:

- `database/migrations/2026_10_24_000001_create_production_tables.php`
- `app/Models/BillOfMaterial.php`, `BillOfMaterialLine.php`,
  `ManufacturingBatch.php`, `ManufacturingBatchMaterial.php`, `ProductionReport.php`,
  `ProductionMaterialLine.php`, `ProductionOutputLine.php`,
  `ProductionWastageLine.php`, `ProductionCostAllocation.php`
- `app/Modules/Production/Services/BomService.php`, `ProductionService.php`,
  `ProductionProgress.php`, `ProductionWorkspace.php`
- `app/Modules/Production/Http/Controllers/ProductionController.php`
- `app/Modules/Production/Http/Requests/BomRequest.php`, `ProductionRequest.php`,
  `ProductionActionRequest.php`
- `app/Policies/ProductionPolicy.php`
- `database/seeders/ProductionConfigurationSeeder.php`, `ProductionSetupSeeder.php`
- `database/factories/BillOfMaterialFactory.php`, `BillOfMaterialLineFactory.php`
- `app/Modules/Production/Resources/views/{nav,overview,boms,index,form,show,progress,progress-table}.blade.php`
- `resources/views/components/erp/production-navigation.blade.php`
- `resources/js/production.js`
- `tests/Support/ProductionFixtures.php`
- `tests/Feature/ProductionSetupTest.php`
- `tests/Feature/{ProductionTest,ProductionWorkspaceTest,ProductionCostTest,ProductionProcessingIntegrationTest,ProductionConcurrencyTest}.php`
- `tests/Frontend/production.test.mjs`, `production-browser.mjs`
- `docs/PRODUCTION.md`

Updated integration files:

- `app/Modules/Production/Routes/web.php`, `app/Models/ProcessingJob.php`
- `app/Modules/Processing/Services/{ProcessingService,ProcessingWorkspace}.php`
- `app/Modules/Processing/Http/Requests/ProcessingRequest.php`
- `app/Modules/Processing/Resources/views/form.blade.php`
- `app/Modules/Inventory/Services/{StockLedger,InventoryService}.php`
- `app/Modules/Catalog/Services/{CatalogService,CatalogWorkspace}.php`
- `app/Modules/Catalog/Resources/views/masters/item-fields.blade.php`
- `app/Modules/Core/Services/{AuditLogger,TransactionDocument}.php`
- `app/Modules/Core/Support/DocumentPresentation.php`
- `app/Providers/AppServiceProvider.php`, `config/erp_permissions.php`
- `database/seeders/DatabaseSeeder.php`, `resources/js/app.js`
- `app/Modules/Core/Resources/views/layouts/app.blade.php`
- `resources/views/components/erp/confirm-buttons.blade.php`
- `tests/Support/inventory_worker.php`, `tests/Support/DatabaseRace.php`
- `database/migrations/2026_10_21_000002_create_warehouse_and_inventory_tables.php`
  (Pint formatting only; identical DDL/data behavior, no database decision change)
- `AGENTS.md`, `README.md` and documentation listed below.

No Composer/npm dependency, application .env, credentials or business transaction
was changed. Local schema/configuration/admin grant activation is recorded above.
Compiled Vite assets/manifest reflect the source build; browser/PDF artifacts are
synthetic test fixtures under ignored storage/framework/testing.

### 3. Tables and migrations

One additive migration creates the nine IP29-IP37 tables listed in
[DATABASE_SCHEMA.md](DATABASE_SCHEMA.md), with DECIMAL quantities/value/fractions,
FK/unique/check/index constraints, document-header PK/FKs, unique document line
numbers and unique nullable movement references. It adds the nullable indexed
Processing job batch FK described above. No purchasing/inventory identity is
recreated. Application migration count rises from 78 to 87 tables. It was initially exercised
on the guarded isolated test database and subsequently applied to the local
application database with explicit user authorization, as recorded above. No reset.

### 4. Routes and screens

Fourteen Production routes under `/production`, all authenticated, active-user
and production.view protected:

| Method | Path | Purpose |
|---|---|---|
| GET | /production | Start here / setup guide |
| GET | /production/boms | Recipes and publish form |
| POST | /production/boms | Publish recipe version |
| GET | /production/batches | Search/status/paginated orders |
| GET | /production/batches/create | New batch |
| POST | /production/batches | Save / Confirm batch |
| GET | /production/reports | Search/status/paginated reports |
| GET | /production/reports/create | Load batch, actual conversion / closure |
| POST | /production/reports | Save / Confirm report |
| GET | /production/progress | WIP, material usage and custody |
| GET | /production/documents/{document} | Detail, history and next actions |
| POST | /production/documents/{document}/{action} | Confirm saved / cancel draft |
| GET | /production/documents/{document}/pdf | Private PDF attachment |
| GET | /production/documents/{document}/print | Clean printable document |

Permissions: production.view, production.manage, production.confirm,
production.materials.override and production.costs.allocate. The existing
configuration seeder defines grants for super-admin. The approved local activation
now attaches those five grants via scoped ProductionSetupSeeder and audits them.
Wholesale-only users and unauthorized preparers cannot bypass backend checks.

### 5. Tests and results

Final regression and browser evidence is recorded in [TESTING.md](TESTING.md).
Clean full regression passed **174 tests / 1,262 assertions**, including all
**13 independent concurrency cases**. After final WIP-origin safeguards, the
Production suite passed **24 tests / 161 assertions**, including three new cases
beyond that full-run discovery. These runs overlap; the current suite contains
177 distinct cases at that review. Activation subsequently adds one setup case.
**26 Node tests**, all four module browser harnesses on Edge
156 desktop/mobile, Vite build (67 modules), whole-repository Pint, 134 compiled
Blade/PHP lint checks, route cache/list/clear, 220 relative links and independent
batch/report PDF rendering/visual review passed. The first failed regression and
its Blade/startup corrections are retained in TESTING.md. No target MySQL 8.4 or
live deployment check is claimed.

### 6. Known issues and limits

- Local DB evidence is MariaDB 10.4.32, not target MySQL 8.4; exact target checks
  and operator/accountant acceptance are outstanding before production rollout.
- Local application migration/setup is now complete. Other installations still
  require migration/configuration and target MySQL 8.4 acceptance.
- No packing, wholesale, payments, expense/payroll entry, labor scheduling,
  machine planning or legacy contractor opening import is implemented.
- Intermediate WIP retains a source physical unit. Generic cross-dimension
  intermediate yield, production rework/disassembly and automated posted-report
  reversal are deferred. No edit/delete workaround is exposed.
- A normal report needs positive input and output. Catastrophic zero-output
  write-off is not manufactured as fake suits; existing authorized inventory
  adjustment/history is separate and does not auto-close a short batch.
- Completion requires exactly the accounted plan (including retained rejects);
  short-close/plan amendment after confirmation is not implemented.
- Reject disposal/rework is separate; pending/held/rejected inventory retains
  existing quality restrictions. Only quality released stock is consumable.
- No automatic stock reservation on plan, no money-only WIP estimate, no automatic
  material-requirements purchasing or inferred cross-unit totals.
- Direct cost choices require eligible posted expense sources; this phase does
  not supply expense/payroll input screens or allocate estimates.
- Inquiry choices load active references/current positions; large-volume query
  optimization and WIP graphs beyond 5,000 layers need a later measured review.
- Existing PDF branding falls back to JPEG/text without GD; PNG/WebP need GD.

### 7. Documentation updated

AGENTS, README, PROJECT_OVERVIEW, PRD, ARCHITECTURE, DATABASE_SCHEMA, ERD,
DATABASE_DECISIONS, BUSINESS_RULES, DEVELOPMENT_PHASES, MIGRATION_PLAN,
PROJECT_STATE, CHANGELOG, TESTING, DEPLOYMENT, database/INVENTORY_PRODUCTION,
PROCESSING, SIMPLE_ADMIN_FLOW, DATABASE_REVIEW and this guide. Dated current sections supersede
historical phase reports without deleting their evidence.

### 8. Suggested Git commit message

`feat(production): add batch manufacturing and traceable production reporting`

No commit was created; this workspace has no Git repository.

### 9. Readiness and review checklist

**Ready for code review and Phase 9 approval**, with passing implementation checks
recorded above and in PROJECT_STATE/TESTING. Production rollout remains conditional
on the target MySQL 8.4, migration/configuration and operator/accountant checks
above. The explicitly authorized local migration/setup is complete. Stop after
Phase 9; do not silently
begin packing or sales.

- [x] Approved nine tables and additive job link; no data rewrite/reset.
- [x] Shared stock/FIFO/journal writers; no duplicated inventory totals.
- [x] Published recipe versions and frozen requirements.
- [x] Partial production, measured losses, retained rejects and actual cost inputs.
- [x] WIP origin cannot count the same raw input twice or fund another batch.
- [x] WIP follows transferred/processed layers and blocks premature completion.
- [x] Request replay/changed-payload/competing final posting checks.
- [x] Configurable Processing routes; no hardcoded service order.
- [x] Permissions protect interface, HTTP and service boundaries.
- [x] Separate guided screens, direct Confirm, optional Save and transaction PDFs.
- [ ] Verify exact MySQL 8.4 target and deployment configuration.
- [ ] Operator/accountant accept real BOM, partial/final quantities and document layout.
