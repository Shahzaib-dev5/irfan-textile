# Changelog

## Guided Finance follow-up (2026-10-29)

- Simplified payment/receipt/advance adjustment entry around the selected party role.
- Added scoped unpaid/credit summaries, account/method defaults and exact capped
  amount suggestions. Adjustment submits no new bank/cash movement.
- Retained original Advanced forms and existing posting/permission safeguards.
- No schema changes. See [FINANCE_GUIDED_UX.md](FINANCE_GUIDED_UX.md).

## Physical Suit Stock follow-up (2026-10-28)

The authorized [Physical Suit Stock workflow](PHYSICAL_SUIT_STOCK.md) simplifies ready-suit
entry, adds processed-fabric inventory and dated customer deliveries/debit-credit
history. Existing stock/FIFO/journals remain authoritative; no migration or duplicate
balances. Production and packing remain under Advanced Manufacturing. The guide
records files, routes, ledger effects, deployment and limits; see TESTING.md for
verification results.

## Processing follow-up ? 2026-10-27

The authorized [Fabric Out / Fabric In workflow](PROCESSING_FABRIC_FLOW.md) adds automatic
processed output identities, laat/component evidence, per-suit embroidery contracts
and atomic direct vendor handovers. Daily entry no longer requires manual routes
or jobs; historical and Production-linked workflows remain available. One additive
flow-history table and one service configuration preserve existing stock, journals
and permissions. Physical base-unit quantities remain authoritative; component
counts do not automatically create finished suits. Earlier Processing workflow
requirements below are historical where superseded by that guide.


## 2026-10-26 — Phase 12 finance

Added finance vouchers, audited chart/groups and bank setup, payments/receipts,
partial settlements, controlled journal reversals, source/party/inventory checks,
financial statements and bank evidence/matching. Connected supplier return credits
to billed purchase returns without another stock movement. Added guided screens,
backend permissions, A4 document downloads and independent payment races. Scoped
seeding preserves Wholesale access boundaries and other users. See
[ACCOUNTING_FINANCE.md](ACCOUNTING_FINANCE.md) for files/migrations and actual checks.


## Phase 11 - Wholesale Sales (2026-10-26)
Added eleven operational Sales tables and nullable line-bound reservation FK,
shared-customer orders/backorders, credit controls, finished inventory reservation,
partial dispatch, matched invoice, physical return, commercial credit and history.
Added guided sidebar/screens, item lookup, invoice previews, A4 PDFs and separate
Wholesale role/backend permissions. Inventory/FIFO and source journals are reused;
customer credit editing and Catalog references are protected. No dependency change.
Checks/activation: [TESTING.md](TESTING.md), [PROJECT_STATE.md](PROJECT_STATE.md).

## 2026-10-26 - Phase 10 Finished Goods and Costing

Added the approved seven-table packing slice, PACK measurement without hidden
SUIT conversion, immutable contents, actual FIFO packing/full recovery, container
trace and finished stock/quality/disposal inquiries. Added source-backed batch/
per-suit cost and historical/current value separation, including original/reversal
signed facts. Reused Production completion and Inventory posting, extracted shared
cost distribution/private PDF rendering, added costing/packing PDFs and guided
permission-aware screens. Added domain/HTTP/cost/race/setup/browser/Node checks.
No package, duplicated identity master, guessed cost or business stock seed.
Actual final verification/local activation is in PROJECT_STATE/TESTING.

## 2026-10-26 - Authorized local Production activation

Added scoped ProductionSetupSeeder and its idempotency/authorization/audit test.
Backed up the configured local database, applied the existing Phase 9 migration
(batch 7), seeded Production configuration/five existing-admin grants, and cleared
configuration/route/view caches. Verified seven local pages, preserved operational
history and other-role grants. Setup/workspace checks: 5 passed / 71 assertions.
No business transactions, credentials or .env edits. Target MySQL 8.4 acceptance
remains pending.

## 2026-10-26 - Phase 9 Manufacturing and Production

- Added nine approved BOM/batch/report/material/output/loss/cost tables and an
  optional Processing-job batch link; no existing posted history rewritten.
- Added immutable recipe versions, frozen batch plans, partial/final conversion,
  physical WIP lineage, retained rejects, actual FIFO and source-capped conversion
  cost absorption through existing StockLedger/SourceJournal boundaries.
- Added guided Production navigation, recipes, batches, consumption/output,
  progress/history and policy-protected batch/report PDF/print documents.
- Added production authorization, configuration seed/factories, domain/HTTP/cost/
  integration tests, two independent posting races and browser/Node checks.
- Preserved earlier Processing hash shape for unlinked jobs; protected absorbed
  cost sources and published master references. No application data/reset/.env or
  package changes. See [PRODUCTION.md](PRODUCTION.md) for actual checks and limits.

## 2026-10-22 - Direct admin confirmation and transaction PDFs

- Direct save/confirm across Purchasing/Processing, optional saved entries,
  one-action PO confirmation, retained approval audit and restricted-user access.
- Atomic preparation/finalization and stable replay keys reuse existing posting,
  locks, quantities, frozen rates, immutable history and journal rules.
- Eight professional A4 PDFs and clean print views; private authorized downloads
  never repeat posting. Dompdf 3.1.6 and four transitives added; no schema changes.
- Final full regression: 153 tests / 1,111 assertions including 11 races; 21 Node
  tests, Edge desktop/mobile checks, real PDF rendering, Pint, Vite, Composer,
  route cache/list/clear and 125 compiled-template checks passed.
- See SIMPLE_ADMIN_FLOW.md for the guide/completion report and TESTING.md for
  focused/full regression, race, rendering and browser evidence. Stop here.

## 2026-10-22 - Guided Textile master data

- Separate permission-aware Catalog sidebar, setup overview and master screens.
- Type-first item entry, compatible unit suggestions, clear stock/roll explanations,
  protected edit fields, optional fabric specifications and separate alternate units.
- Categories versus textile SKUs and definition-versus-stock examples; all seven
  item types and existing backend validation/posting remain unchanged.
- Two GET routes; no migration, package, permission or configuration changes.
- Full regression: 140 tests / 910 assertions, all nine races; 21 Node tests,
  Catalog/Purchasing/Processing desktop/mobile Edge smoke, Pint, Vite build
  (66 modules) and route cache/list/clear passed.
- See TEXTILE_MASTER_UX.md for the guide and completion report and TESTING.md
  for final regression evidence. Stop after this authorized UX follow-up.

## 2026-10-22 - Guided Purchasing screens

- Added a permission-aware Purchasing sidebar category, shared Supplier submenu,
  separate order/GRN/return/bill lists and preparation/review/edit URLs, overview,
  prerequisites, context loading and direct next-step links. Legacy URLs work.
- Preserved approval, draft editing and multi-order/multi-GRN preparation. Show
  remaining accepted/unbilled/return capacities, available original lot/roll stock,
  original receipt-cost suggestions and names in history filters. Corrected payable
  deep links to use the bill document ID. Posting services/schema are unchanged.
- Full suite: 131 tests / 836 assertions; 13 Node checks; final Purchasing and
  Processing desktop/mobile browser smoke; Pint, Vite build, route cache/list/clear
  and 119 compiled-template syntax checks passed. No application data/configuration
  seed or credential change. See PURCHASING_UX.md and TESTING.md.
- Ready for review; MySQL 8.4 and live acceptance remain pending. Stop after this
  authorized interface follow-up; no next business phase started.


## 2026-10-22 - Phase 8 guided Processing workspace

- Added permission-aware Processing sidebar submenus, independent sidebar scroll,
  a prerequisite checklist and separate service-type, route, job, dispatch,
  receipt, custody, contractor-bill, payable-source and report screens.
- Added source-scoped forms with inferred vendor, eligible ledger-derived stock,
  outstanding/unbilled limits, clear whole-roll guidance, quantity/charge previews
  and direct job-to-dispatch-to-receipt-to-bill links. Existing write protection
  remains authoritative; no schema, package or configuration seeder changes.
- Full regression: 120 tests / 726 assertions; final focused follow-up: 10 tests /
  103 assertions; eight Node tests, Edge desktop/mobile smoke, Pint, Vite build,
  route cache/list/clear and 116 compiled-template syntax checks passed.
- See PROCESSING_UX.md for the guide and completion report. Stop after Phase 8;
  MySQL 8.4 and live acceptance remain outstanding.


> Historical Phase 7 implementation report (2026-10-21): purchase orders/approval, partial GRNs,
> original lots/rolls, supplier returns, exact-cost stock bill matching and AP
> source records are implemented. See [PURCHASING.md](PURCHASING.md) for the
> actual schema, operating rules and limits. Processing/production/sales,
> payments and tax remain future scope; MySQL 8.4 validation is outstanding.

## 2026-10-21 - Phase 5 textile master data

- Added seven approved catalog tables for hierarchical categories, classified
  SKUs, fabric specifications, item conversions, designs, colors and finished
  variants, reusing units. Collections use design-season labels. No stock
  quantity or unapproved attribute/collection table was added.
- Added shared Catalog list/read/create/edit and conversion screens, filters,
  pagination, policies, audited transactions, company-row cycle locking,
  exact BCMath conversions, factories and safe reference seeders.
- Full suite: 39 tests passed, 241 assertions. Pint, Composer validation/platform,
  Blade/route caching and Vite build passed. MariaDB 10.4.32 was available;
  target MySQL 8.4 and independent-connection contention remain unverified.
- Phase 5 is ready for review. Inventory and further phases were not started.

## 2026-10-21 - User approval

- User approved all completed phases (1-4). Target MySQL 8.4 validation remains
  outstanding; no next phase was started.

## 2026-10-20 - Phase 4 party management

- Implemented the approved shared party identity, business roles/profiles,
  contacts/addresses, vendor service capabilities and effective-dated rate
  management. Supplier, vendor and customer screens use Purchasing, Processing
  and Sales permissions while sharing one identity record.
- Added the approved unit/payment-term prerequisites and stable configuration
  seeder, rate overlap locking, audit events, role deactivation and Blade CRUD
  screens. No purchase orders, jobs, stock movements, invoices or postings were
  added.
- Full suite: 24 tests and 80 assertions pass; Pint formatting, route, view and
  Vite checks are recorded in `PROJECT_STATE.md`. Runtime was MariaDB 10.4.32;
  MySQL 8.4 validation remains outstanding.

## 2026-10-20 - Phase 3 authentication and company setup

- Added active-user session authentication, throttled login/password-reset flows,
  one-time interactive administrator bootstrap, and policy/middleware-backed
  role and module-permission administration.
- Added company singleton setup with private logo storage, PKR-only currency
  master, configurable financial-year start, financial years and periods.
- Added redacted append-only audit events, transaction services, migrations,
  seeders, factories and 13 Phase 3 feature tests. Full suite: 21 tests passed;
  Pint, route-list and Vite production build passed.
- Executed migration/tests on local MariaDB 10.4.32; MySQL 8.4 target validation
  remains outstanding. No Purchasing or Inventory functionality was added.

## 2026-10-20 — Phase 2 Laravel foundation

- Initialized Laravel 13.38.0 on the official Laravel project skeleton with PHP
  8.3+, Composer, Blade, Tailwind CSS 4, Alpine.js, Inter and Vite.
- Added a config-driven registry and route/view conventions for all 14 ERP
  modules, session login/logout, responsive ERP layout, health endpoints and
  dashboard shell.
- Added only the Phase 1-approved users/session/password-reset migration. No
  business CRUD, tables or transactions were implemented.
- Added route/database smoke tests, Pint scripts, environment examples and
  runnable setup instructions. Eight tests and the production asset build pass.
- The database smoke test used local MariaDB 10.4.32 via PDO MySQL; MySQL 8.4
  validation remains outstanding.

## 2026-10-20 — Phase 1 database architecture approved

- Approved the documented 138-table MySQL 8.4 architecture by authorizing Phase
  2. The schema remains documentation, not business DDL.
- Preserved company/accountant approval gates for specific posting policies and
  statutory rates.

## 2026-10-21 - Phase 6 Warehouses and Inventory

Added warehouse/location setup and contractor-role custody, lots/rolls, immutable
stock and FIFO/value ledgers, typed evidenced opening/count/damage/write-off and
transfer documents, reservations, posting keys/version checks, locked numbering,
exact cost reversals, balanced stock journals and reconciliation/repair screens.
Added 25 approved tables, minimum accounting prerequisites, eight permissions,
policies, services, requests, Blade screens, factories/configuration and actual
independent-worker race tests. Extended audit metadata, financial-year sequences
and Catalog stock-use identity guards. No operational quantities are seeded.
See WAREHOUSES_INVENTORY.md and PROJECT_STATE.md for scope, checks and target-DB
limitations. Stop after Phase 6.

## 2026-10-21 - Phase 7 Purchasing

Implemented shared-Supplier purchase orders/submission/approval/closure,
partial GRNs with original lot/roll identities and rejected-delivery evidence,
zero accepted-quantity tolerance, bounded supplier stock returns, exact-cost
receipt-matched bills/AP sources, historical prices and purchase-order printing.
Added ten approved tables, six permissions, purchasing models/services/policy/
requests/controller/views/routes/configuration and functional/concurrency tests.
Shared SourceJournal now serves Inventory and Purchasing; Inventory document
operations reject purchasing roots. Extended Catalog operational references,
financial-year sequences and navigation/printing. No production reset, fabricated
stock, tax/processing jobs or subsequent phase implementation.

## 2026-10-21 ? Phase 8 processing and contractor management

Added configurable service types and versioned routes, audited immutable job rate
snapshots/negotiated overrides, contractor dispatch challans, partial accepted and
rejected returns, normal/abnormal loss costing, traced output rolls, exact-layer
custody balances/reconciliation and exact-rate contractor bill/AP-source matching.
Added ten approved tables, eight permissions, Blade screens/printing and lifecycle,
route, authorization, conservation and independent-connection concurrency tests.
Reused shared parties, FIFO/value ledgers, document numbering and SourceJournal;
blocked generic inventory depletion of job custody. Updated phase documentation
and installation guidance. No application reset, fabricated stock, new dependencies,
Production/sales/payment implementation or phase advancement occurred.
