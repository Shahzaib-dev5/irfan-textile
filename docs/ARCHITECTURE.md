# Module architecture and aggregate boundaries

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


## Phase 12 boundaries (2026-10-26)

FinanceService owns financial vouchers, dimensioned opening/manual/cash journals,
allocations and financial reversals. FinanceAccounts owns audited master setup;
BankReconciliationService owns immutable bank evidence/matching; FinanceReports reads
shared journals/open items/stock values. SupplierCreditService closes the billed
purchase-return clearing gap. FinanceDocument reuses the secure A4 PDF renderer.
Company-row transactions, stable UUID/payload hashes, period checks and existing
numbering/events are reused. No operational stock logic or source journal is replayed.
See [ACCOUNTING_FINANCE.md](ACCOUNTING_FINANCE.md).


## Phase 11 module boundaries (2026-10-26)
SalesService owns commercial caps/credit/matching and uses StockLedger plus the
Inventory-owned SalesReservations boundary under the company/customer locks.
SourceJournal creates one balanced journal sidecar per dispatch/invoice/return/
credit. SalesDocument projects A4 output through the existing secure renderer.
Policies, middleware, Form Requests and services protect Wholesale backend access.
Customer master stays in Parties; credit administration has a distinct permission.
See [SALES.md](SALES.md) for actual files and transaction rules.

## Phase 10 boundaries (2026-10-26)

FinishedGoods now contains PackSpecificationService (immutable publication),
PackingService (typed conversion lifecycle, content/FIFO/capacity/replay checks),
FinishedGoodsQuality (authorized Inventory delegation), FinishedGoodsWorkspace
(read models and coherent current stock snapshot) and ActualCosting (read-only
historical value/cost graph). Thin controllers/Form Requests/FinishedGoodsPolicy
and authenticated module routes serve the guided Blade workspace.

StockLedger owns all stock/FIFO/value writes. The unchanged Production source-to-
output rounding algorithm is extracted into shared StockCostDistribution and
called by both Production and Packing. Cost-neutral conversions create no fake
journal. No quantity/value total is stored on a pack SKU/container or batch.
TransactionDocumentRenderer is extracted from the existing private PDF controller;
all earlier document types and new packing/cost statements reuse its same security
and rendering options. No package is added. See FINISHED_GOODS_COSTING.md.

## Phase 9 application boundaries (2026-10-26)

`BomService` publishes immutable recipe versions; `ProductionService` owns batch
planning, report preparation/confirmation/cancellation and exactly-once posting.
`ProductionProgress` follows physical WIP through cost links, transfers and
Processing; `ProductionWorkspace` supplies read-only form choices. Thin controllers,
Form Requests, ProductionPolicy, route permissions and the existing module registry
serve Blade screens. No new module registration mechanism or package is introduced.

Stock effects exclusively call existing StockLedger movement/FIFO/value/link/
refresh primitives; balanced net journals use SourceJournal. Existing calendar,
numbering and document events use PurchaseDocuments infrastructure. Company-row
locking serializes stock posting, BOM versions, cost caps and completion; stable
UUID confirmation keys and frozen payload hashes reject duplicate/changed requests.
A nullable Processing-job batch FK provides explicit coordination without owning
another contractor stock ledger. Null links are omitted from legacy job hashes to
preserve earlier posted payloads. Core TransactionDocument renders batch/report
PDFs through the existing private renderer. See [PRODUCTION.md](PRODUCTION.md).

## Simple admin confirmation and PDFs (2026-10-22)

Domain confirm methods orchestrate existing save/submit/post services inside the company-locked transaction. Stable UUIDv5 keys make new-create confirmations replay-safe; payload validation, existing locks, posted hashes and number allocation remain authoritative. Core TransactionDocument projects read-only document data; its policy-protected controller renders clean HTML or Dompdf output. No schema/state enum changes.
See [SIMPLE_ADMIN_FLOW.md](SIMPLE_ADMIN_FLOW.md).

## Guided Textile master follow-up (2026-10-22)

CatalogWorkspace is a read-only presentation helper for field guidance, existing
identity locks and compatible alternate-unit choices. Existing CatalogRegistry,
CatalogService, Form Requests, policies, transactions and audit writes remain the
domain boundary. Two GET routes expose the overview and standalone conversions;
Alpine catalogItem controls guided field visibility/default suggestions without
posting stock. Shared layout adds the permission-aware Catalog sidebar component.
See [TEXTILE_MASTER_UX.md](TEXTILE_MASTER_UX.md). No schema/package changes.

## Guided Purchasing follow-up (2026-10-22)

PurchasingWorkspace is a read-only query service for source context, posted-history capacities and movement/reservation-derived original return stock. Typed routes reuse existing policies, Form Requests, thin controllers and Blade forms. PurchasingService, PurchaseDocuments and SourceJournal are unchanged; client choice/suggested-rate data never authorizes posting.
See [PURCHASING_UX.md](PURCHASING_UX.md).


## Phase 8 guided workspace (2026-10-22)

ProcessingWorkspace is a read-only application query service for setup hints and scoped form choices. It reads stock movement/reservation history and posted custody/bill matches. Typed routes reuse thin controllers and shared Blade forms; ProcessingService still owns all posting, locking, snapshots and idempotency. Alpine calculations are estimates only.
See [PROCESSING_UX.md](PROCESSING_UX.md).

> Phase 8 implementation (2026-10-21): configurable processing routes/jobs,
> contractor dispatch/custody, partial accepted/rejected returns, loss costing,
> roll lineage and exact-rate contractor bill/AP sources are implemented.
> See [PROCESSING.md](PROCESSING.md) for actual behavior and explicit boundaries.
> Production/sales/payments/tax remain future scope; MySQL 8.4 validation is pending.

Status: approved modular monolith. Phase 2 established module registration and
route/view resolution. Phase 3 implements Core company setup, Identity access
control and Accounting financial-calendar configuration. Phase 4 implements
shared Parties management, with route entry points and authorization in
Purchasing (suppliers), Processing (vendors) and Sales (customers). Phase 5 adds
Catalog master maintenance, exact item conversions and shared Blade forms.
Collections use the approved designs.season descriptor; no separate master or
EAV attribute store was introduced. Catalog mutations serialize on the company
row, validate real model relationships, and emit atomic audit snapshots. Phase 6 implements warehouse and inventory aggregates, FIFO/value history and minimum
balanced stock journals. Phase 7 adds approved purchase orders, GRNs, returns and
exact-cost supplier stock bills/AP sources. Phase 8 adds Processing routes/jobs,
custody, returns/loss and contractor bills. Production and sales remain inactive.

## Boundaries

| Module | Owned aggregate roots | Responsibilities and dependencies |
|---|---|---|
| Core | company, company setting, audit event, document, sequence | Configuration, document envelope, lifecycle, audit; Identity actors, Accounting periods |
| Identity | user, access role, permission | Sessions, password reset, module authorization; no business party roles |
| Parties | party with addresses/contacts/roles/profiles | Shared identity, role assignment, vendor capabilities/rate history; UI entry points owned by Purchasing/Processing/Sales |
| Catalog | item, design, variant, unit | Stable stock identities and conversion definitions |
| Warehouses | warehouse/location | Company locations, transit and contractor custody; vendor role |
| Inventory | stock position, lot/roll, reservation, stock/cost posting | Quantity/value ledgers, FIFO layers, transfer and adjustment; Catalog and documents |
| Purchasing | purchase order, receipt, invoice, return, credit note | Supplier profile, Catalog, Inventory and Accounting |
| Processing | route version, job and steps, dispatch, receipt | Vendor capability/rates, custody and transformations; Catalog, Inventory, Purchasing billing |
| Production | BOM version, manufacturing batch, production report | Consumption, WIP, output/waste and cost allocation; Catalog, Inventory, Accounting |
| FinishedGoods | pack specification, packing order, package | Packaging transformation and logistics grouping; Catalog, Inventory, Production |
| Sales | sales order, dispatch, invoice, return, credit note | Customer profile, reservation/credit exposure, Inventory, Accounting |
| Accounting | fiscal calendar, account tree, journal, open item, settlement, bank reconciliation | Authoritative GL and financial controls; common documents and parties |
| HRPayroll | employee, employment terms, attendance, payroll run | Salary snapshots, approved payable journals; Parties, Identity, Accounting |
| Reporting | read/query models | Reads ledgers and domain joins; never a separate authority for balances |

The document envelope is common infrastructure, not a domain-model substitute.
Typed headers and lines retain real FKs. Journal entries are document sidecars.
Every transactional aggregate validates its whole set of lines, state and source
references before posting; optional polymorphic audit labels cannot drive posting.

## Mutation and dependency rules

An owning service changes its aggregate. A posting action coordinates inventory,
valuation, journal/open items and document state in **one InnoDB transaction**.
No asynchronous queue can be the sole route to the financial or physical effect
of a successful post. Queues may later deliver notifications or refresh optional
reports after commit. Cross-module readers use relationships/queries; callers do
not directly mutate another module's tables.

Document roots are locked before draft lines, posting or reversal. Masters with
temporal children use the master as a mutex. Inventory posting serializes shared
lot/position/layer resources; financial period closure uses the same period lock
as posting. Unique constraints remain the final race guard. Deadlocks are
expected and retried for the entire transaction with the original request key.

The company is single-tenant; repeating company_id on every table would create
redundant filters without implementing tenancy. A future multicompany change
requires a reviewed migration of all natural keys, calendars, sequences, stock
ownership, control accounts and permissions.

## Laravel organization in the foundation

Laravel follows the standard project structure. `App\Modules\` maps to
`app/Modules/`; each module has `Routes/web.php` and `Resources/views/`. The
`config/modules.php` registry maps stable module keys to directories. The
`ModuleServiceProvider` loads module routes inside Laravel's `web` middleware and
registers a `module.<key>::` Blade namespace. Core owns the foundation dashboard
and health routes/views; Identity owns the login/logout routes and login screen.
Shared reusable Blade components live in `resources/views/components/erp/`.

New business code should keep controllers thin, validate input with Form Requests,
place transactions in module actions/services, and use Eloquent relationships,
policies and tests. Do not add a modular-framework package, generic repository
layer or event-sourcing framework solely to realize this design. The application
implements business services, models, migrations and CRUD screens for
authentication/setup (Phase 3), party management (Phase 4) and Catalog textile
masters (Phase 5), warehouses and core inventory (Phase 6). Later business modules require explicit phase authorization.

## Phase 7 integration boundary

Purchasing owns supplier commitments and receipt/bill matching. Inventory's
StockLedger owns quantity/FIFO/value primitives, called only inside the shared
company-locked source posting transaction. Accounting SourceJournal writes one
balanced sidecar and party-control open items keyed to that source document;
Inventory also uses it. There is no second journal document or payable cache.
PurchaseDocuments coordinates purchasing lifecycle, numbering and audit.
Supplier identities remain in Parties; document permissions belong to Purchasing.
Inventory document actions enforce their own module scope to prevent cross-module
cancellation/posting/reversal bypass. See PURCHASING.md for exact contracts.

## Phase 8 processing implementation

ProcessingRoutes owns service definitions and draft/published route versions.
ProcessingService owns immutable job snapshots and typed dispatch, receipt/return
and contractor bill document transactions. ProcessingPolicy and permission
middleware protect the interface/backend; service-level checks reload the actor.
Shared party Vendor profiles/capabilities/rates remain in Parties. Purchasing
purchase_invoices and invoice lines are reused for contractor bills, with the
approved processing match relation; no independent bill/party identity is added.
SourceJournal and PurchaseDocuments provide the established journal, calendar,
numbering and status history contracts, while StockLedger handles quantity/value
entries and exact FIFO custody layers under the same company mutex. No new package
or frontend framework is introduced. See PROCESSING.md for invariants and limits.
