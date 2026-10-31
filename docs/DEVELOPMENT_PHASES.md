# Development phases

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


## Current authorized phase: 12 (2026-10-26)

Accounting and Finance implementation covers eleven additive tables and existing
source-ledger integration. Final validation/local activation is tracked in
[PROJECT_STATE.md](PROJECT_STATE.md). Do not begin Phase 13, tax or payroll.
See [ACCOUNTING_FINANCE.md](ACCOUNTING_FINANCE.md) for actual scope and exclusions.


## Current authorized delivery: Phase 11 (2026-10-26)
Implement Wholesale Sales and Customers through orders, credit/reservations,
dispatch, matched invoices, returns/credits, history and A4 documents. Preserve
Phases 1-10. Scope ends at Phase 11; collections, tax and general accounting/payroll
CRUD are future requests. Actual status: [PROJECT_STATE.md](PROJECT_STATE.md);
completion report: [SALES.md](SALES.md). Earlier stop notes are historical.

## Current scope: Phase 10 (2026-10-26)

The explicit Phase 10 request supersedes the Phase 9 stop. Finished Goods now
reuses SUIT variants/completion and adds explicit PACK contents, atomic physical
packing/full recovery, actual source costing, per-suit reporting, traceable
containers and finished stock/quality/disposal views. It preserves shared parties,
configurable Processing and all prior ledgers. Stop after Phase 10; sales,
payments, tax and general accounting/payroll CRUD require another explicit request.
See [FINISHED_GOODS_COSTING.md](FINISHED_GOODS_COSTING.md) for the complete report.

## Current delivery: Phase 9 (2026-10-26)

The explicit Phase 9 request supersedes older Production-is-future notes. The
approved BOM/batch/report slice, actual consumption/output/loss, WIP/cost lineage,
configurable Processing coordination, guided Blade screens, batch/report PDFs
and automated tests are implemented. No packing, wholesale, payment or general
accounting/payroll CRUD is included. Stop after Phase 9. The complete report is
[PRODUCTION.md](PRODUCTION.md); deployment/acceptance limits remain explicit.

## Simple admin confirmation and PDFs (2026-10-22)

The authorized simple-admin/PDF follow-up adds direct confirmations and proper transaction documents to completed Purchasing/Processing, preserving backend access boundaries for a separate Wholesale user. No later business phase, stock workflow, schema or wholesale sales module is implemented.
See [SIMPLE_ADMIN_FLOW.md](SIMPLE_ADMIN_FLOW.md).

## Guided Textile master follow-up (2026-10-22)

A further user-authorized UI follow-up adds separate Catalog sidebar screens,
guided type-specific item forms and standalone alternate-unit maintenance. It
preserves Phase 5 master rules and all existing Purchasing/Processing behavior.
No new tables, packages or business phase. Stop after this follow-up; see
[TEXTILE_MASTER_UX.md](TEXTILE_MASTER_UX.md) and TESTING.md for completion evidence.

## Guided Purchasing follow-up (2026-10-22)

The user explicitly authorized the same separate-screen UX for existing Purchasing after the Processing improvement. This interface follow-up adds no phase, schema, payment, sales, Production or opening-stock migration authorization.
See [PURCHASING_UX.md](PURCHASING_UX.md).


## Phase 8 guided workspace (2026-10-22)

The user authorized a Phase 8 interface follow-up: separate Processing screens, nested sidebar, prerequisite/help text and next-step actions. It adds no tables/migrations and does not authorize a later phase, go-live stock import or roll splitting.
See [PROCESSING_UX.md](PROCESSING_UX.md).

> Phase 8 implementation (2026-10-21): configurable processing routes/jobs,
> contractor dispatch/custody, partial accepted/rejected returns, loss costing,
> roll lineage and exact-rate contractor bill/AP sources are implemented.
> See [PROCESSING.md](PROCESSING.md) for actual behavior and explicit boundaries.
> Production/sales/payments/tax remain future scope; MySQL 8.4 validation is pending.

## Completed phases

**Phase 1 — Database architecture:** approved architectural documentation for
the workflow, module boundaries, full 138-table specification, ERDs, business
rules, migration order and design review. This is not executable DDL.

**Phase 2 — Laravel foundation:** completed Laravel 13 foundation, environment
configuration, module registry, Blade/Vite/Tailwind/Alpine layout, session
authentication shell, health page, protected dashboard shell and smoke tests.
The Phase 2 report is retained in `PROJECT_STATE.md`.

**Phase 3 — Authentication and company setup:** implements login/logout,
password reset, active-user enforcement, role and permission authorization,
company profile and private logo, PKR currency master, audit metadata, financial
years and accounting periods. Ten setup tables are added on top of the Phase 2
authentication/session tables. No purchasing, inventory or operational postings
are implemented. Current completion evidence is in `PROJECT_STATE.md`.

**Phase 4 — Party Management:** implements one shared party master with supplier,
vendor and customer roles, role profiles, multiple contacts/addresses, vendor
processing capabilities and effective-dated PKR rates. It also implements the
approved `units` and `payment_terms` master dependencies. Supplier, vendor and
customer screens are owned by Purchasing, Processing and Sales respectively.
No purchase orders, processing jobs, inventory movements, production, invoices
or accounting postings are implemented. Migration and feature tests ran on local
MariaDB 10.4.32; MySQL 8.4 validation remains outstanding. Evidence is in
`PROJECT_STATE.md`.

**Phase 5 — Textile master data:** implements units, hierarchical categories,
classified SKUs, fabric specifications, item-specific exact conversions,
collections via design season labels, colors and finished suit variants.
Reusable list/read/create/edit screens have policies, filters, pagination and
audited status maintenance. Configuration seeders and factories create no stock.
See TEXTILE_MASTER_DATA.md and PROJECT_STATE.md for details and evidence.

**Phase 6: Warehouses and Inventory:** implements storage, lots/rolls,
stock/FIFO/value ledgers, typed inventory documents, openings/counts/issues,
transfers, reservations, reversals and reconciliation, plus the minimum stock
accounting dependencies. No purchasing or processing job workflows.
See WAREHOUSES_INVENTORY.md and PROJECT_STATE.md for checks and scope.

**Phase 7 - Purchasing:** supplier orders, partial GRNs, returns, matched supplier
bills and payable sources, price history and printed/PDF documents are implemented.

**Phase 8 - Processing:** configurable routes, assigned jobs, frozen vendor rates,
challans, custody, partial returns/loss, contractor bills and guided/PDF screens
are implemented.

**Phase 9 - Manufacturing:** versioned BOMs, frozen batch plans, actual partial/final
conversion, traceable WIP, losses/rejects, source-capped costs, Processing links and
batch/report screens/PDFs are implemented. See PRODUCTION.md for current checks.

## Historical roadmap before Phases 7-9

Phase 7 implements supplier orders/approval, partial GRNs, returns, exact-cost
stock bill matching/AP sources and history/printing.
Phase 8 implements configurable processing routes/jobs, custody, partial returns,
loss costing and matched contractor AP sources. Stop after Phase 8 for review.
Possible subsequent phases include production/finished goods;
sales; HR/payroll; reporting and deployment hardening. These are planning topics,
not permission to proceed. The user must explicitly request each phase and its
scope. Resolve the company/accountant policy gates in
[DATABASE_DECISIONS](DATABASE_DECISIONS.md) before implementing the affected
posting workflows.

Every phase must preserve prior functionality, provide real services/migrations/
screens/tests where applicable, update documentation, run relevant checks and
produce the mandatory completion report. Never advance silently.
