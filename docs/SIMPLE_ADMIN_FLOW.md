# Simple admin confirmation and transaction PDFs

## Processing follow-up ? 2026-10-27

The authorized [Fabric Out / Fabric In workflow](PROCESSING_FABRIC_FLOW.md) adds automatic
processed output identities, laat/component evidence, per-suit embroidery contracts
and atomic direct vendor handovers. Daily entry no longer requires manual routes
or jobs; historical and Production-linked workflows remain available. One additive
flow-history table and one service configuration preserve existing stock, journals
and permissions. Physical base-unit quantities remain authoritative; component
counts do not automatically create finished suits. Earlier Processing workflow
requirements below are historical where superseded by that guide.


## Phase 12 finance confirmation (2026-10-26)

Finance uses direct Confirm, guided separate screens and actual A4 voucher PDFs.
Payments/receipts may allocate against a bill in the same transaction. Optional
finance drafts are not introduced; an unsubmitted form can be cancelled, while a
confirmed voucher needs a controlled reversal. No extra approver is required.
See [ACCOUNTING_FINANCE.md](ACCOUNTING_FINANCE.md).


## Phase 11 Wholesale operator flow (2026-10-26)
Wholesale uses its own sidebar and authorization role. Sales forms offer direct
Confirm or optional Save for later / preview, followed by next-step guidance and
actual A4 PDFs. No extra approver is required by default. Customer credit settings
remain an administrator permission. See [SALES.md](SALES.md).

## Phase 10 packing and costing

Finished Goods uses the same direct Confirm / optional Save for later pattern for
packing/recovery conversions, with private conversion and costing PDFs. Actual
Production completion is reused and does not receive suits twice. Separate
Wholesale permissions remain unchanged. See FINISHED_GOODS_COSTING.md.

## Production added in Phase 9 (2026-10-26)

The same Confirm / optional Save for later pattern now applies to Production
batches and consumption/output reports, with Download PDF and clean print views.
Start at Production > Start here. Publish a recipe, confirm a batch, record actual
inputs/output, and complete only when quantities/WIP/vendor custody reconcile.
The separate Wholesale access boundary is preserved. See
[PRODUCTION.md](PRODUCTION.md) for prerequisites and a worked example.

Date: 2026-10-22, Asia/Karachi. The user confirmed that one administrator manages
Purchasing and Processing, while Wholesale will have a separate operator. This
follow-up simplifies those existing workflows and fulfills the earlier request
for real transaction PDFs. It does not implement wholesale sales or a later phase.

## Daily operating guide

For an existing administrator with the installed super-admin permissions:

1. Complete the form and check the supplier/vendor, physical quantities, location,
   original references and price/rate. The final section explains the effect.
2. Choose the main **Confirm** button. This saves and finalizes together. A
   purchase order's submission/approval happens inside the same operation; no
   separate draft, submit and approve screens are required for the admin.
3. The final document page offers the next action and **Download PDF** /
   **Print document**. A download is a real application/pdf attachment, not a
   screenshot or an instruction to install a browser extension.
4. Use **Save for later** only for unfinished work. This preserves the original
   draft behavior and creates no stock/payable posting. Existing saved purchase
   orders can be finalized with one Confirm action. Legacy submit/post URLs remain.

| Form | Main button | Effect |
|---|---|---|
| Purchase order | Confirm purchase order | Authorizes the purchase commitment; no stock/payable |
| Goods receipt | Confirm goods receipt | Receives accepted stock and posts the existing receipt accrual |
| Purchase return | Confirm purchase return | Issues original receipt stock; does not settle an existing bill |
| Supplier bill | Confirm bill | Matches the receipt and records the original supplier obligation |
| Processing job | Confirm processing job | Freezes route/vendors/rates; no fabric movement |
| Dispatch challan | Confirm dispatch | Moves owned fabric to the assigned vendor custody |
| Processing receipt | Confirm processing receipt | Reconciles issued quantity, returned output/loss and service accrual |
| Contractor bill | Confirm bill | Matches accepted services and records the contractor obligation |

Purchasing: **Confirm order -> Receive goods -> Confirm receipt -> Record supplier
bill -> Confirm bill -> Payable sources**. Physical returns branch from a GRN.

Processing: **Confirm job -> Dispatch fabric -> Confirm dispatch -> Receive fabric
-> Confirm processing receipt -> Record contractor bill -> Confirm bill**. Partial
returns leave the remainder with the vendor. Route stage sequence stays configurable.

Supplier, warehouse, job, vendor and frozen rates still come from the selected
source where the existing guided screen supports this. No new duplicate entry
screen is added. Inspection/quality release and whole-roll constraints remain.

## Status meanings

- **Saved for later**: stored as `draft`, editable where the module supports it,
  with no stock/financial posting. Processing drafts still require cancel/recreate
  for changed details; use direct Confirm for completed entries.
- **Awaiting confirmation**: a previously submitted PO awaiting an authorized
  decision. The existing separate approval path is retained for restricted users.
- **Confirmed**: stored as `posted`; its details are immutable and its documented
  stock/custody/financial effects have been recorded once.
- **Cancelled**: abandoned saved entry, retained for history.

A failed direct confirmation rolls back the new preparation, dependent lines,
lots/rolls/custody, numbering, audits and posting together. An existing draft edit
that fails confirmation also rolls back that attempted edit. Inputs return through
normal validation. Correct the issue and retry; no hidden saved document is left.
Posted corrections still use the existing supported reversal/correction policies.

## Permissions and the Wholesale operator

There is no extra approval account or permission dialog for the administrator.
The existing super-admin role already carries preparation and finalization access.
This change does not automatically grant permissions, modify live roles or identify
an administrator by user ID/email. Server authorization remains in force so a
separate Wholesale operator cannot enter Purchasing/Processing, invoke Confirm or
fetch their private PDFs without explicit module permissions.

A preparation-only account sees Save for later, and may send a saved purchase order
for approval through the retained workflow. Crafted direct-confirm requests still
require the existing document-kind approval/post permission plus preparation access.
Wholesale sales permissions/screens remain a future authorized sales phase. No
operational access was removed from existing users and no live account was edited.

## Real transaction documents

Both modules have a protected PDF download and a clean printable HTML view for
all four document kinds, eight types in total. A4 portrait documents include:

- Current company name/address/contact and a supported configured logo.
- Title, document number, date, status, supplier/contractor and source references.
- Document-specific items, lots/rolls, units, quantities, original rates, discounts,
  charges/totals where applicable; dispatch challans do not disclose job rates.
- Prepared/authorized/received signature spaces, page counters, repeating table
  headings and a note that reprints refer to the same transaction.
- DRAFT watermark/review notice for unconfirmed entries; cancelled status is clear.

No PDF/print request posts a document or duplicates stock, custody, journals or
payables. Downloads are private/no-store and policy-protected. HTML escapes user
text. The renderer disables remote resources, PHP and JavaScript and restricts
local file access. Only bounded, verified raster logo bytes from the configured
private branding directory may be embedded; arbitrary paths/URLs/SVG are ignored.

The UI retains Inter; PDFs embed DejaVu Sans for consistent Unicode rendering.
JPEG logos work without GD; install PHP GD for PNG/WebP branding. Without it those
logos are omitted and the legal company name remains. Downloads use current company
and party presentation data, not a new immutable PDF archive/legal-identity snapshot.
Transaction quantities/rates/amounts continue using stored document values.

Dependency: direct `dompdf/dompdf` ~3.1.6, locked 3.1.6, with four transitives. No
Laravel wrapper or service provider is required. Its PHP constraint supports 8.3,
and actual Laravel 13 rendering is exercised in HTTP tests. Sources checked:
[official release](https://github.com/dompdf/dompdf/releases/tag/v3.1.6) and
[package constraints](https://github.com/dompdf/dompdf/blob/v3.1.6/composer.json).

## Completion report

### 1. Features delivered

Direct save/confirm across eight existing transaction forms; one-action saved PO
confirmation; optional saved entries; clearer effects/statuses; retained next steps
and restricted-user approval path; protected actual PDF and clean print output.

### 2. Files created and modified

Created:

- `app/Modules/Core/Support/DocumentPresentation.php`
- `app/Modules/Core/Services/TransactionDocument.php`
- `app/Modules/Core/Http/Controllers/TransactionDocumentController.php`
- `app/Modules/Core/Resources/views/documents/transaction.blade.php`
- `resources/views/components/erp/confirm-buttons.blade.php`
- `tests/Feature/SimplePurchasingFlowTest.php`
- `tests/Feature/SimpleProcessingFlowTest.php`
- `tests/Feature/TransactionPdfLayoutTest.php`
- `tests/Frontend/render-transaction-pdfs.ps1`
- `docs/SIMPLE_ADMIN_FLOW.md`

Modified:

- `app/Modules/Purchasing/Services/{PurchasingService,PurchasingWorkspace}.php`
- `app/Modules/Processing/Services/{ProcessingService,ProcessingWorkspace}.php`
- `app/Modules/Purchasing/Http/Controllers/PurchasingController.php`
- `app/Modules/Processing/Http/Controllers/ProcessingController.php`
- Both modules' `Routes/web.php`.
- `app/Modules/Purchasing/Http/Requests/{SavePurchaseRequest,PurchaseActionRequest}.php`
- `app/Modules/Processing/Http/Requests/ProcessingRequest.php`
- Both modules' `Resources/views/{form,show,index,overview}.blade.php` and related
  source/setup guidance wording in existing top-level views.
- `tests/Support/inventory_worker.php`
- `tests/Feature/{PurchasingConcurrencyTest,ProcessingConcurrencyTest}.php`
- `tests/Frontend/{purchasing-browser,processing-browser}.mjs`
- `composer.json`, `composer.lock`, `AGENTS.md`, `README.md`.
- `docs/{PROJECT_OVERVIEW,PRD,ARCHITECTURE,BUSINESS_RULES,DEVELOPMENT_PHASES,
  PROJECT_STATE,CHANGELOG,TESTING,DEPLOYMENT,PURCHASING,PROCESSING,PURCHASING_UX,
  PROCESSING_UX}.md`.

### 3. Tables and migrations

None; all 78 application tables remain unchanged. Existing posted/draft states,
audit events and keys are reused. No application DB migration/reset, business-data
seeding, .env edit or credential/role assignment was performed. Test writes use
only the guarded isolated test database.

### 4. Routes and screens

Added `GET /purchasing/documents/{document}/pdf` (`purchasing.pdf`) and the matching
Processing URL (`processing.pdf`). Existing `/print` URLs now serve clean document
HTML. Existing Purchasing action URL accepts `confirm`. Form save routes accept
optional `intent=confirm|save`; absence preserves the old draft API behavior.
Route listing reports 42 Purchasing routes and 40 Processing routes, including
shared Supplier/Vendor routes. All legacy routes and module scope checks remain;
no new business screen/module.

### 5. Tests and results

Final full regression: **153 tests / 1,111 assertions passed**, including all
11 races. The initial focused confirmation/PDF suite passed 10 tests / 175
assertions; targeted print/pagination coverage passed two tests / 25 assertions.
Twenty-one Node tests, Purchasing/Processing Edge desktop/mobile checks, Pint,
Vite build (66 modules), Composer validation, route cache/list/clear and lint of
125 compiled Blade templates passed.
Final PDF-focused verification also passed **3 tests / 121 assertions**.
All eight real PDFs generated successfully and opened/rendered with Windows' PDF
reader; representative PO and processing receipt pages were visually reviewed.
See [TESTING.md](TESTING.md) for final regression, two additional race tests,
multipage PDF coverage, browser checks and quality results.

### 6. Known limits

Authoritative MySQL 8.4 and live operator/accountant/printer acceptance remain
pending; local tests use MariaDB 10.4.32. Existing processing draft editing,
whole-roll/splitting, credit/settlement and reversal boundaries remain. PDFs are
live document presentations, not signed/archived or tax invoices. GD is needed for
PNG/WebP logos. Manufacturing, sales, payments and vendor opening-stock imports
are not implemented by this follow-up.

### 7. Documentation updated

This guide plus all documents listed above explain Confirm, optional saved entries,
private PDFs, retained permissions, deployment and the continued Phase 8 boundary.
Older phase counts and completion reports remain historical.

### 8. Suggested Git commit message

`feat(erp): simplify admin confirmations and add transaction PDFs`

### 9. Readiness for approval

Ready for code review and operator acceptance subject to final checks recorded in
TESTING.md and the stated MySQL 8.4/branding/deployment limits. Stop after this
follow-up; no subsequent business phase is started.
