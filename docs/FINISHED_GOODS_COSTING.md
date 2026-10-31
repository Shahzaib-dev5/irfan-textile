# Phase 10 - Finished Goods, Packing and Actual Costing

## Physical Suit Stock follow-up (2026-10-28)

The authorized [Physical Suit Stock workflow](PHYSICAL_SUIT_STOCK.md) simplifies ready-suit
entry, adds processed-fabric inventory and dated customer deliveries/debit-credit
history. Existing stock/FIFO/journals remain authoritative; no migration or duplicate
balances. Production and packing remain under Advanced Manufacturing. The guide
records files, routes, ledger effects, deployment and limits; see TESTING.md for
verification results.

## Phase 11 downstream Sales integration (2026-10-26)
Wholesale now dispatches released SUIT/PACK lots via StockLedger at actual FIFO
cost. PACK dispatch does not consume suit contents again. Physical Sales returns
receive new traceable lots with links to original issue costs; rejected/uninspected
returns remain quarantined. Sales does not receive production twice or change
historical manufacturing cost. See [SALES.md](SALES.md). Phase 10-only scope text
below describes the completed historical phase.

Implemented and locally activated, 2026-10-26. Ready for code review and operator approval; stop after Phase 10.

## Implementation contract and migration impact

Implement the seven approved IP38-IP44 tables, with no duplicate variant, stock,
batch-completion or accounting ledger. This raises application tables 87 -> 94.
All table additions are additive; no existing history or payload hash is rewritten.
Existing finished item classification also supports a new configured whole-count
PACK base unit for a distinct sellable packed SKU. SUIT variants remain SUIT only.
PACK and SUIT have no generic unit conversion; explicit immutable specifications
reconcile their contents. No existing item-type CHECK or unit/quantity is changed.

Ordinary packing consumes exact published component quantities and actual FIFO
layers and receives new PACK lots at internal company storage atomically. Every
output pack is fully serialized to package records with conserved source content
quantities; packages are trace metadata, not extra stock. Full-recovery unpacking
is a new conversion limited to proven packs from the selected specification.
Partial-loss unpack/rework is rejected; actual damage/disposal uses existing
Inventory adjustment and quality services with separate authorization/history.
No silent missing-content or manufacturing cost estimate is accepted.

Existing Production final reports already receive suits and close the batch.
Finished Goods completion links reuse those actions and create no second receipt.
Warehouse relocation uses existing Inventory transfers; packing itself receives
its output once at the chosen internal location. Released partial production can
be packed without pretending the entire batch is complete.

Actual costing recursively follows immutable cost links and posted value entries,
identifies source material/opening values, job/service accruals and actual
production allocations, and conserves PKR rounding. Historical acquisition cost
uses a value-entry cutoff, including source costs only as known when issued.
Current on-hand quantity/value is shown separately. New master rates/BOMs do not
restate old output costs. Missing source evidence stays explicitly identified;
no fabricated transportation/overhead/processing costs. Units are never added
across dimensions. Allocation bases and unsupported policies are documented here.

Local activation will use a private pre-migration backup and scoped admin setup,
consistent with the user's already-authorized migration/activation workflow.
No fake stock, transactions, credentials or company profile changes.

## Operator workflow

1. **Textile master data:** keep individual designs/colors as existing finished
   SUIT variants. Create a separate Finished product item measured in PACK for
   each saleable assortment. Create actual bags/tags/cartons/paper as Packaging
   material items, measured in PCS, M, KG or their real physical unit.
2. **Production:** receive real suits with normal partial/final reports. Inspect
   and release lots before packing. The Finished Goods batch page links back to
   existing Production completion; it creates no duplicate stock receipt. Closing
   a batch still requires the plan accounted, no intermediate WIP and no vendor
   custody. Packing released partial output does not pretend the batch is complete.
3. **Finished goods > Packing specifications:** choose the PACK SKU and list
   content SUIT variants and supply quantities per one PACK. Example: 1 suit +
   1 bag, or an assortment containing multiple specific design/color SUIT SKUs.
   Publish. Different saleable contents need another PACK SKU; version changes
   can revise supplies while preserving those contents. Published versions and
   referenced SKU/variant measurement identities are protected.
4. **Packing / recovery records > New:** load the version and operation. Enter
   actual source lots for each component and quantities consumed. Add output lot,
   company receiving location, PACK quantity and inspection quality. Each output
   lot has one or more unique container codes; their PACK quantities must cover
   the output exactly. One carton may represent several identical PACK units.
5. **Confirm packing conversion** saves/posts atomically. Optional Save for later
   creates no movements. Correct a draft by cancelling/recreating it. Repeated
   identical confirmation creates no extra quantities, values or containers.
6. **Full recovery:** select the original specification and proven source PACK
   lots. Recover every component, including supplies, exactly. Enter frozen cost
   shares totaling 1 and explain the allocation basis in remarks. Held/rejected
   source recovery stays pending/held/rejected until a separate inspection.
   Partial-loss recovery, nested packs and repacking after sales are not offered.
7. **Batches & actual costing:** view actual original output costs, per-suit cost,
   source breakdown, retained rejects and printable/PDF cost statements. Multiple
   different source units are never added as equivalent physical quantities.
8. **Finished stock & quality:** filter SKU/lot, warehouse and inspection state;
   inspect, hold or reject retained goods. Unreserved quantity still needs quality
   release before picking. Actual disposal uses a separately numbered Inventory
   damage document at actual FIFO cost, with existing Inventory manage/post
   permissions in addition to Finished Goods quality permission.
9. **Trace acquisition layer:** follow batches, Processing jobs, source lots,
   documents and cost evidence. An audited opening remains an opening source;
   the system does not invent missing manufacturing history. Warehouse relocation
   uses existing Inventory transfers. New packing output is received once at its
   selected internal/quarantine location.

Example: 100 suits produced from actual fabric costing PKR 6,000 cost PKR 60/suit.
Packing 10 suits consumes PKR 600 plus 10 real bags at PKR 2: output is 10 PACK
valued at PKR 620, PKR 62 per equivalent suit. Unpacked suit stock falls to 90;
the 10 container units are trace metadata, not another 10 suits. Consuming 5 M
of real wrapping paper at PKR 3/M adds PKR 15, not five suit units. Pending or
rejected outputs retain their own valued quantities and stay blocked from picking.

## Cost components and actual evidence

| Component | Accepted actual evidence |
|---|---|
| Grey/raw fabric, dupatta, lace and other materials | Consumed item/lot FIFO value from posted purchase/production or audited opening receipts |
| Dan, Dyeing, Combined Dan/Dyeing, Embroidery, Pressing, Calendaring, Finishing or another configured service | Accepted Processing receipt accrual and its frozen job/service identity; not today's vendor rate |
| Cutting/labor, transportation, other conversion and overhead | Eligible posted source journal debits allocated once by ProductionCostAllocation; existing purpose/account/cap restrictions apply |
| Packing supplies | Actual PackingMaterialLine FIFO issues from stocked supplies in their own base units |
| Abnormal manufacturing loss | Existing Production expense destination; it is not capitalized again into good output |
| Actual finished-stock disposal | Existing Inventory damage document and its configured variance expense |

No new expense/payroll entry screens, contractor rates, arbitrary editable cost
fields or cost estimates are introduced. Source account descriptions/document
references identify actual allocations. Missing sources do not get guessed.

## Allocation, historical integrity and concurrency

Packing requires exact consumption = specification quantity per PACK * received
PACK units for each component independently. Each container's content records are
allocated deterministically across actual source lines, in line order and the
component's base unit; total input attribution and every container are checked
again on posting. No container stock balance or hidden PACK/SUIT conversion exists.

Packing output lot cost shares follow actual PACK quantities. Fractions use ten
decimal places; an unrepresentably small positive share is rejected. Money rounds
to four places with the final output receiving residual. Shared StockCostDistribution
preserves each source's issued value and each destination's received value exactly;
this is the unchanged Production distribution algorithm extracted into Inventory.
Full-recovery unpacking uses explicit recorded fractions summing to one. Its actual
input pool is unchanged; the authorized operator documents the recovered-output
allocation basis in remarks. Losses cannot be hidden as missing components.

ActualCosting replays immutable value entries and incoming cost links. Each source
is evaluated before its issue value-entry ID, which prevents later postings from
restating historical material/service/overhead composition. Original and reversed
signed ledger facts both participate; a later reversal does not erase the original
issue. Original acquisition reports stop at that receipt document's last value
entry. Current remaining inventory is a separate all-signed-entry carrying value.
Master labels/inspection state are current; monetary source facts and IDs remain
historical. No future late-cost propagation/editor is claimed by this read model.

Costing recursively allocates PKR amounts only, never sums meters, suits and kilos
as a cost weight. Production/processing charges already carried by material are
not added again. Per-suit cost divides an output's allocated acquisition value by
its own SUIT count, or its immutable PACK content count. For an assortment this is
an explicit average, not a claimed price/cost for each constituent design. Retained
rejected outputs receive separate value and do not inflate the accepted denominator.
Zero finished output produces an empty report instead of division by zero.

All conversion mutations use the existing company mutex and database transaction,
recheck available stock/FIFO/specification/calendar, use stable UUID replay keys,
freeze a posted payload hash, and preserve immutable history. Cost-neutral
inventory conversions need no invented zero journal. Existing damage/loss/actual
allocation accounting uses the existing source journal boundaries. Configurable
Processing routes and shared parties are untouched.

## Phase completion report

### 1. Features and deliverables

Existing Catalog SUIT variants and Production completion are reused. Added
versioned PACK specifications, actual packing and full recovery, warehouse output
receipts, container/content trace, stock/current valuation/quality/disposal inquiry,
historical batch/packing cost and per-suit breakdown, source lineage and real
private PDF/print documents. Direct Confirm and optional Save match the admin UX.

### 2. Files created and modified

New files:

- database/migrations/2026_10_26_000001_create_finished_goods_packing_tables.php
- app/Models/{ProductPackSpec,ProductPackSpecLine,PackingOrder,PackingMaterialLine,PackingOutputLine,Package,PackageContent}.php
- app/Modules/FinishedGoods/Services/{PackSpecificationService,PackingService,ActualCosting,FinishedGoodsWorkspace,FinishedGoodsQuality}.php
- app/Modules/FinishedGoods/Http/Controllers/{FinishedGoodsController,CostDocumentController}.php
- app/Modules/FinishedGoods/Http/Requests/{PackSpecificationRequest,PackingRequest,PackingActionRequest,FinishedQualityRequest}.php
- app/Policies/FinishedGoodsPolicy.php
- app/Modules/FinishedGoods/Resources/views/{nav,overview,specifications,index,form,show,batches,costing,inventory,trace}.blade.php
- app/Modules/Inventory/Services/StockCostDistribution.php
- app/Modules/Core/Services/TransactionDocumentRenderer.php
- resources/views/components/erp/finished-goods-navigation.blade.php
- resources/js/packing.js
- database/seeders/{FinishedGoodsConfigurationSeeder,FinishedGoodsSetupSeeder}.php
- database/factories/{ProductPackSpecFactory,ProductPackSpecLineFactory}.php
- tests/Support/FinishedGoodsFixtures.php
- tests/Feature/{FinishedGoodsTest,FinishedGoodsWorkspaceTest,FinishedGoodsCostIntegrationTest,FinishedGoodsConcurrencyTest,FinishedGoodsSetupTest}.php
- tests/Frontend/{packing.test,finished-goods-browser}.mjs
- docs/FINISHED_GOODS_COSTING.md

Updated integration files:

- app/Modules/FinishedGoods/Routes/web.php
- app/Modules/Catalog/Services/{CatalogService,CatalogWorkspace}.php
- app/Modules/Catalog/Resources/views/masters/item-fields.blade.php
- resources/js/{catalog,app}.js
- app/Modules/Production/Services/ProductionService.php (shared distribution delegation)
- app/Modules/Core/Services/{AuditLogger,TransactionDocument}.php
- app/Modules/Core/Http/Controllers/TransactionDocumentController.php
- app/Modules/Core/Support/DocumentPresentation.php
- app/Modules/Core/Resources/views/layouts/app.blade.php
- resources/views/components/erp/confirm-buttons.blade.php
- app/Providers/AppServiceProvider.php, config/erp_permissions.php
- database/seeders/DatabaseSeeder.php, tests/Support/inventory_worker.php
- AGENTS.md, README.md and the architecture/schema/state/testing/deployment docs below.

No package, independent product master, password or .env changes. Test/browser
artifacts are synthetic and ignored; database backup artifacts remain private.

### 3. Tables and migration

The additive migration implements the approved seven tables:

| Table | Purpose |
|---|---|
| product_pack_specs | Immutable versioned packed SKU definition |
| product_pack_spec_lines | Exact saleable/supply contents per PACK |
| packing_orders | Typed draft/posted conversion header |
| packing_material_lines | Actual input lots and negative movement references |
| packing_output_lines | Actual outputs, allocation fractions and positive movements |
| packages | Unique container metadata and represented PACK count |
| package_contents | Actual consumed-line provenance per container |

All FKs restrict deletion/update, with covering indexes. Header document_id is
PK/FK; typed lines have document/line uniqueness and nullable unique movement FKs.
Amounts/quantities/fractions retain approved exact precision. Spec versions and
container codes are unique; each item occurs once per spec, consolidating its
physical role. CHECKs enforce enum/boolean/positive quantity/fraction bounds.
No existing table columns or historical facts change. Configuration adds PACK
(count, precision 0) and PK numbering; application table total becomes 94.

### 4. Routes and screens

Sixteen authenticated, active-user, permission-protected routes under
/finished-goods, named finished-goods.*:

| Method | Path | Screen/action |
|---|---|---|
| GET | /finished-goods | Overview / prerequisites |
| GET/POST | /specifications | Browse / publish immutable recipe |
| GET/POST | /packing | Records / save or confirm |
| GET | /packing/create | Guided source/operation form |
| GET | /batches | Production completion context / costing |
| GET | /inventory | Current quantities/value/quality filters |
| POST | /quality | Audited inspection or Inventory damage delegation |
| GET | /trace/{layer} | Batches/jobs/lots/source cost lineage |
| GET | /costing/{document} | Posted batch/packing cost breakdown |
| GET | /costing/{document}/{format} | Printable/PDF cost statement |
| GET | /documents/{document} | Conversion detail, containers and history |
| POST | /documents/{document}/{action} | Confirm saved / cancel draft |
| GET | /documents/{document}/pdf | Private conversion PDF |
| GET | /documents/{document}/print | Clean print document |

Permissions: finished-goods.view/manage/confirm/quality. Existing Inventory
manage/post permissions additionally protect inspection/disposal, and Production
permissions protect completion. Scoped setup extends only existing super-admin
Finished Goods grants, preserves other roles and audits newly attached grants.
A separate Wholesale-only operator cannot enter the module or call its backend.

### 5. Checks and results

Final full regression: **198 passed / 1,433 assertions**, including 15 independent
concurrency cases; zero errors/failures/skips. Node 29 passed, Vite 68 modules,
whole-repository Pint, 145 compiled Blade/PHP checks and 16 cached routes passed.
Three new transaction/cost PDFs independently rendered and visually reviewed.
Local migration batch 8 and scoped four-permission setup succeeded after backup;
six actual local pages returned 200 with existing history/grants preserved.
All five module browser harnesses passed desktop/mobile, including eight Finished
Goods screens. 234 relative documentation links passed. Detailed evidence:
[TESTING.md](TESTING.md).

### 6. Known limits

- Local checks use MariaDB 10.4.32; exact MySQL 8.4 validation and real operator/
  accountant acceptance remain required before commercial deployment.
- Full-recovery unpack only; no partial-loss unpack, nested packs, post-sales
  repacking or package-by-package sales reservation/shipment allocation.
- A container is metadata for pack-lot stock. It does not have another editable
  stock quantity or independently enforced current serial custody.
- No new expense/payroll/transport input CRUD or late-cost adjustment/propagation
  editor. Existing actual posted allocations and recorded value history are used.
- Source openings without proven constituent history cannot be unpacked into
  invented contents; their trace remains the audited opening evidence.
- Published contents are immutable; changing saleable assortment needs another
  packed SKU. Draft corrections cancel/recreate; posted packing reversal
  automation is not exposed. Full recovery is a new physical conversion.
- Original output cost and current carrying value are different reports. Quality
  and descriptive master labels are current; price/rate/BOM edits do not rewrite
  monetary evidence. Trace/read graphs are limited to 5,000 nodes.
- Existing Production short-close/rework limits remain. Finished Goods adds no
  second completion receipt or manual finished suit quantity field.
- PDF PNG/WebP branding retains the existing GD requirement; JPEG/text fallback
  works without GD. No dependency was added.

### 7. Documentation

Updated AGENTS, README, PROJECT_OVERVIEW, PRD, ARCHITECTURE, DATABASE_SCHEMA, ERD,
DATABASE_DECISIONS, DATABASE_REVIEW, BUSINESS_RULES, MIGRATION_PLAN,
DEVELOPMENT_PHASES, PROJECT_STATE, CHANGELOG, TESTING, DEPLOYMENT,
database/INVENTORY_PRODUCTION and Catalog/Production guides to reflect this slice.
Earlier dated phase-only reports remain historical.

### 8. Suggested commit

`feat(finished-goods): add traceable packing and actual batch costing`

No Git commit is created in this non-Git workspace.

### 9. Readiness checklist

Implementation status and final evidence are in PROJECT_STATE/TESTING. Stop after
Phase 10. Local migration/configuration activation follows the already-authorized
workflow, after backup and verification; exact target DB/company acceptance
remains distinct from local development success.

- [x] Approved seven-table slice, shared variants/documents/stock/FIFO/journals.
- [x] Exact physical reconciliation per unit; no meter/SUIT/PACK conversion shortcut.
- [x] Explicit package contents, immutable versions and historical cost source graph.
- [x] Actual values, per-suit reporting, reject/disposal handling and transaction PDFs.
- [x] Idempotent/concurrent posting and backend/module authorization tests.
- [x] Guided screens with direct confirmation and optional draft preparation.
- [ ] Verify MySQL 8.4 target and company/accountant's allocation/inspection policy.
- [ ] Operator acceptance on actual approved packaging materials and source documents.
