# Guided Purchasing workspace

## Current simplified admin flow (2026-10-22)

The admin now chooses **Confirm** directly on a completed form. Save for later is
optional. Purchase order submission/approval is performed inside one operation
when the user has both permissions. Saved orders can also be confirmed once.
The existing transaction/stock/rate/custody rules and restricted-user permissions
remain. Every transaction has Download PDF and a clean Print document view.
See [SIMPLE_ADMIN_FLOW.md](SIMPLE_ADMIN_FLOW.md) for current instructions and the
completion report; earlier multi-screen draft instructions below are historical.

Date: 2026-10-22 (Asia/Karachi). This is the user-authorized interface follow-up
for the existing Purchasing module, within the current Phase 8 work boundary.
It adds no later business phase or database schema. The rules in
[PURCHASING.md](PURCHASING.md) remain authoritative.

## Where each task belongs

Open **Purchasing > Overview / Start here**. The sidebar category expands on
Purchasing and shared Supplier pages. Processing keeps its own separate category.

| Sidebar screen | Purpose | Next step |
|---|---|---|
| Overview / Start here | Prerequisites and an explanation of the buying workflow | Configure missing setup, then create an order |
| Suppliers | Shared party Supplier roles, profiles, contacts and payment terms | Create the purchase order |
| Purchase orders | Prepare, edit, submit, approve and print commitments | Receive goods against the approved order |
| Goods receipts | Record accepted/rejected delivery and new lots/rolls | Inspect stock and match the supplier bill |
| Purchase returns | Return available original lot/roll stock from its current warehouse | Supplier credit is a later workflow; replacements require a new order |
| Supplier bills | Match the invoice to accepted, unreturned, unbilled GRN quantities | Review original payable sources |
| Payable sources | View original posted supplier obligations | Payment/settlement entry remains future scope |
| Prices & history | Filter approved order and posted bill prices by named item/supplier | Review the relevant source document |

Suppliers remain in the one shared party master. The Supplier submenu appears
only with `purchasing.supplier.view`; Purchasing documents/inquiries require
`purchasing.documents.view`. Document preparation/editing additionally requires
`.manage`; posting/approval retains its existing separate permission. Hidden
navigation is backed by policies, middleware, Form Requests and service checks.

## First-time setup

1. Complete company settings and configure an open financial year/period covering
   the document date. Future accounting dates are not supported.
2. Assign the Supplier role to an existing party or create that party once. Add
   its profile, contact details and payment terms; do not duplicate identity.
3. Create actual stock-managed Textile Items and active units. Fabric normally
   uses M. An item category groups SKUs; it cannot be received as stock itself.
4. Create active warehouses and internal/quarantine receiving locations. A
   location must belong to the receiving warehouse. A warehouse with no active
   receiving location cannot prepare a new GRN using the guided screen.

## Daily flow

**Purchase order -> approved order -> GRN -> supplier bill -> payable source.**
Physical returns branch from a posted GRN.

### Purchase orders

Open **Purchase orders > New purchase order**. Choose supplier, ship-to warehouse,
item, compatible order unit, quantity and PKR price/discount. Save the draft and
review it. Drafts can be edited. Submit for approval; an approver can return it
to draft or approve it. Approval assigns the order number, freezes its details and
creates no stock or payable. The posted order provides **Receive goods against
this order** while an open quantity remains. Closing the unfulfilled balance and
recording an entirely rejected delivery remain available on the order details.

### Goods receipts

From the approved order choose **Receive goods against this order**, or open
**Goods receipts > Receive purchased goods**, type a purchase order number or
supplier name and select the matching approved order. Its context loads at once.
Supplier/warehouse are inferred, order lines show remaining
quantity in the original order unit, and locations are limited to that warehouse.
Closed/fully accepted orders show an explanatory empty state.

Enter accepted and rejected quantities, inspection notes, receiving location,
new lot number and each physical roll on its own line. The original order unit
and conversion snapshot apply. Save, review, then post the GRN. Only accepted
stock enters the quantity/value ledger and GRNI. Pending quality is the default;
release inspected stock in Inventory before picking/Processing. Rejected goods
remain supplier-owned and do not consume accepted order capacity.

If goods arrive from a purchase made without an order, choose **Receive without
order**. Select the supplier and warehouse, then enter each item, compatible
unit, accepted/rejected quantity, actual supplier cost per base unit, lot/roll
and receiving location. A reason for bypassing the order is required and saved
to the document audit history. Posting uses the same stock and GRNI entries as
an order-backed receipt. Create the supplier bill from the posted GRN as usual;
it must match the recorded receipt cost exactly. Returns and receipt statements
continue to use the same GRN lines. Direct receipts do not create an order or
order approval record.

For example, an order for 100 M can receive 60 M now and 40 M on another GRN.
Zero over-receipt tolerance remains. The server rechecks cumulative accepted
quantities inside the transaction; draft-screen remaining quantities are guidance.

### Supplier bills

From a posted GRN choose **Create supplier bill**, or open **Supplier bills >
New supplier bill**, type the GRN number or supplier name and select the match.
Its supplier and billable receipt lines load immediately. Supplier is inferred and
only positive unbilled, unreturned receipt capacity is offered. The original
receipt cost is suggested and displayed per base unit. Bill quantities use the
item base unit, even when the order used a converted purchasing unit.

Enter the real supplier invoice reference/date and due date, quantity and exact
receipt-matched price/discount. Review, then post. Posting clears GRNI and creates
one original payable source without another stock receipt. This basic workflow
still excludes tax and price-variance/credit/payment workflows.

Example: receive 60 M, already bill 20 M and physically return 10 M. The remaining
billable quantity is **60 - 20 - 10 = 30 M**. Fully billed/returned sources show
no eligible new bill line. Drafts do not reserve bill capacity; posting rechecks it.

**An unpaid supplier bill appears under Purchasing > Payable sources.** These
are original obligations, not live settlement/aging balances. The bill link now
uses the bill's document ID; journal IDs are not interchangeable with document IDs.

### Purchase returns

From a posted GRN choose **Return goods from this receipt**. Select the original
receipt line and matching available lot/roll, enter base quantity and reasons,
review and post. The screen derives availability from stock movement and
reservation history. Reserved or unavailable stock is not offered.

If the original stock moved to another warehouse, expand **Choose current
warehouse / multiple receipts**, keep the GRN selected and load the warehouse
currently holding its original lot/roll. The receipt's original warehouse is the
default, not a restriction on returning transferred original stock.

A physical roll returns whole; use **Use full roll quantity** when applicable.
Pre-return roll splitting is not implemented. A physical return does not reduce
an existing supplier payable, reopen PO capacity or serve as a payment. Supplier
credit notes/settlements remain future workflows.

### Multiple sources and editing

The source chooser's advanced option preserves multi-order and multi-GRN
preparation. Leave its order/GRN dropdown blank and choose one supplier and,
where required, one warehouse. Load that context to combine its eligible sources.
A single invoice may still match several GRNs for the same supplier.

Existing editable drafts retain all their source references, including references
whose capacity was consumed by another posting. Editing offers source choices as
before, so supplier/warehouse changes remain possible. Ensure all selected lines
match those headers. Backend validation rejects invalid combinations and stale
quantities. A submitted PO must first be returned to draft; posted records are
immutable. Correct a supplier bill reference through draft editing before
cancellation when retaining it: cancelled references remain reserved.

## Implementation and completion report

### 1. Features delivered

- Permission-aware Purchasing sidebar category and separate workflow screens.
- Overview/prerequisite instructions, source/details/review guidance and next links.
- Typed list/create/review/edit/save URLs with fixed document kinds and preserved
  legacy routes, draft editing, approval, rejection evidence and cancellation.
- Read-only `PurchasingWorkspace` selects source context, remaining order/bill/
  return capacity and original available lot/roll stock. Server posting services
  and quantity/financial ledgers remain unchanged.
- Stable Alpine row keys, original receipt-rate suggestions and matching return
  stock choices. Names replace raw IDs in history filters. Supplier payable links
  correctly open the bill document.

### 2. Files created and modified

Created:

- `app/Modules/Purchasing/Services/PurchasingWorkspace.php`
- `app/Modules/Purchasing/Resources/views/overview.blade.php`
- `app/Modules/Purchasing/Resources/views/source.blade.php`
- `resources/views/components/erp/purchasing-navigation.blade.php`
- `resources/js/purchasing.js`
- `tests/Feature/PurchasingWorkspaceTest.php`
- `tests/Frontend/purchasing.test.mjs`
- `tests/Frontend/purchasing-browser.mjs`
- `docs/PURCHASING_UX.md`

Modified:

- `app/Modules/Core/Resources/views/layouts/app.blade.php`
- `app/Modules/Purchasing/Http/Controllers/PurchasingController.php`
- `app/Modules/Purchasing/Http/Requests/SavePurchaseRequest.php`
- `app/Modules/Purchasing/Routes/web.php`
- `app/Modules/Purchasing/Resources/views/{nav,index,form,show,history,payables}.blade.php`
- `resources/js/app.js`
- AGENTS, README, PURCHASING, PROJECT_STATE, PRD, PROJECT_OVERVIEW, ARCHITECTURE,
  BUSINESS_RULES, DEVELOPMENT_PHASES, CHANGELOG, TESTING and DEPLOYMENT documentation.

No PurchasingService, PurchaseDocuments, policy, posting or accounting service
was changed. Generated Vite files are ignored build outputs.

### 3. Tables and migrations

**None.** The existing 78 application tables, constraints, permission/configuration
seeders and dependencies remain. No migration/reset, application business-data
seeding, environment edit, new credential or password change was performed.

### 4. Routes and screens

There are **41 routes under Purchasing**, including six shared Supplier routes,
ten preserved document/inquiry routes and 25 new workspace routes.

Twenty-five new routes: GET `/purchasing` and six for each of `orders`, `receipts`,
`returns`, `bills`: GET list, GET `/create`, POST save, GET `/{document}`, GET
`/{document}/edit` and PUT `/{document}`. Typed lists/stores cannot be switched to
another document kind through a query/body. Cross-kind detail/edit/update requests
are rejected after authorization. Existing generic, action, print, history,
payable-source and shared supplier URLs are preserved.

Navigation and preparation use the existing permission split. In particular,
a preparer cannot gain posting/approval permission from the new screens, and a
supplier-only administrator sees supplier links without document access.

### 5. Checks and results

Final full regression: **131 tests / 836 assertions**, including all nine race
tests. Node: **13 passed** (five Purchasing, eight Processing). Final Purchasing
and Processing desktop/mobile Edge browser smoke, Pint, Vite build (65 modules),
route cache/list/clear and PHP lint of **119** compiled Blade templates passed.
Relative documentation links passed.

Actual final results and reproducible commands are recorded in
[TESTING.md](TESTING.md) and [PROJECT_STATE.md](PROJECT_STATE.md).
Coverage includes full/partial buying, posting idempotency, unchanged approval and
editing, scoped source/stock choices, remaining capacity, advanced multi-source
preparation, named history filters, access denial and correct payable deep links.

### 6. Known limits

Exact MySQL 8.4 and live operator/accounting/printing acceptance remain outstanding;
local database validation uses MariaDB 10.4.32. Existing exact-cost bills, zero
receipt tolerance, original-lot/whole-roll returns, reserved invoice references,
no posted commercial correction/credit/payment workflow and no roll split remain.
Source choices are a current snapshot; all write decisions remain server validated.
Screen separation does not authorize another business phase.

### 7. Updated documentation

The guide and project/reference documents named above reflect current navigation,
prerequisites, preserved editing/multi-source behavior, posting limits and checks.
Schema/ERD specifications remain unchanged because this follow-up adds no tables.

### 8. Suggested Git commit

`feat: add guided purchasing screens and sidebar navigation`

### 9. Readiness

Ready for review and approval after the recorded checks, subject to deployment
acceptance limits. Stop after this authorized UX follow-up; no later module starts.

## Review checklist

- [x] Separate, permission-aware supplier/order/GRN/return/bill/inquiry navigation.
- [x] Contextual guidance, remaining capacity and direct next actions.
- [x] Draft editing, submission/approval and multi-source preparation preserved.
- [x] Stock/custody, double-entry journals and posting services unchanged.
- [x] No migrations, configuration permissions, credentials or future module added.
- [x] Full regression, frontend, browser, build, format/cache and documentation checks.
- [ ] MySQL 8.4 and live operator/accounting/print acceptance.

## Direct purchase receipt without a PO follow-up (2026-11-05)

The Goods receipts list now offers **Receive without order**. This creates a
normal editable Goods Receipt draft whose lines have no purchase-order-line FK.
Supplier, warehouse, stocked item, compatible unit, quantities, actual base-unit
cost, lot/roll, receiving location and the reason are captured. The reason is
appended as an immutable `purchase_direct_receipt_reason` audit event whenever
the draft is saved. At posting, the existing inventory/GRNI journal and stock
movement path are reused. Supplier bills still match accepted receipt quantities
and exact recorded cost; returns still use the original receipt and position.

Files: `PurchasingWorkspace`, `PurchasingService`, `PurchasingController`,
`SavePurchaseRequest`, and the Purchasing form, list, source and detail views.
Routes: existing `GET /purchasing/receipts/create?direct=1` and receipt store,
show and update routes; no route or permission was added. Tables/migrations: no
change; `goods_receipt_lines.purchase_order_line_id` was already nullable and the
existing audit event table stores the required reason. No stock replay, party
balance or payable cache was added.

PHP 8.4 syntax lint, Pint, Blade view compilation and the six receipt routes
passed. The automated test suite and browser flow were not run for this follow-up.
Exact MySQL 8.4 and live operator acceptance remain pending. No variance handling
is added: the bill must match the actual cost recorded on the direct receipt.
Suggested commit: `feat: receive purchases without purchase orders`.
