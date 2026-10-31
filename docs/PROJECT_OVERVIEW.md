# Project overview

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


## Phase 12 finance (2026-10-26)

Accounting & finance now provides the operational bookkeeping workspace, reusing the
same ERP documents and shared parties. Actual payment and receipt vouchers connect
to unpaid bills through append-only settlements. Source journals, reports and
controlled reversals preserve traceability. See [ACCOUNTING_FINANCE.md](ACCOUNTING_FINANCE.md).


## Phase 11 current scope (2026-10-26)
Wholesale sales uses existing Customer profiles on shared Parties. Guided orders,
credit limits, reservations, dispatch/invoice matching, returns/credits, history,
availability and A4 documents extend completed Phase 10. No payment/tax/general
accounting CRUD. Read [SALES.md](SALES.md); prior phase scope notes are historical.

## Phase 10 Finished Goods and Costing (2026-10-26)

Existing Production suit receipts/completion and Catalog variants now connect to
explicit PACK specifications, atomic packing/full recovery, warehouse output lots
and container contents. Actual batch/per-suit cost follows posted FIFO/value/cost
links through Purchasing, configurable Processing, Production and packing. Current
stock value, retained rejects and actual disposal use existing Inventory history.
No second finished receipt, manual item quantity or fabricated manufacturing cost.
See [FINISHED_GOODS_COSTING.md](FINISHED_GOODS_COSTING.md). Stop after Phase 10.

## Phase 9 implemented scope (2026-10-26)

Production now connects the existing procurement/processing stock to finished
suit output through published BOM versions, confirmed batch plans and actual
conversion reports. Partial outputs and physical intermediate WIP retain lot,
FIFO cost and contractor lineage. The guided workspace includes batch/report PDF
and print documents. Packing, wholesale transactions and payments remain future
scope. See [PRODUCTION.md](PRODUCTION.md); older phase reports are historical.

## Simple admin confirmation and PDFs (2026-10-22)

Purchasing and Processing now use one main Confirm action and optional Save for later. The admin follows order/receipt/bill or job/dispatch/receipt/bill next actions without compulsory draft-review screens. Real A4 PDF downloads and clean printable documents are available for all eight types; Wholesale remains separately authorized future sales scope.
See [SIMPLE_ADMIN_FLOW.md](SIMPLE_ADMIN_FLOW.md).

## Guided Textile master follow-up (2026-10-22)

Textile master data now provides a sidebar setup overview and separate items,
categories, units, designs, colors, finished variants and collections screens.
Guided item entry explains type, SKU/category, physical units, stock and roll
tracking; alternate-unit maintenance is separate. Definitions still create no
inventory. See [TEXTILE_MASTER_UX.md](TEXTILE_MASTER_UX.md).

## Guided Purchasing follow-up (2026-10-22)

Purchasing now has its own guided workspace alongside Processing. Suppliers belong under Purchasing; orders, physical receipts, returns, invoices and original payable inquiries are separate screens and business events.
See [PURCHASING_UX.md](PURCHASING_UX.md).


## Phase 8 guided workspace (2026-10-22)

Processing now exposes a sidebar category with setup, service types, routes, jobs, challans, receipts, stock with vendors, bills, payable sources and reports. The guide follows job approval through dispatch, receipt and bill without changing stock ownership or posting rules.
See [PROCESSING_UX.md](PROCESSING_UX.md).

> Phase 8 implementation (2026-10-21): configurable processing routes/jobs,
> contractor dispatch/custody, partial accepted/rejected returns, loss costing,
> roll lineage and exact-rate contractor bill/AP sources are implemented.
> See [PROCESSING.md](PROCESSING.md) for actual behavior and explicit boundaries.
> Production/sales/payments/tax remain future scope; MySQL 8.4 validation is pending.

Status: Phase 1 architecture approved; Phases 2-3 foundation, identity/company
setup and financial calendar are implemented. Phase 4 implements shared party
management, role profiles, contacts/addresses, vendor capabilities and rate
history. Phase 5 adds textile master definitions, fabric specifications,
collection/design/color and suit variants, plus validated item conversions.
Phases 6-8 implement warehouse/inventory posting, purchasing and contractor
processing. Production, packing, sales, payments and general accounting remain
requirements outside the explicitly implemented subsets.

## Business workflow

1. Purchasing selects a supplier role on the shared party, records a purchase
   order, receives measured grey/kora fabric into lots and optional identified
   rolls, and matches the supplier invoice to accepted receipts. Physical receipt,
   financial invoice and payment are separate events.
2. Warehouses tracks company-owned grey fabric by item, lot, roll and location.
   Quantity and inventory value are ledgers; balances are projections. Transfers
   may pass through transit and receiving/quarantine locations.
3. Processing plans a versioned route of configurable services and selects
   capable vendor roles. Each job step records its rate/unit snapshot, dispatches
   fabric on a challan, tracks the company's fabric at contractor locations, and
   accepts processed output, unprocessed returns, shrinkage and losses.
4. Production uses versioned suit/component requirements, consumes actual
   processed fabric and trims, records accepted output and wastage, and allocates
   direct labor and eligible overhead. Embroidery may be an external route step;
   cutting, assembly or other finishing may be production operations. The schema
   does not force one sequence.
5. FinishedGoods records product variants, actual good suits, packing materials,
   packages/cartons and their contents. Packaging is a costed transformation only
   when it consumes materials or changes stocked identity; grouping already
   costed suits into a carton must not manufacture extra stock.
6. Sales accepts wholesale orders, reserves stock, dispatches identified stock
   and packages, invoices quantities, processes physical returns and credit
   notes, and settles customer receipts.
7. Accounting records balanced journals, party open items, expenses, payments,
   receipts, tax snapshots, payroll liabilities, bank reconciliation and financial
   period closure. Receipts/dispatches and invoices reconcile without double
   recognition of stock or cost.

## Operating scope

One legal company, many warehouses and external contractors, PKR functional
currency, local business dates in Asia/Karachi and UTC event timestamps. Multiple
users receive explicit module permissions. One party may be supplier, processing
vendor and customer simultaneously; roles share legal identity but keep
commercial profiles and AP/AR purposes distinct.

Authentication access roles are separate from party roles. The shared-party
master is implemented. Supplier/vendor/customer party screens are exposed from
their Purchasing, Processing and Sales route groups. Phase 7 implements purchase orders, receipts/returns and basic supplier stock
bills/AP sources. Phase 8 implements configurable processing jobs, custody, returns/loss and
contractor bill sources. Manufacturing, sales invoices, payments/tax and general
financial workflows remain future scope.
Phase 6 implements inventory documents, quantity/value ledgers, warehouse/contractor
locations and balanced opening/variance journals.
The Laravel module registry, identity screens, company settings and financial
calendar are implemented.

## Outcomes

Trace fabric from supplier receipt to contractor custody, processing output,
manufacturing batch, finished suit and wholesale dispatch. Explain stock
quantities, contractor balances, per-suit cost, vendor bills, customer receivables
and each financial balance from immutable source postings.
