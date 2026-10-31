# Product requirements

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


## Phase 12 delivered scope (2026-10-26)

Authorized finance work covers chart/groups, existing calendar, dimensioned balanced
journals, cash/bank configuration, supplier/contractor payables, customer receivables,
payments/receipts, explicit settlement, audited opening balances/reversals, bank
reconciliation and financial statements. Direct Confirm supports the single admin;
Wholesale has no finance access by default. Prior source documents recognize costs
and revenue once. Tax/payroll/statutory filing and automated year-close remain future.
See [ACCOUNTING_FINANCE.md](ACCOUNTING_FINANCE.md) and current verification state.


## Phase 11 Wholesale implementation (2026-10-26)
Sales orders may create backorders; only available released stock can reserve or
dispatch. Dispatch recognizes COGS/revenue, matched invoice creates billed AR once.
Returns restore stock; separate commercial credit reduces customer AR. Credit
includes AR, unbilled delivery and unfulfilled commitments. Guided separate screens,
searchable finished items, customer history and A4 PDFs serve the Wholesale role.
Tax/payments are deferred; no quotations required by approved Sales design.
See [SALES.md](SALES.md). Stop after Phase 11; older scope reports are historical.

## Phase 10 delivery (2026-10-26)

Finished Goods reuses finished SUIT variants and Production completion, adds the
approved seven packing tables and a separate whole-count PACK unit/SKU. Operators
publish immutable contents, confirm actual lot consumption/output, serialize
containers and review source-backed historical and current costs. A container
never adds another stock balance. Different units reconcile independently; PACK
contents are a specification, not a conversion factor. Rejected/held goods retain
quality restrictions and actual disposal delegates to Inventory. Batch/packing
cost statements and conversion documents have private PDFs/clean print views.
Full-recovery unpack only, no nested/post-sales repacking or invented costs. See
[FINISHED_GOODS_COSTING.md](FINISHED_GOODS_COSTING.md) for final checks and limits.

## Phase 9 delivery (2026-10-26)

Manufacturing requirements are implemented by the nine approved BOM/batch/report
tables, the Production sidebar workspace and existing stock/accounting services.
A batch allocates one finished design/color variant and freezes its component
plan. Actual conversion reports may receive partial finished suits, same-unit
intermediate WIP and recoverable scrap while recording measured loss. Completion
requires the planned output accounted and no intermediate WIP/vendor custody.
Direct Confirm and optional Save for later preserve the admin workflow. A fully
received batch may close through a no-movement final report. Recipes and posted
history are immutable. See [PRODUCTION.md](PRODUCTION.md) for acceptance evidence,
field instructions and limitations; packing/sales/payments are not delivered.

## Simple admin confirmation and PDFs (2026-10-22)

One administrator can complete Purchasing/Processing forms with a direct Confirm action. Save for later is optional. Existing approval/post permissions remain backend access boundaries for the separate Wholesale user. Eight transaction types provide real PDF downloads and clean print views. Wholesale sales remains future scope.
See [SIMPLE_ADMIN_FLOW.md](SIMPLE_ADMIN_FLOW.md).

## Guided Textile master follow-up (2026-10-22)

The authorized Catalog UX follow-up separates master navigation, explains category
versus stock item, and guides type-specific item setup with required composition,
optional specifications and clear stock/roll choices. Existing identity protection,
unit compatibility, permissions and no-quantity-in-master requirements remain.
See [TEXTILE_MASTER_UX.md](TEXTILE_MASTER_UX.md). No later phase is authorized.

## Guided Purchasing follow-up (2026-10-22)

The authorized Purchasing interface follow-up adds a grouped sidebar, separate order/GRN/return/bill screens, contextual preparation and direct next actions. Existing draft editing, approval, partial fulfillment and multi-source exact-cost matching remain supported.
See [PURCHASING_UX.md](PURCHASING_UX.md).


## Phase 8 guided workspace (2026-10-22)

Processing has a guided workspace: a setup checklist, separate typed workflow screens, vendor/job selectors, scoped stock choices and explicit next actions. Server authorization and posting remain authoritative; no business rule or schema is relaxed.
See [PROCESSING_UX.md](PROCESSING_UX.md).

> Phase 8 implementation (2026-10-21): configurable processing routes/jobs,
> contractor dispatch/custody, partial accepted/rejected returns, loss costing,
> roll lineage and exact-rate contractor bill/AP sources are implemented.
> See [PROCESSING.md](PROCESSING.md) for actual behavior and explicit boundaries.
> Production/sales/payments/tax remain future scope; MySQL 8.4 validation is pending.

Status: product requirements baseline. Phase 1 database architecture is
approved. Phases 2-3 foundation and authentication/company setup are implemented.
Phase 4 implements shared party management, role-specific profiles, contacts,
addresses and vendor service-rate administration. Phase 5 adds textile Catalog
masters, unit conversions and design/collection/product definitions. Remaining operational
capabilities are requirements unless called out in project state.

## Users and responsibilities

Purchasing staff manage supplier roles and procurement. Warehouse staff receive,
measure, move and count stock. Processing staff manage vendors, capabilities,
routes, challans and acceptance. Production/packing staff record actual materials,
good output and losses. Sales staff manage customer roles, wholesale orders,
dispatch and invoicing. Finance staff post bills, expenses, payroll, settlements
and period close. Administrators manage configuration and access without rewriting
posted business history.

## Required capabilities

| Area | Required behavior | Evidence in this phase |
|---|---|---|
| Identity | Secure session sign-in/out, password reset, active-user restrictions, user administration and module permissions | Phase 3 controllers, requests, policies, middleware, views and automated tests |
| Company setup | Singleton company profile, logo, business settings, PKR currency, financial years and accounting periods | Phase 3 migrations, settings/calendar services and Blade screens |
| Parties | One identity with multiple role profiles, contacts and addresses | Phase 4 shared master, transactional role assignment, module-owned screens and tests |
| Vendor capabilities | Configurable service catalog, effective-dated PKR rates and meter-based dyeing | Phase 4 seeded service configuration, overlap-safe rates screen/service and tests |
| Catalog | Grey/processed fabric, consumables/materials, packaging, suit variants, units, designs/colors/collection labels | Phase 5 models, migrations, screens, services, policies, factories, seeders and tests; no stock quantities |
| Procurement | Orders, lot/roll receipt, invoice matching, returns, credit notes, AP | Phase 7 approved orders, partial GRNs, returns, exact-cost stock bill/AP-source services, screens and tests; taxes/credits/settlement remain future scope |
| Inventory | Canonical units, no negative stock, reservations, transfer/transit, adjustments, FIFO valuation | Phase 6 services, typed documents, Blade screens, FIFO/value ledger and concurrency tests |
| Processing | Configurable route versions, historical rates, custody, partial/multiple outputs, losses, contractor charges | Phase 8 route/job/contractor custody, partial accepted/rejected returns, loss costing, frozen charges and exact-rate AP sources; see PROCESSING.md boundaries |
| Production | BOM versions, actual consumption, WIP, output, waste, labor/overhead and per-suit cost | Production specifications |
| Packing | Packaging consumption, carton/package content and traceability | Finished goods specifications |
| Sales | Wholesale order, credit check, reservation, dispatch, invoice, physical return/credit, AR | Sales specifications |
| Accounting | Balanced GL, open items, payments/receipts, allocation, expense, bank reconciliation, periods | Financial specifications |
| Payroll | Employee terms, attendance, component snapshots, payroll journal and settlement | HR/payroll specifications |
| Governance | Permissions, numbering, idempotency, immutable posting and audit | Phase 3 implements user roles/permissions and audited setup changes; Phase 6 implements inventory document numbering, idempotency and immutable posting |
| Reporting | Stock/custody, lot trace, batch cost, aging, trial balance, GL, P&L, balance sheet | Phase 6 stock/custody, movement and reconciliation screens; financial/batch reports remain future scope |

## Nonfunctional acceptance criteria for implementation

Exact DECIMAL arithmetic; foreign-key integrity; atomic stock and financial
posting; safe retries under contention; explicit posting authorization; immutable
posted evidence; testable reversals; deterministic schema changes; recoverable
backups; private attachments; redacted audit records; paginated indexed reporting.
Laravel-native modular boundaries and reusable Blade components are required.
The English Inter application shell is in place; Phase 3 setup and Phase 4 party
screens use it and show PKR rates. Operational transaction UX remains future work.

## Explicit limits and unresolved approvals

No multicompany, foreign currency, third-party-owned consignment processing,
piecework payroll engine, fixed-asset depreciation, ecommerce, customer portal or
statutory filing integration is implied. These are scope boundaries, not hidden
features. Pakistan tax treatment, payroll deductions, digital invoice obligations
and retention rules require business/accountant approval before implementation.
See [DATABASE_DECISIONS](DATABASE_DECISIONS.md) for the exact proposed policies.
