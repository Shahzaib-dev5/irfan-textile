# Phase 11 - Wholesale Sales and Customers

## Physical Suit Stock follow-up (2026-10-28)

The authorized [Physical Suit Stock workflow](PHYSICAL_SUIT_STOCK.md) simplifies ready-suit
entry, adds processed-fabric inventory and dated customer deliveries/debit-credit
history. Existing stock/FIFO/journals remain authoritative; no migration or duplicate
balances. Production and packing remain under Advanced Manufacturing. The guide
records files, routes, ledger effects, deployment and limits; see TESTING.md for
verification results.

## Phase 12 payment integration (2026-10-26)

Customer receipts and allocations are now available in Accounting & finance to
separately authorized users. They reduce AR and existing credit exposure without
reposting sales revenue or stock. Wholesale receives no Finance grants. Older
payment-deferred notes below describe the Phase 11 release only.
See [ACCOUNTING_FINANCE.md](ACCOUNTING_FINANCE.md).


Implemented and locally activated, 2026-10-26. Ready for code review and operator approval; stop after Phase 11.
The explicit request supersedes Phase 10-only stop/scope text in older reports.

## Operator flow and prerequisites

Use **Wholesale sales > Overview / Start here**. The normal sequence is:

1. Administrator: configure the company, open year/period, sales posting accounts,
   active customer role/profile, payment terms and an approved credit limit. Zero
   credit limit means no credit; an active credit hold blocks new commitments,
   reservations and dispatch. Keep existing customer identity in shared Parties.
2. Administrator: receive real finished SUIT variants or PACK stock through
   Production/Packing and inspect/release it at internal warehouse locations.
   Wholesale can view quantities without access to manufacturing costs.
3. Sales orders: choose the customer, search SKU/name, choose a compatible sales
   unit, enter quantity, unit price, total line discount and delivery address.
   Confirm once, or Save for later to review an unfinished draft. An order may
   exceed today's stock as a backorder, but dispatch/reservations may not.
4. Open the confirmed order. Optionally reserve specific real lots for its lines.
   Split picks across lots on separate dispatch lines. Reserved stock cannot be
   used by another order. Expiry requires an explicit release; elapsed wall time
   does not silently change stock availability. Release wrong-lot holds before
   dispatching another lot if they exceed the remaining order balance.
5. Dispatch notes: select the same customer/warehouse, confirmed order line,
   released internal stock lot and actual whole SUIT/PACK quantity. Confirm a
   partial or complete delivery. Dispatch posts stock, FIFO COGS and unbilled
   revenue in one transaction. Negative stock is never allowed.
6. Sales invoices: select the customer's confirmed dispatch lines and quantities.
   Frozen prices/discounts determine the amount; it cannot be freely repriced.
   Save for later for invoice preview, then confirm. Invoice posts billed AR and
   clears unbilled AR; it never issues stock or recognizes sales revenue again.
7. Download A4 PDFs / print orders, dispatch, invoices, physical returns and credits.
   Open Customer history from a document for all sales records and credit exposure.
8. Returns: choose the original dispatch, measured return quantity, new return lot,
   warehouse and receiving location. Inspected saleable goods enter internal
   storage; damaged or uninspected goods enter quarantine and remain held. This
   restores actual original issue cost and stock only, not revenue/customer AR.
9. Credit notes: select the original invoice and match. Physical-return credits
   require a matching posted return and bounded whole quantity/value; price
   adjustments have quantity zero and a documented reason. No stock is received
   again. A return of unbilled goods can be physically received first; invoice the
   original dispatch and then issue its commercial credit. Returns do not reopen
   the original order; create a replacement order if required.
10. To cancel a backorder, close the confirmed order's unfulfilled balance with a
    reason. This releases outstanding reservations, retains delivery/invoice
    history and prevents more dispatch. Draft correction cancels/recreates it.
    Posted corrections use physical return and/or credit, never editing/deletion.

Tax computation, collections, cash deposits/refunds and settlements are outside
this phase. Sales PDFs explicitly state that tax and payments are not implemented.
No standalone fully-free invoice: free goods must accompany a positive matched
invoice total. Quotations are not required by approved F33-F45 design.

## Data architecture and migration impact

Migration: database/migrations/2026_10_26_000002_create_wholesale_sales_tables.php.
Eleven approved operational tables (F33-F39, F41-F44), taking application tables
94 -> 105; Laravel migration tracking is an additional table. Tax children F40/F45
are deferred with tax configuration. Existing inventory/financial tables are reused.
All new FKs restrict updates/deletes; DECIMAL quantities (20,6), prices/factors
(20,8), money (20,4); InnoDB. Ancillary created_at/updated_at use Laravel's
nullable TIMESTAMP(6) defaults, populated by Eloquent, rather than Phase 1's
proposed non-null DATETIME(6) metadata convention. This affects only the eleven
new tables; existing document/audit/stock history metadata is unchanged. Explicit
business dates (dispatched_at/received_at) are DATETIME(6) NOT NULL. Tightening
ancillary metadata later requires a reviewed forward migration, not editing an
already-applied migration. Header document_id PK/FK documents.id;
line id PK, document_id FK and UNIQUE(document_id,line_no). Full approved column
specifications: [Commercial Finance](database/COMMERCIAL_FINANCE.md#sales).

| Table | Implemented fields / additional constraints |
|---|---|
| sales_orders | Customer profile role FK, optional terms/address/reference/required date, immutable address snapshot, notes. Index customer/required_on. |
| sales_order_lines | Item/unit FKs, description, quantity/base quantity/factor/price/discount, optional required_on. tax_rate_id is NULL-only until tax phase (same prior Purchasing pattern). Positive quantities/factor, nonnegative price/discount CHECK. Item/document index; extra UNIQUE(id,document_id) supports reservation parent integrity. |
| sales_dispatches | Customer-profile/warehouse FKs, dispatched_at, optional carrier/tracking/notes, address snapshot. Customer/date index. |
| sales_dispatch_lines | Order-line/stock-position/revenue-account FKs, base quantity, frozen net revenue, nullable UNIQUE stock movement FK. Optional package FK retained but prohibited by this UI/service until serial shipment allocation exists. Positive quantity/nonnegative revenue CHECK; order/document index. |
| sales_invoices | Customer-profile/optional terms FKs, due_on, billing snapshot, optional reference/notes, net/tax/rounding/gross. Positive net, tax=rounding=0 and gross=net CHECK. Customer/due index. |
| sales_invoice_lines | Order-line/item/unit/optional cost-centre FKs, description, positive quantities/factor, price/discount/net/tax/gross. Nonnegative amounts, zero tax and gross=net CHECK. Base-unit invoice representation of frozen dispatch quantities. |
| sales_invoice_dispatch_matches | Invoice-line/dispatch-line FKs, matched_base_quantity, frozen net_amount, UNIQUE(invoice_line,dispatch_line), positive quantity/nonnegative amount CHECK. Each current UI line represents one match; multiple dispatches can feed one invoice through separate lines. |
| sales_returns | Customer-profile/warehouse FKs, received_at and reason. Customer/date index. |
| sales_return_lines | Dispatch-line/new stock-position FKs, quantity, condition enum saleable/quarantine/damaged, nullable UNIQUE movement FK, reason. Positive quantity CHECK; dispatch/document index. |
| sales_credit_notes | Original invoice FK, physical_return/price_adjustment kind, reason, net/tax/rounding/gross. Positive net, zero tax/rounding, gross=net CHECK. |
| sales_credit_note_lines | Invoice-line/match/optional return-line FKs, credited_base_quantity, net/tax/gross, description. Nonnegative quantity, positive net, zero tax and gross=net CHECK. |

Additive refinement: stock_reservations.sales_order_line_id nullable indexed FK
plus a composite FK (sales_order_line_id,document_id) to sales_order_lines
(id,document_id). Existing transfer rows remain NULL and unchanged. This makes
approved order-line reservation ownership enforceable, instead of using an
untyped sequence as a commercial line identity. No old ledger/history reset.
Rollback would remove new tables and this nullable column; it is not a safe way
to remove posted commercial history and is never run on company data here.

## Transaction, stock and accounting rules

- Existing company-row mutex plus customer role/profile, source document and
  stock/FIFO locks serialize posting, commitments, matching and reservations.
  This coarse single-company lock favors correctness; future higher throughput
  needs measured lock partitioning, not removal of invariants.
- Creation UUID plus validated-input hash rejects changed retries. Browser/API
  reservation commands require their own UUID and replay once from an audited
  command hash; internal legacy callers without a key remain cap-checked. Posting UUID,
  immutable payload hash, unique source movement/match facts and one journal per
  source prevent duplicate stock/financial records. Confirm saves/posts atomically.
- StockLedger remains the only physical/FIFO primitive. SalesReservations is an
  Inventory boundary, updates reservation projections and audits reserve/fulfill/
  release. Ledger movements remain authoritative. No editable Sales stock field.
- Credit exposure = signed posted AR + party-specific unbilled AR + net value of
  approved unfulfilled order commitments. Dispatch replaces its commitment with
  unbilled AR, invoice reclassifies it, and credit reduces AR. Both posted/reversed
  original facts and their signed reversals count. No double-counted exposure.
  No permission-based credit/negative-stock exception is silently enabled.
- Customer credit edits use a separate sales.credit.manage permission. Existing
  Party changes now acquire the same company mutex, preventing stale concurrent
  credit settings. Customer profiles are explicitly locked for decisions.
- Dispatch: Dr COGS / Cr inventory at actual issued FIFO value; Dr unbilled AR /
  Cr wholesale revenue at frozen order net. Both pairs belong to this one source.
- Invoice: Dr customer AR / Cr unbilled AR for exactly matched dispatch net.
  SourceJournal creates the one AR open item. Unbilled clearing is non-control,
  retains customer dimensions and is matched through immutable dispatch matches.
- Return: Dr inventory / Cr COGS at original actual dispatched cost; new lot cost
  links point to original negative FIFO allocations. Allocation uses cumulative
  returned quantity / original dispatch quantity against each original cost
  component, with final residual rounding; no current selling/master rate is used.
  Original dispatch cost is proportionally blended when it used several FIFO
  acquisitions. This allocation rule is explicit because individual suits within
  a homogeneous lot are not serialized. Later-cost adjustment editing is outside
  the current application; no unsupported correction is invented.
- Credit: Dr sales returns/allowances / Cr customer AR, one credit open item; no
  stock change. Caps include every prior effective credit under the same lock.
  Physical quantity must fit both invoice match and actual return; net must fit
  remaining match value and proportional original price. Price adjustments use
  zero quantity. Settlements/refunds are deferred, never fake payment documents.
- Discount and dispatch/invoice shares use BCMath cumulative prorating to four
  decimals, carrying the residual on final quantity. Original order unit factor,
  price and discount are frozen. Base-unit invoice price represents the frozen
  dispatch amount with an explicit residual discount if conversion repeats.
- Ordinary wholesale sells whole active SUIT variants or separate PACK SKUs.
  PACK consumption already occurred during packing; selling PACK never consumes
  its constituent SUIT stock again. Meters and suits/packs never interconvert.
- Return lots, held/damaged quality, expiry and reservations remain visible to
  inventory/quality modules. Quarantined stock cannot be selected for dispatch.
- Closing order balance is an audited event, not changing posted payload/status.
  It frees future commitments but does not erase already-dispatched receivables.

These recognition/credit/return-cost rules follow the approved architecture and
remain subject to accountant/operator acceptance before commercial deployment.

## Phase completion report

### 1. Features implemented

Shared customer selection/terms, searchable finished items and compatible units,
orders/backorders, credit controls, line-bound lot reservations, partial dispatch,
matched partial invoices, physical returns, commercial credits, customer history,
availability, guided separate Blade screens, immutable source journals and A4 PDFs.
No second customer master, stock ledger, AR balance table or finance document.

### 2. Files created and modified

New: eleven app/Models/Sales*.php models;
app/Modules/Sales/Services/{SalesService,SalesWorkspace,SalesDocument}.php;
app/Modules/Sales/Http/Controllers/{SalesController,SalesDocumentController}.php;
app/Modules/Sales/Http/Requests/{SalesDocumentRequest,SalesActionRequest}.php;
app/Modules/Inventory/Services/SalesReservations.php; app/Policies/SalesPolicy.php;
the additive migration; database/seeders/{SalesConfigurationSeeder,SalesSetupSeeder}.php;
resources/js/sales.js; resources/views/components/erp/sales-navigation.blade.php;
app/Modules/Sales/Resources/views/{nav,overview,index,form,show,availability,history}.blade.php;
tests/Support/SalesFixtures.php; tests/Feature/{SalesTest,SalesConcurrencyTest,
SalesWorkspaceTest,SalesSetupTest}.php; tests/Frontend/{sales.test,sales-browser}.mjs;
this guide.

Updated: Sales/Routes/web.php, AppServiceProvider, erp_permissions.php,
Identity LoginController (Wholesale default landing), permission-filtered Core dashboard,
DatabaseSeeder, StockReservation relationships, inventory_worker.php, app.js,
Core layout/sidebar, TransactionDocument logo projection visibility, Catalog
service/workspace reference protection, Parties save/credit form, AGENTS, README
and current architecture/database/business/deployment/testing/state documents.
Generated Vite assets are rebuilt; private fixtures/backups are not deliverable
operational data. No dependency change or Git commit in this non-Git workspace.

### 3. Tables and migrations

Eleven new tables and nullable reservation FK/composite parent constraint listed
above. Migration order follows Phase 10. No tax/payment/settlement CRUD or tables.
Configuration only: five sales document types and five typed posting accounts.

### 4. Routes, screens and authorization

Routes under /sales, auth + active.user + sales.documents.view:
GET /, /documents, /documents/create, /items, /availability,
/customer-history/{customer}, /documents/{document},
/documents/{document}/{format:pdf|print}; POST /documents,
/documents/{document}/{action:post|cancel|close|reserve|release}.
Existing /sales/customers remains owned by Parties.

Permissions: sales.documents.view/manage/confirm, sales.stock.reserve,
sales.credit.manage plus existing customer view/manage. Policy and middleware,
Form Request and service checks enforce backend access, including cross-module
IDs. Scoped setup grants Sales definitions to the existing admin and creates a
Wholesale role with six Sales-only permissions, excluding credit administration.
It never creates/assigns a user or replaces other roles' grants. Assign the role
to the real Wholesale user through existing User Management as an administrator.
The union of deliberately assigned roles remains the normal authorization model.
Sales-only users default to the Sales overview after login. The generic dashboard
also links only their authorized active workspaces, replacing obsolete Phase 3
placeholder claims. An explicitly intended protected URL still enforces its policy.

### 5. Tests and checks

Full regression: **220 passed / 1,585 assertions**, including 20 independent races.
Final Sales/foundation/identity suite: **44 passed / 219 assertions**, including
all 24 Sales cases and final login/dashboard, frozen-account and reservation-retry
safeguards. Runs overlap; 222 distinct cases verified. Node 32, Vite 69 modules,
whole-repository Pint, 153 compiled Blade/PHP checks and route caching passed.
Five Sales A4 PDFs independently rendered/visually reviewed. Local migration ran
as batch 9 after private backup; nine actual local pages returned 200 and previous
operational fingerprints/role grants were preserved. No operational data seeded.
All six module browser harnesses passed desktop/mobile, including nine Sales
screens. Full browser/activation evidence: [TESTING.md](TESTING.md).

### 6. Known limits

Exact MySQL 8.4, real operator/accountant acceptance and backup restore rehearsal
remain distinct from local MariaDB tests. Tax, collection/refund/settlement and
advance-aware credit offsets are deferred. No quotations are required by design.
No negative-stock/credit override, draft editing, standalone zero-total invoice,
nested/serialized package shipment allocation or automatic posted-document void.
Corrections use returns/credits and order closure. Allocation to payments/credits
is not fabricated. Current UI uses one dispatch match per invoice line, several
lines per invoice. Stock/source pick lists are local-company inquiries; item
lookup limits search results to 30. Current cost labels may change with master
names but immutable money/quantity snapshots remain authoritative.

### 7. Documentation

README, AGENTS, PROJECT_OVERVIEW, PRD, ARCHITECTURE, DATABASE_SCHEMA, ERD,
DATABASE_DECISIONS, DATABASE_REVIEW, BUSINESS_RULES, MIGRATION_PLAN,
DEVELOPMENT_PHASES, PROJECT_STATE, CHANGELOG, TESTING, DEPLOYMENT and affected
module guides document this implemented slice; older phase entries are historical.

### 8. Suggested Git commit

feat(sales): add wholesale orders dispatch invoicing returns and credit controls

### 9. Readiness checklist

- [x] Shared Customer profile, no separate identity master.
- [x] Real documents, lines, FKs, decimal/check constraints and permission boundaries.
- [x] Stock/FIFO and SourceJournal reused, no duplicate finance/stock posting.
- [x] Explicit backorder, credit, expiry, cancellation and return-cost rules.
- [x] Separate guided screens, searchable items, invoice preview and A4 documents.
- [x] Passing full/final relevant regression and backed-up local activation; see PROJECT_STATE/TESTING.
- [ ] Target MySQL 8.4 and operator/accountant commercial acceptance.

Stop after Phase 11.
