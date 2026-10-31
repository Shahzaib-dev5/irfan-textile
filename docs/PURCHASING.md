# Phase 7 — Purchasing

## Current simplified admin flow (2026-10-22)

The admin now chooses **Confirm** directly on a completed form. Save for later is
optional. Purchase order submission/approval is performed inside one operation
when the user has both permissions. Saved orders can also be confirmed once.
The existing transaction/stock/rate/custody rules and restricted-user permissions
remain. Every transaction has Download PDF and a clean Print document view.
See [SIMPLE_ADMIN_FLOW.md](SIMPLE_ADMIN_FLOW.md) for current instructions and the
completion report; earlier multi-screen draft instructions below are historical.

Current integration: Phase 8 Processing now uses the shared party, stock/FIFO,
calendar and source-journal foundations. See [PROCESSING.md](PROCESSING.md).
The phase-specific scope below remains the historical implementation contract.

Implemented scope: supplier purchase orders, approval, partial receipts/GRNs,
lot/roll creation, rejected-delivery evidence, physical supplier returns,
receipt-matched basic bills and payable sources, price/history inquiries and
printable purchase orders. All suppliers reuse `parties` → Supplier `party_roles`
→ `supplier_profiles`. There is no new supplier identity master.

## Guided Purchasing screens (2026-10-22)

Open **Purchasing > Overview / Start here**. Suppliers, Purchase orders, Goods
receipts, Purchase returns, Supplier bills, Payable sources and Prices & history
have separate sidebar entries. New documents can load their original order/GRN;
advanced preparation still supports multiple sources. Draft editing and approval
are preserved. See [PURCHASING_UX.md](PURCHASING_UX.md) for the guide, file/route
manifest, checks and completion report. No schema or posting-service change is
introduced by this interface follow-up.

## Using Purchasing

1. Apply pending additive migrations and run `php artisan db:seed` using PHP
   8.3+. Confirm the intended DB connection first. Configuration adds permissions,
   document types and posting-account mappings, never stock or login credentials.
2. Configure an active supplier through the existing Supplier screen, warehouse
   locations and stock items/units. An open financial year/period must cover the
   document date. Fabric is measured in meters unless an approved item-specific
   compatible order-unit conversion exists. Finished suit purchasing and service
   procurement are outside this raw-material purchasing slice.
3. Open **Purchasing → Purchase order**, select the shared supplier and ship-to
   warehouse, and enter item lines, quantities, unit prices, line discounts and
   expected delivery. Save, review and **Submit for approval**. An authorized
   approver may return it for editing or approve it. Approved orders get a number
   and freeze the commitment; approval changes no stock or financial balance.
4. Open **Goods receipt**. Select approved order lines for the same supplier and
   warehouse. Enter accepted/rejected quantities in the order unit, inspection
   notes, location, a new lot and each roll on its own line. Save and post the
   GRN. Accepted stock alone enters company ownership, inventory and GRNI.
   Pending/held stock remains subject to Inventory quality/picking controls.
5. Receive partially across multiple GRNs. The posted accepted quantity plus the
   current document cannot exceed the approved order. **Tolerance is zero**;
   there is no override or hidden percentage. Rejected quantities do not consume
   order capacity. An entirely rejected delivery is recorded on the approved
   order as inspection evidence, not as a zero-stock GRN.
6. Open **Supplier bill** and match accepted GRN lines in item base units. Basic
   bills require quantity/value matches at original receipt cost. Partial bills
   are allowed; matched/returned quantities cannot be billed again. Supplier
   reference is normalized by trimming, collapsing whitespace and uppercasing;
   duplicate checking uses the shared party identity, not just its role.
   Cancelled bill references remain reserved by the approved unique key; correct
   a draft through editing before cancellation when retaining that reference.
   Due dates must be on or after the supplier invoice date in this basic workflow.
   A posted bill clears GRNI and creates exactly one AP source, without another
   stock or valuation entry. Due dates and price/discount values are snapshots.
7. Open **Supplier return**, select the original receipt and current matching
   lot/roll position, enter base quantity and reasons, then post. Return capacity
   is limited by original accepted quantity and available unreserved stock.
   A tracked physical roll returns whole; splitting is a later physical workflow.
   Returns do not reopen PO quantity: replacements require a new approved order.
8. Cancel drafts with a reason. To cancel the unfulfilled part of an approved
   order, use **Cancel unfulfilled balance**. This records closure evidence and
   prevents further receipts while preserving accepted history and the original
   numbered order. It never turns a posted order into a mutable draft.
9. Use **Prices & history** for approved order and posted bill prices with their
   original unit basis. **Payable sources** shows original supplier obligations;
   it is not a payments/allocation or aging module. Use **Print purchase order**
   or its dedicated print URL; navigation and action controls are hidden in print.

## Authorization and workflow

| Permission | Purpose |
| --- | --- |
| purchasing.documents.view | Documents, history and payable-source inquiries |
| purchasing.documents.manage | Prepare, submit, edit and cancel drafts |
| purchasing.orders.approve | Approve, return to draft and close remaining orders |
| purchasing.receipts.post | Post GRNs and record fully rejected delivery evidence |
| purchasing.returns.post | Post physical supplier returns |
| purchasing.bills.post | Post exact-cost receipt-matched bills and AP sources |

Existing supplier-profile permissions remain separate. Role configuration
assigns these explicitly; reseeding extends super-admin only. Policies/Gates,
middleware, Form Requests and fresh service-level actor checks protect actions.
Preparing a document does not confer its posting/approval permission.

PO states use the existing document contract: draft → submitted → approved
(root `status=posted`), or draft → cancelled. Submitted/returned workflow is
derived from immutable audit events while root status remains draft. Version
checks guard edits, submission and approval. Returning a submission to draft
requires approval permission and a reason. There is no mandatory two-person rule;
assign preparer/approver permissions to different users if operations require it.
Closure is an immutable posted-order status/audit event, not payload mutation.

GRNs, returns and bills use draft → posted or draft → cancelled. Posted source
rows are immutable. Physical corrections use purchase returns; supplier commercial
credits and generalized posted-document reversal are not implemented by this
basic phase. Incorrect posted bills require the later credit/reversal workflow,
never editing or deleting the bill. Inventory routes/services are explicitly
scoped to inventory document types and cannot cancel/post/reverse purchase roots.

## Schema and migration impact

Additive forward-only migration:
`2026_10_21_000003_create_purchasing_tables.php`.

| Group | Tables |
| --- | --- |
| Commitments | purchase_orders, purchase_order_lines |
| Physical receipt | goods_receipts, goods_receipt_lines |
| Basic bills | purchase_invoices, purchase_invoice_lines, purchase_invoice_receipt_matches |
| Physical return | purchase_returns, purchase_return_lines |
| AP source dependency | party_open_items |

Ten new approved tables bring the application schema to **68 tables** excluding
migration bookkeeping. InnoDB, restrictive FKs, deterministic short FK names,
unique document/line and movement keys, row checks and exact decimals apply.
Quantities are DECIMAL(20,6), prices/factors DECIMAL(20,8), money DECIMAL(20,4).
Header keys extend `documents.id`; line movement FKs are filled atomically on
posting. `party_open_items.journal_line_id` is unique and original_amount is
immutable. No duplicate payable balance table, purchase-price table or stock
quantity column is introduced. Full approved columns/defaults/indexes remain in
database/COMMERCIAL_FINANCE.md F08 and F19–F29; the migration is the executable
subset. No existing production data or previous migrations were reset/rewritten.

The approved nullable PO `tax_rate_id` is temporarily constrained to NULL without
an FK because tax configuration is not implemented. Likewise the future invoice
processing-step field is NULL-only, and invoice lines are currently stock-only;
expense/cost-centre dimensions are unused. Bill tax and rounding are zero-only.
Later authorized tax/processing migrations must replace these named subset
checks and add the real prerequisite FKs. No tax/processing tables were invented
to satisfy unused dimensions. These stronger checks affect only new tables and
prevent creating unsupported financial facts.

Configuration adds PO/GRN/PRTN/BILL document types and account purposes GRNI,
supplier_payable and supplier_return_clearing, using first-create semantics:
2100 GRNI (liability), 2110 Supplier payable (party payable control),
1190 Supplier return clearing (asset). Existing inventory control is reused.
Confirm real mappings with the accountant before live use. No legal/tax compliance
or statutory tax rate is assumed. Existing mappings survive reseeding.

## Posting and accounting integration contracts

Every write locks the company singleton first, then roots/source lines and stock
resources within one transaction. This shares Phase 6's conservative serialization
with Inventory, Catalog and calendar changes. Approval/receipt/matching caps are
rechecked after locking, rather than trusting the draft. Root creation UUID,
posting UUID, payload hash, version, locked sequence and audit events implement
safe retries. A repeated committed posting with its original key returns the
existing result; a different payload/key is rejected. Cancellation allocates no
official number. Fiscal sequences also initialize on new year creation.

Unit conversion factors are frozen on PO lines. Receipts use those snapshots,
even if master factors later change. A master factor with more than eight
significant fractional places is rejected when it cannot fit the approved
document snapshot. Base-unit precision is validated without binary floats.
Receipt provisional unit cost is the rounded net order cost / ordered base
quantity, snapshotted to eight places; receipt valuation rounds to four PKR
decimals. Existing `StockLedger` creates receipt/issue movements, FIFO layers,
allocations, values and cost links. Lot origin is the GRN; a reused posted lot
or roll cannot conceal a second receipt. Used PO item identity is also frozen
by Catalog, even before first receipt.

`Accounting/Services/SourceJournal` is the shared accounting boundary used by
Inventory and Purchasing. It accepts signed per-account PKR amounts inside the
source posting transaction, validates balanced totals/active accounts and creates
one journal keyed by the original document. Party-control lines require party,
role and due date and create one immutable open item per line. It creates no
second financial document. Its unique journal/open-item keys and the root posting
key prevent duplicate financial records.

| Source | Stock | Journal |
| --- | --- | --- |
| Approved PO | None | None |
| Accepted GRN | Increase stock/value once | Dr Inventory, Cr GRNI |
| Exact-cost matched bill | None | Dr GRNI, Cr supplier AP plus AP source |
| Return on a wholly unbilled receipt | Issue actual FIFO quantity/value | Dr GRNI, Cr Inventory |
| Return once receipt has any billed quantity | Issue actual FIFO quantity/value | Dr Supplier return clearing, Cr Inventory; AP unchanged |
| Entirely rejected delivery | None | None; inspection audit only |

Bill capacity is accepted − posted returned − posted matched quantity, locked and
rechecked at posting. Partial GRNI relief uses original receipt cost; the final
eligible quantity takes the original provisional-value residual, less already
relieved amounts. A bill that differs is rejected atomically: no partial journal,
AP source or extra stock. For complex interleaving of partially billed returns,
clearing-account amounts may require later credit/cost reconciliation; the basic
matcher rejects unresolved differences instead of inventing a balancing entry.
Original payable amounts remain gross; there is no silent supplier AP/AR offset.

## Files, routes and checks

Created purchasing models corresponding to the ten tables, PurchasingService,
PurchaseDocuments lifecycle service, SourceJournal, PurchasingPolicy, controller,
two Form Requests, module routes, six Blade views/partials, configuration seeder,
PurchasingTest, PurchasingConcurrencyTest, PurchasingFixtures and shared
DatabaseRace worker helper. Updated Inventory posting to use SourceJournal and
enforce module boundaries; Catalog guards, calendar sequences, navigation,
printing CSS, provider Gates, permissions and seeder registration were extended.

Ten new route definitions: document list/create/store/show/edit/update/print,
lifecycle POST action, history and payable-source inquiry. They are under
`/purchasing/documents`, `/purchasing/history`, `/purchasing/payable-sources`.
Existing shared Supplier routes stay under `/purchasing/suppliers`.
Lifecycle actions are submit, post, cancel, return_to_draft, close and
reject_delivery. No operational delete routes exist.

Tests cover full/partial receipt, approval and closure, accepted/rejected goods,
supplier roles, conversion snapshots, duplicate posting/reference, match caps,
returns and stock/AP effects, permissions, HTTP forms and print/history pages.
Independent synchronized PHP/PDO workers exercise concurrent receipts and bill
matching capacity. Only the hard-guarded isolated `irfan_textile_phase3_test`
database is recreated. Exact final checks are in PROJECT_STATE.md.

## Review checklist and limits

- [x] One shared supplier identity and role/profile validation.
- [x] Approved immutable orders and explicit cancellation of remaining balance.
- [x] Partial receipts and rejected quantities; zero over-receipt tolerance.
- [x] Once-only GRN inventory/GRNI and source lot/roll identity.
- [x] Bounded physical returns with available-stock checks; AP not silently reduced.
- [x] Exact-cost bill matching and one payable source without duplicate stock.
- [x] Historical prices, printable PO, permissions and tests.
- [x] Independent-process receipt and matching races tested.
- [ ] Execute migrations/tests on required MySQL 8.4 before production.
- [ ] Accountant acceptance of mappings and live opening/procurement balances.
- [ ] Browser/device acceptance of dynamic multi-line forms and printing.

Local validation uses PHP 8.3.35, Laravel 13.38.0 and MariaDB 10.4.32. No MySQL
8.4 or interactive-browser result is claimed. No new packages are required.
Out of scope: PO-less receipts, tolerance overrides, order amendments after
approval, finished-goods purchasing, tax/withholding, service/processing/expense
bills, price variance/late-cost propagation, supplier credit notes, settlements,
payments and full AP aging. Posted purchasing reversals remain unimplemented;
posted documents are protected from generic Inventory reversal endpoints.
Stop after Phase 7; processing jobs and later phases are not authorized.

## Final executed checks

`php artisan test --compact`: **78 passed, 513 assertions**, including 16 new
functional tests, two new independent purchasing races and all prior inventory,
identity, party and Catalog tests. Pint, Composer strict validation/platform
checks, Vite production build, Blade cache and route cache/cached listing/clear
passed. Documentation UTF-8/fence/link scans passed. Caches were cleared after
compilation checks. 85 application routes excluding vendor routes.

PHP 8.3.35 / Laravel 13.38.0 / local MariaDB 10.4.32. Writes were restricted to
the isolated test schema; no application migration/reset or .env change.
MySQL 8.4 and interactive browser/device acceptance remain outstanding.
