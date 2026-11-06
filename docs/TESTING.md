# Testing and validation

## Searchable supplier bill receipt field (2026-11-05)

Automated tests were not run for this follow-up. PHP 8.4 Blade compilation and
the focused diff check passed; browser interaction remains unverified.

## Searchable goods receipt order field (2026-11-05)

Automated tests were not run for this follow-up. Blade compilation and formatting
checks were performed; browser interaction remains unverified.

## Direct purchase receipt without a PO follow-up (2026-11-05)

Automated tests were not run for this follow-up. PHP 8.4 syntax lint, Pint,
Blade view compilation and Purchasing receipt route listing passed. The live
browser flow and MySQL 8.4 acceptance remain outstanding.

## Physical Stock sidebar — 2026-10-31

`PhysicalSuitStockTest` passed 8 tests / 57 assertions on PHP 8.4.9 with the
isolated `irfan_textile_phase3_test` database, including active submenu and
permission-limited navigation. Blade cache compilation, Pint for the test file,
and `git diff --check` passed. No interactive browser run was performed.

## Guided Finance UX — 2026-10-29

- FinanceTest: **16 tests / 97 assertions passed**, PHP 8.3.35, isolated
  `irfan_textile_phase3_test` schema. Includes real bill/advance screen data,
  standard-form fallback, cash posting, settlements, reversals and permissions.
- `node --test tests/Frontend/finance.test.mjs`: **3 passed**. Role/account
  isolation, customer refund direction, capped exact four-decimal suggestions,
  excess on account, bank method defaults and validation-state restoration.
- Vite build: **70 modules passed**; changed PHP files Pint passed; Blade cache
  compilation passed and compiled views cleared afterward.
- Edge browser harness: **20 fixtures passed** desktop/mobile, no JavaScript
  errors. Real rendered guided form payloads exclude cash/bank fields during
  settlement and exclude settlement fields during new payments. Screenshots in
  ignored `storage/framework/testing/finance-browser/`. Browser runs against
  synthetic exported test HTML, not live company transactions.
- Initial default PHP 8.2 was rejected by Composer; verified using the existing
  PHP 8.3.35 runtime. An overlapping test run caused isolated-schema migration
  errors; stopped it and the final serial full FinanceTest run passed. Edge
  initially failed sandbox GPU startup; the approved outside-sandbox run passed.
- No full-system regression or exact MySQL 8.4 acceptance claimed. Application
  database unchanged. See [FINANCE_GUIDED_UX.md](FINANCE_GUIDED_UX.md).

## Physical Suit Stock and Sales invoice fix — 2026-10-28

Final focused regression: **22 tests / 102 assertions passed**, 78.633 seconds,
PHP 8.3.35, isolated `irfan_textile_phase3_test` database. Suites:
PhysicalSuitStockTest (6), FinanceSalesIntegrationTest (1), SalesTest (15).
Coverage includes actual opening/conversion value, retry/changed-payload rejection,
atomic shortage rollback, whole-suit precision, held input, duplicate batch,
HTTP confirmation, Wholesale denial, customer payment/AR and dated delivery
history, and invoice rendering without falling into return-only fields.
This is a focused run, not a full-system regression or new concurrency benchmark.

Vite build passed (69 modules). Four physical-stock routes registered. Blade cache
compilation passed; **167 compiled views** passed PHP syntax checks. Changed PHP
files formatted with Pint. Final source/feature tests passed after formatting.
No application data migration or business transaction was run. Exact MySQL 8.4
and interactive browser/operator acceptance remain pending.

Initial run failed during test-schema setup because C: had zero free bytes;
the new empty test file was restored after space was freed. A subsequent run
needed the PHP runtime's OpenSSL extension enabled for cookie encryption.
Both environment failures precede the successful final run above.

```powershell
$erpRuntime = Join-Path $env:TEMP 'irfan-textile-phase2-php83-retry'
& "$erpRuntime/php.exe" -d "extension_dir=$erpRuntime/ext" -d extension=mbstring -d extension=pdo_mysql -d extension=fileinfo -d extension=openssl vendor/phpunit/phpunit/phpunit tests/Feature/PhysicalSuitStockTest.php tests/Feature/FinanceSalesIntegrationTest.php tests/Feature/SalesTest.php
```

Workflow, file inventory, routes and limits: [PHYSICAL_SUIT_STOCK.md](PHYSICAL_SUIT_STOCK.md).

## Fabric flow follow-up verification (2026-10-27)

- Final focused regression: **51 passed / 340 assertions**, zero failures/errors/skips,
  234.12s. Covers new Fabric Out/In, stock/cost custody, per-suit embroidery with
  separate component counts, partial and rejected receipts, atomic direct handover,
  rollback, duplicate-key protection, HTTP/PDF output, Wholesale denial, period
  closure, whole-roll restrictions, existing Processing, Production integration and
  PDF layouts. Includes an independent simultaneous Fabric Out retry race.
- New Fabric Out/In and existing Processing browser harnesses passed Edge 156 on
  desktop/mobile, including service filtering, published rate fill, destination
  controls, submitted-field disabling, navigation and no JavaScript errors.
  New desktop/mobile screenshots were visually reviewed.
- Vite build passed (69 modules). Blade compilation and route cache/list/clear passed;
  four new route definitions. Changed PHP files checked with Pint; relative Markdown
  links have no missing targets.
- The system PHP is 8.2 and cannot load this project. Checks used the existing
  `irfan-textile-phase2-php83-retry/php.exe` runtime under TEMP. Initial test fixture
  omissions (Wholesale setup and closed-period actor/timestamp) were corrected before
  the final successful run. Chromium initially failed under sandbox restrictions;
  its approved unsandboxed headless run passed.
- Additional independent competing vendor-handover race: **1 passed / 10 assertions**,
  50.63s. Exactly one of two competing 60-meter handovers succeeded, with 40 meters
  remaining at the original vendor and 60 at the next. Final runs cover **52 distinct
  tests / 350 assertions**.
- Local activation: migration batch 11, verified 293,632-byte backup, 116 existing
  table fingerprints matched, empty new flow table, seven real GET pages returned 200.
- This is a focused regression, not a new run of the entire 236-case Phase 12 suite.
  Exact MySQL 8.4 and live operator acceptance remain outstanding.


## Phase 12 final verification (2026-10-26)

- Full regression: **236 passed / 1,683 assertions**, zero errors, failures or skips,
  1,314.31s. It includes the established module lifecycle, stock, cost, authorization
  and concurrency coverage.
- Final focused Finance run after the last bank-opening safeguard: **18 passed / 100
  assertions**, 146.57s. It covers independent same-key and competing-payment races,
  payment/receipt allocations, supplier-return credits without stock replay, finance
  reversals, period closure, source reconciliation, bank evidence/matching, report
  integrity and Wholesale/calendar authorization boundaries.
- Node frontend-state suite: **32 passed**. Vite production build: **69 modules**.
  Blade cache and route cache/list/clear passed; 14 Finance route definitions listed.
- Private finance browser fixtures ran desktop/mobile across 19 Finance views with
  dynamic journal-line editing and no JavaScript errors. The opening voucher PDF was
  rendered and visually reviewed as a one-page A4 finance document. Whole-repository
  Pint passed, and **290** relative documentation links had zero broken targets.
- Local activation used a private 264,744-byte SQL backup. Both finance migrations
  ran in batch 10 and setup seeded configuration plus eight existing-admin accounting
  grants. Sixteen real GET pages returned 200. Original operational row fingerprints,
  document history and unrelated role grants remained unchanged; all eleven new
  operational tables were empty. The local engine is MariaDB 10.4.32, so MySQL 8.4
  remains a deployment validation requirement.
- An earlier run was interrupted by lack of disk space. Disposable generated browser
  profiles were removed and the user freed space; all results above are subsequent
  successful checks.


## Phase 11 final verification (2026-10-26)

Environment: Laravel 13.38/PHP 8.3.35, guarded isolated
irfan_textile_phase3_test, MariaDB 10.4.32, Node 24.12, Vite 7.3.7, Edge 156.
Exact MySQL 8.4 and actual company/operator/accountant acceptance are pending.

- Full regression: **220 passed / 1,585 assertions**, 2,361.69s, zero errors,
  failures or skips. Includes 20 independent concurrency cases (15 prior + five
  new Sales): identical dispatch, competing stock, duplicate invoice match,
  customer credit commitments and competing sales reservations.
- Final Sales/foundation/identity run after final integration safeguards:
  **44 passed / 219 assertions**, 260.16s, zero errors/failures/skips. Includes
  all 24 Sales cases and 20 existing authentication/foundation cases. Explicitly
  covers frozen GL-account mapping, required price/quantity fields, reservation
  retry hash, actual Wholesale login landing, filtered dashboard and prior auth.
  Runs overlap: 222 distinct cases verified. Do not add the run totals together.
- Domain checks include partial fulfillment/invoicing, discounts and residual
  rounding, exact FIFO COGS/AR/unbilled exposure, physical returns vs credits,
  immutable history and price snapshots, SET conversion changes, separate PACK
  stock, held/damaged returns, expired reservations, backorders, closed periods,
  customer/supplier-role validation, cross-module IDs and backend permissions.
- Initial fixture/integration failures were corrected before final checks: party
  fixture used the approved kind; user-role assigned_by supplied; binary hash
  storage corrected; reservation import restored; customer Blade adjacency fixed;
  period closure fixture now supplies closed_at/closed_by. No business assertion
  or concurrency barrier was removed. Final SQL tests ran sequentially on the
  disposable guarded test DB, never on company operational data.
- Node 32 tests passed. Final Vite build passed with 69 modules. Whole-repository
  Pint passed. 153 compiled Blade templates passed PHP lint; route cache/list/
  clear passed. Sales exposes 10 new route definitions plus six existing customer
  routes (16 under the prefix). Config/route/compiled caches cleared locally.
- Five new actual A4 PDFs independently opened/rendered with Windows PDF reader
  and visually reviewed: sales order, dispatch, invoice, physical return and
  commercial credit. Price precision remains exact; redundant trailing zeros in
  unit prices are trimmed to avoid wrapping. Previous transaction PDFs rendered
  too, including the nine-page fixture. These are private synthetic test artifacts.
- Wholesale nine-screen desktop/mobile run passed item search and unit selection,
  sidebar, stable dynamic line keys, direct confirmation, no JS errors or overflow.
  The fixture server uses synthetic item JSON, not localhost company data. Private
  fixture asset references were synchronized to the final Vite manifest for the
  final six-module browser regression. All six passed: Purchasing, Processing,
  seven Catalog screens, six Production screens, eight Finished Goods screens
  and nine Wholesale screens, on desktop/mobile with no JS errors or overflow.
- Local activation: fresh 236,429-byte SQL backup completed before migration batch
  9 and scoped SalesSetupSeeder. Nine real local admin GET pages returned 200.
  Existing operational counts and PK-ordered row fingerprints, document HEX payload/
  status hashes, users and unrelated role grants were preserved; eleven new
  tables empty. Five typed accounts, five document types, one Wholesale role and
  two permission-grant audit events added; no user/stock/order/balance fabricated.
  Seven total admin Sales permissions / six Wholesale grants verified. Restore
  rehearsal and exact target DB acceptance are not claimed.
- Final documentation check: 264 relative links, zero broken.

Prior dated checks below are historical.

## Phase 10 final verification (2026-10-26)

Environment: Laravel 13.38, PHP 8.3.35, guarded isolated
irfan_textile_phase3_test on MariaDB 10.4.32, Node 24.12, Vite 7.3.7, Edge 156.
Actual MySQL 8.4 and commercial operator/accountant acceptance remain pending.

- Full final regression: **198 passed / 1,433 assertions**, 877.97s; JUnit records
  zero errors, failures or skips. Includes 20 new Finished Goods cases and 15
  independent concurrency cases across the suite. Tests ran sequentially against
  the guarded test database, not company data.
- Tests cover exact packing/containers, full recovery, partial production sources,
  rejected stock and disposal/reversal, immutable history, unit compatibility,
  measured packaging in meters, actual processing/labor/overhead/conversion costs,
  rounding, changed idempotency payload, same/competing-source worker races,
  cross-module/Wholesale restrictions, HTTP/PDF flows and scoped setup.
- Initial 15-case focused run passed 128 assertions. A later disposal reversal
  test exposed exclusion of original entries once status became reversed; the
  read-only costing replay now includes both signed posted/reversed facts. Its
  focused correction passed, followed by the clean full suite above.
- 29 Node tests passed; Vite production build passed, 68 modules. Whole-repository
  Pint passed. 145 compiled Blade views passed PHP lint. Route cache/list/clear
  passed, including 16 Finished Goods routes; compiled/config caches cleared.
- All three new PDF fixtures independently opened/rendered using Windows PDF
  reader and were visually reviewed: packing conversion, packing actual costs,
  production batch actual costs. Previous transaction PDFs rendered too, including
  a nine-page document. Synthetic fixtures remain private under storage/framework.
- Local activation: backup completed before additive migration batch 8 and scoped
  setup. Six real local admin GET pages returned 200. Operational counts, document
  status/payload hashes and other-role grants were preserved; seven new tables
  empty, four admin grants verified. No new user or stock transaction. Backup
  restore drill and exact target database acceptance are not claimed.

- Final Edge 156 desktop/mobile runs passed for all five modules: Processing,
  Purchasing, seven Catalog screens, six Production screens and eight Finished
  Goods screens. Checked sidebar, dynamic forms, retained quantities, direct
  confirmation, no JS errors and no horizontal overflow. A first Catalog launch
  hit Windows EBUSY while reading DevToolsActivePort; a new-profile retry passed.
  No application assertion or business rule was relaxed.
- 234 relative documentation links checked, zero broken. One migration row and
  one Finished Goods grant audit event added locally; other-role grants preserved.

Prior dated phase results below remain historical.

## Phase 9 activation verification (2026-10-26)

- New scoped ProductionSetupSeeder plus Production workspace: **5 passed / 71
  assertions**, 32.88s, isolated test DB. Covers repeatable setup, retaining
  unrelated admin/Wholesale grants, no fabricated user/stock/documents, one audit
  event despite repeated setup, page/PDF flows and backend restrictions.
- Actual local irfan_textile: additive migration batch 7 applied successfully;
  nine Production tables and five existing-admin permissions verified. A private
  SQL backup completed before the migration; restore rehearsal was not performed.
- Seven local Production GET pages returned 200 with the existing admin; these
  read-only kernel checks used array sessions and did not sign a browser in or
  create users/documents. Existing operational counts, document payload/status
  hashes and other-role permission sets matched the pre-migration baseline.
- One new permission-grant audit record was verified. Config/route/view caches
  cleared. Local DB is MariaDB 10.4.32; exact MySQL 8.4 remains unverified.
- This adds one test to the previously reviewed 177-case suite (178 now). The
  earlier full 174 and final 24 Production runs below were not repeated wholesale
  for this seeder-only follow-up.

## Phase 9 verification (2026-10-26)

Environment: Laravel 13.38.0, PHP 8.3.35, guarded isolated
irfan_textile_phase3_test on MariaDB 10.4.32; Node 24.12.0, Vite 7.3.7, Edge 156.
No checks against target MySQL 8.4 or live company data are claimed.

- Initial focused domain/HTTP/cost/integration/concurrency run: 20 passed / 132
  assertions. Completion-only closure was subsequently added and passed.
- Node: 26 passed, including five new production state/reference tests.
- Production Edge desktop/mobile: six screens passed, nested sidebar, direct
  confirmation controls, dynamic input/output/wastage references, no horizontal
  overflow or JS errors. Initial sandbox Edge GPU startup failed; the approved
  external headless run passed. This was a browser launch restriction.
- Vite production build: 67 modules passed. Production route list: 14 routes.
- First full regression: 161 passed / 13 failed, 1,161 assertions, 1,178.67s.
  Rendering found an inline @php/block interaction in Processing and adjacent
  Blade directives in Catalog; both were corrected. Two separate-process races
  hit worker startup deadlines on the slow Windows/OneDrive environment. Worker
  startup limits were extended while retaining the mandatory simultaneous-ready
  barrier and separate PHP/PDO processes. No business assertion was removed.
- Clean full regression after the rendering/startup fixes: **174 passed / 1,262
  assertions**, 848.57s, zero errors/failures/skips. Includes **13 independent
  concurrency cases** (the previous 11 and two new production races).
- Subsequent final Production verification after WIP-origin safeguards: **24
  passed / 161 assertions**, 146.39s, zero errors/failures/skips. This includes
  three additional cases beyond the full-run discovery: same-batch WIP cannot
  satisfy a raw requirement twice, transferred WIP cannot fund another batch,
  and batch-linked Processing rejects foreign WIP but permits its own custody.
  These are overlapping runs, not 198 distinct tests; current suite has 177 cases.
- Final Edge 156 browser runs: Production six screens, Processing five,
  Purchasing five and Catalog seven all passed desktop/mobile, sidebar behavior,
  dynamic field/reference checks and no JS errors/horizontal overflow. Fixtures
  were regenerated from passing final HTTP tests and current Vite assets.
- Whole-repository `vendor/bin/pint --test`: passed. One prior warehouse migration
  received formatting only; its SQL/data semantics are unchanged.
- Blade compilation and PHP lint: **134 compiled views passed**. Cache cleared.
- Route cache/list/clear: passed; **14 Production routes**. View cache cleared.
- Actual batch/report PDF downloads passed MIME/signature/print content checks;
  both opened and rendered as one-page documents using Windows' independent PDF
  reader. Both layouts were visually reviewed. Existing eight transaction types
  and the nine-page draft also rendered successfully with that reader.
- All **220 relative Markdown links** passed validation.

Repeatable commands (supported PHP executable required):

```powershell
php artisan test --compact
php artisan test --compact --filter='ProductionTest|ProductionWorkspaceTest|ProductionCostTest|ProductionProcessingIntegrationTest|ProductionConcurrencyTest'
node --test tests/Frontend/*.test.mjs
npm.cmd run build
php vendor/bin/pint --test
```

For browser fixtures, build first, set PRODUCTION_BROWSER_FIXTURES,
PROCESSING_BROWSER_FIXTURES, PURCHASING_BROWSER_FIXTURES and CATALOG_BROWSER_FIXTURES
to 1 and run the corresponding workspace feature tests. Then run the four
`tests/Frontend/*-browser.mjs` scripts. They serve synthetic HTML/assets locally,
not the app DB. TRANSACTION_PDF_FIXTURES=1 exports actual synthetic PDFs; the
render-transaction-pdfs.ps1 script opens/renders them independently on Windows.

Browser/PDF artifacts are synthetic PHPUnit fixtures under ignored storage. The
initial Processing browser attempt used stale fixtures because the form failed
to render; the final regenerated-fixture run passed. See
[PRODUCTION.md](PRODUCTION.md) for workflow coverage and known limits.

## Simple admin confirmation and PDF checks (2026-10-22)

- Final full regression: **153 tests passed / 1,111 assertions**, 427.33s,
  Laravel 13.38.0 / PHP 8.3.35, guarded isolated MariaDB 10.4.32. This includes
  all **11** independent races (nine existing plus simultaneous direct PO
  confirmation replay and competing direct dispatches with losing preparation
  rollback). No skipped/failing cases in the final full run.
- Purchasing and Processing Edge 155 desktop/mobile browser smoke: **passed**,
  including main Confirm/optional Save for later buttons, existing source/line
  interactions, navigation and no JS errors/horizontal overflow.
- Route cache/list/clear: **passed**, 42 Purchasing and 40 Processing routes,
  including shared party routes. Two new PDF GET routes; existing print/action
  URLs preserved. Blade compilation/lint: **125 templates passed**, cache cleared.
- Composer manifest/lock validation and **198 relative documentation links** passed.
- Focused direct-confirm/PDF suite: **10 tests / 175 assertions passed**.
  Covers all eight transaction kinds through real HTTP, optional saved entries,
  purchase submission/approval auditing, repeat create requests, changed-payload
  rejection, existing saved PO finalization, stale keys, rollback, partial receipt/
  return/bill reconciliation and preparation-only/Wholesale-only access denial.
- Final PDF-focused verification on the final renderer: **3 passed / 121
  assertions**, 28.72s. Regenerated all eight types and a nine-page draft, opened
  and rendered independently; the final footer includes the document reference.
- Targeted legacy-print and PDF pagination checks: **2 passed / 25 assertions**.
  The long draft uses 60 descriptive rows; multiple PDF pages, escaped external
  image markup, invalid-logo-path rejection and no stock posting were checked.
- The first full run passed 151 cases and failed one legacy print-button label
  assertion. The clean print toolbar now preserves "Print purchase order"; that
  legacy case and the new pagination test then passed. Final regression is
  recorded separately below.
- Actual PDF attachments for all eight kinds passed `%PDF-`/MIME/content checks.
  Clean HTML output includes references, quantities/units, rates/amounts where
  relevant, status and signature sections, with no application navigation.
- Windows' independent built-in PDF reader opened all eight generated files and
  rendered their first pages. PO and processing receipt renders were visually
  reviewed; the fixtures were one A4 page each with page counters and readable
  table/total/signature sections. Synthetic exports remain under ignored
  `storage/framework/testing/transaction-pdf`; no live data is exported.
- Node regression: **21 tests passed**. Pint and Vite build (66 modules) passed.
- Dompdf 3.1.6 was installed through Composer with four transitives; no existing
  dependency was upgraded. Composer reported no security advisories. Official PHP
  constraints were checked; actual integration runs on Laravel 13/PHP 8.3.35.
- An initial direct Processing confirmation exposed an unhydrated DB-default lock
  version; reloading the newly saved document fixed it. The focused suite then
  passed. An initial Windows render helper overload failed; explicit IAsyncAction
  handling fixed the test renderer, and all eight files rendered successfully.
- No application DB migration/reset, .env, credentials, role grants or business
  seeding. Tests use the guarded isolated MariaDB DB; exact MySQL 8.4 and live
  operator/accountant/printer acceptance remain pending.

Reproduce PDF checks after installing the lock file:

```powershell
$erpPhp = Join-Path $env:TEMP 'irfan-textile-phase2-php83-retry/php.exe'
$env:TRANSACTION_PDF_FIXTURES = '1'
& $erpPhp artisan test --filter='SimplePurchasingFlowTest|SimpleProcessingFlowTest|TransactionPdfLayoutTest'
Remove-Item Env:\TRANSACTION_PDF_FIXTURES
& ./tests/Frontend/render-transaction-pdfs.ps1
```

Windows rendering is optional verification; PDF generation in the application is
PHP-only and cross-platform. Enable GD for PNG/WebP logos; without GD, legal-name
branding/JPEG remain. See [SIMPLE_ADMIN_FLOW.md](SIMPLE_ADMIN_FLOW.md).

## Guided Textile master workspace verification (2026-10-22)

- Full regression: **140 passed / 910 assertions**, 403.15s, PHP 8.3.35 on
  guarded isolated MariaDB 10.4.32. All nine independent Inventory/Purchasing/
  Processing concurrency tests passed; there are no skipped/failing cases.
- New CatalogWorkspaceTest: **9 passed / 74 assertions**. Existing
  TextileMasterDataTest: **15 passed / 150 assertions**. Coverage includes setup
  presets with no stock writes, separate master screens, category/item guidance,
  compatible/duplicate alternate units, missing-unit configuration, frozen
  purchase-referenced identity, validation input and view/manage/no-access roles.
- Node frontend suite: **21 passed** (eight Catalog, five Purchasing, eight
  Processing). Catalog covers explicit M/SUIT/SVC suggestions, physical-unit
  compatibility, preserved tracking/edit values, read-only locks, inactive units,
  retained fabric input and missing mandatory units.
- Edge 155 desktop/mobile Catalog smoke: **passed** on all seven synthetic pages
  (overview, new item, edit, view, conversions, unit, category). Live Alpine type
  selection changes units/stock flags, retains typed fabric text, omits disabled
  fabric fields from service FormData, posts explicit booleans and enforces
  protected/read-only field display. Compatible alternate units, whole-roll help,
  sidebar toggling, no horizontal overflow and no runtime JS errors verified.
  Desktop/mobile screenshots were visually reviewed. No browser submits business
  forms or signs into the live app; HTTP/backend behavior is covered by PHPUnit.
- Existing Purchasing and Processing Edge desktop/mobile smoke: **passed** with
  the updated shared bundle/sidebar. An initial Purchasing browser attempt used
  previously exported HTML pointing at removed Vite hashes and timed out; after
  the full suite exported fresh fixtures, the same browser check passed. No
  application change was needed for that fixture issue.
- Pint: **passed** after formatting the new feature test. No domain changes.
- Vite production build: **passed**, 66 modules, no new dependency.
- Blade view cache and PHP syntax lint: **123 compiled templates passed**; cache cleared.
- Route cache/list/clear passed. Listing reports **11 Catalog route templates** (two new GET routes, nine
  preserved); the closed six-master parameter expands to 41 method/URL choices.
- Changed PHP syntax checks and **183 relative documentation links** passed.
- Application DB data, .env, credentials, migrations and seed configuration are
  unchanged. Tests use the guarded isolated test database. Exact MySQL 8.4 and
  live operator acceptance remain outstanding.

Build before exporting fixtures so generated HTML references current Vite assets:

```powershell
$erpPhp = Join-Path $env:TEMP 'irfan-textile-phase2-php83-retry/php.exe'
npm.cmd run build
$env:CATALOG_BROWSER_FIXTURES = '1'
& $erpPhp vendor/phpunit/phpunit/phpunit --filter=CatalogWorkspaceTest
Remove-Item Env:\CATALOG_BROWSER_FIXTURES
node --test tests/Frontend/catalog.test.mjs tests/Frontend/purchasing.test.mjs tests/Frontend/processing.test.mjs
node tests/Frontend/catalog-browser.mjs
```

The optional HTML/screenshots/headless profile stay under ignored
`storage/framework/testing/catalog-browser`. The Node runner owns/closes its
loopback static server and headless Chromium instance. Set `CATALOG_BROWSER` to
another compatible executable if needed; Windows Edge/Chrome paths are detected.
See [TEXTILE_MASTER_UX.md](TEXTILE_MASTER_UX.md) for the guide/completion report.

## Guided Purchasing workspace verification (2026-10-22)

- Full regression: **131 tests passed, 836 assertions**, 5m 41s, PHP 8.3.35,
  isolated `irfan_textile_phase3_test` on MariaDB 10.4.32. This includes 11 new
  Purchasing workspace cases, the existing 16 Purchasing tests, Processing and
  all nine independent Inventory/Purchasing/Processing race tests.
- New HTTP/read-model coverage: typed list/store/edit/review scope, unchanged
  submission/approval/draft editing, original source links and header context,
  receiving locations, partial/unbilled/returned capacities, complete/closed-source
  empty states, multiple orders/GRNs, transferred original stock, reservations,
  supplier/document permission separation, invalid references, validation input
  preservation and payable links using bill document IDs.
- Node frontend suite: **13 passed** (five Purchasing and eight Processing).
  Purchasing covers preserved initial draft values, original-cost suggestions,
  stable Add/Remove line keys, original lot/roll matching and whole-roll limits.
- Final Microsoft Edge 155 browser smoke: **passed** for synthetic Purchasing
  overview/order/GRN/return/bill pages at desktop 1440px and mobile 390px. Verified
  live Alpine selections, scoped source/stock metadata, bill price suggestions,
  line edits/removal/reindexing, sidebar toggle and no horizontal overflow or
  JavaScript errors. Existing Processing browser smoke also passed against the
  updated shared layout/bundle. Browser checks never submit business documents;
  actual HTTP transactions/posting are covered by PHPUnit.
- An intermediate browser-test assertion was corrected to read a primitive value
  from Alpine's reactive metadata; no application change was needed for that
  assertion. The final browser runs passed with approved host browser access.
- Pint and changed PHP syntax checks: **passed**.
- Vite production build: **passed**, 65 modules; no package installed or changed.
- Route cache/list/clear: **passed**, 41 Purchasing routes including six shared
  Supplier routes (25 new UI routes plus ten preserved document/inquiry routes).
- Blade compilation and PHP lint: **passed**, 119 compiled templates; cleared.
- Relative documentation links: **passed**. No application DB migration/reset,
  business-data seeding, environment edit or credential change was performed.
- Exact MySQL 8.4 and live operator/accounting/printing acceptance remain pending.

Build assets before exporting browser fixtures, so Vite URLs match the manifest:

```powershell
$erpPhp = Join-Path $env:TEMP 'irfan-textile-phase2-php83-retry/php.exe'
npm.cmd run build
$env:PURCHASING_BROWSER_FIXTURES = '1'
& $erpPhp vendor/phpunit/phpunit/phpunit --filter=PurchasingWorkspaceTest
Remove-Item Env:\PURCHASING_BROWSER_FIXTURES
node --test tests/Frontend/purchasing.test.mjs tests/Frontend/processing.test.mjs
node tests/Frontend/purchasing-browser.mjs
```

The optional export writes synthetic HTML only under ignored
`storage/framework/testing/purchasing-browser`. Screenshots and browser profiles
also stay in that test directory. The runner starts/closes its own loopback
static server and headless Chromium instance. Set `PURCHASING_BROWSER` to a
compatible executable if the Windows Edge/Chrome defaults do not apply. A managed
sandbox may require host execution approval for Chromium renderer/GPU helpers.


## Phase 8 guided workspace verification (2026-10-22)

- Full regression suite: **120 passed, 726 assertions**, 5m 55s, PHP 8.3.35,
  isolated `irfan_textile_phase3_test` on MariaDB 10.4.32. All nine independent
  Inventory/Purchasing/Processing concurrency tests passed.
- After the final active-unit stock filter and sidebar/breadcrumb refinements,
  the focused `ProcessingWorkspaceTest` suite passed: **10 tests, 103 assertions**.
  It covers typed lists/stores, wrong-kind/context rejection, workflow links,
  eligible stock/reservations, whole-roll rollback, partial receipt/bill limits,
  complete-source empty states, permission-scoped navigation and validation input.
- Node preview/state checks: **8 passed**. These include meter/PCS charges,
  loss equations, outstanding quantities, whole-roll guidance, duplicate-source
  over-allocation, rejection and stable Add/Remove line keys.
- Final headless Microsoft Edge 155 browser smoke: **passed** on synthetic rendered
  overview, job, dispatch, receipt and bill pages. Verified live Alpine options,
  line addition/removal/reindexing, 40 M closed = 38 M returned + 2 M normal loss,
  60 M remaining, PKR 76 receipt preview and PKR 40 bill preview. Desktop 1440px
  and mobile 390px navigation/width checks passed, with no JavaScript errors.
  This checks rendered fixtures and interactions; real HTTP service transactions
  are covered by PHPUnit, not submitted by the browser runner. An initial managed
  sandbox GPU access-denied failure was resolved by running this browser check
  with approved permissions. No browser security flags were disabled.
- Pint: **passed** for Processing PHP and the new feature test.
- Vite production build: **passed**, 64 modules; no new dependency installed.
- Route cache/list/clear: **passed**, 39 Processing routes including shared vendors.
- Blade compilation and PHP lint: **passed**, 116 compiled templates; views cleared.
- No application DB migration/reset, business-data seeding, credentials or `.env`
  changes were performed. Target MySQL 8.4 and live operator/printing acceptance
  remain outstanding.

Run from the repository root with a supported PHP runtime. Build **before** exporting
fixtures so their Vite asset URLs match the manifest:

```powershell
$erpPhp = Join-Path $env:TEMP 'irfan-textile-phase2-php83-retry/php.exe'
npm.cmd run build
$env:PROCESSING_BROWSER_FIXTURES = '1'
& $erpPhp vendor/phpunit/phpunit/phpunit --filter=ProcessingWorkspaceTest
Remove-Item Env:\PROCESSING_BROWSER_FIXTURES
node --test tests/Frontend/processing.test.mjs
node tests/Frontend/processing-browser.mjs
```

The browser runner detects installed Edge/Chrome on Windows; set
`PROCESSING_BROWSER` to another compatible Chromium executable if needed.
Fixture HTML/screenshots/browser profiles are disposable ignored test artifacts
under `storage/framework/testing/processing-browser`. The runner binds a temporary
loopback static server and closes it and its own headless browser afterwards.
No application sign-in session or business database is used by that runner.


> Historical Phase 7 implementation report (2026-10-21): purchase orders/approval, partial GRNs,
> original lots/rolls, supplier returns, exact-cost stock bill matching and AP
> source records are implemented. See [PURCHASING.md](PURCHASING.md) for the
> actual schema, operating rules and limits. Processing/production/sales,
> payments and tax remain future scope; MySQL 8.4 validation is outstanding.

## Phase 5 verification

`php artisan test`: **39 tests passed, 241 assertions**, including 15 new
TextileMasterDataTest cases and the full Phase 2-4 suite. New checks exercise
fabric create/update/read and specification persistence, explicit classifications,
stock-quantity rejection, SKU uniqueness, exact conversion/precision rules,
whole suits versus pieces/sets, roll rejection, category cycles and parent
deactivation, variant business keys, collections, policies/read-only screens,
all master forms, filtering/pagination, idempotent configuration seeders,
factories, audited metadata and direct FK/CHECK rejection.

The suite uses PHP 8.3.35 and the dedicated `irfan_textile_phase3_test` schema
through PDO MySQL on MariaDB 10.4.32. It does not touch the application database.
The new migration has not been validated on MySQL 8.4. Independent-connection
contention remains a validation task; tests currently exercise serialized
services functionally. No claim of stock posting or inventory testing is made.
BCMath is an explicit platform requirement and quantity calculations use strings.

Run `php artisan test --filter=TextileMasterDataTest` for focused regression.
Run Pint, Composer validation/platform checks, view/route-cache checks and the
Vite build for the full phase checks, with PHP 8.3+ selected on PATH.

Phase 5 final quality results: Pint passed; Composer strict validation and
platform requirements passed; Blade view cache and route cache/list/clear
passed (58 application routes excluding vendor routes); Vite production build
passed. Composer lock package versions were not changed; ext-bcmath was added
to the root platform requirements.

## Phase 4 verification

The current full suite verifies shared party CRUD paths, adding a vendor role to
an existing supplier identity, contacts/addresses, module permission denial,
Dyeing meter-unit configuration, PKR rate publication, prospective successor
closure, overlap rejection, and vendor-rate screen rendering. It also reruns all
foundation and Phase 3 identity/company/calendar tests.

Run the checks from the repository root with PHP 8.3+ and Node.js available:

```powershell
composer run format:check
php artisan test
npm run build
php artisan route:list --except-vendor
php artisan view:cache
```

Tests use `RefreshDatabase` against the isolated `irfan_textile_phase3_test`
schema configured in `phpunit.xml`; the test runner migrates and writes to that
schema. Provision it separately and never point PHPUnit at production or the
application database.

Phase 4 checks passed on PHP 8.3.35 and the local MariaDB 10.4.32 server through
PDO MySQL. The migration and constraints have **not** been validated on MySQL
8.4 LTS. Run a clean migration and full suite on the authoritative target before
production. The tests do not assert independent-connection contention; rate
serialization uses the parent-row lock, but a race test remains required.

## Phase 2/3 verification history

Run the current foundation checks from the repository root with PHP 8.3+ and
Node.js available on `PATH`:

```powershell
composer run format:check
php artisan test
npm run build
php artisan route:list
```

Feature tests verify Laravel's `/up`, login and dashboard routing, module
registration, database-backed `/health`, active/inactive sign-in, session
revocation, module permissions, privilege-delegation limits, user/password
handling, company setup and audit, financial-year/period overlap and close rules.
Tests use `RefreshDatabase`, so PHPUnit migrates and writes its dedicated
`irfan_textile_phase3_test` schema. Provision that schema separately and never
point PHPUnit at the application or production database.

Phase 3 checks passed on PHP 8.3.35 and local MariaDB 10.4.32 through PDO MySQL.
The migration and tests have **not** been validated on MySQL 8.4 LTS; run the
clean migration and full suite on the authoritative target before production.
Vite build and Pint checks do not validate database DDL.

## Database architecture validation for future implementation

Run business migrations against the exact supported MySQL 8.4 LTS version, strict
mode and InnoDB in a disposable schema. Verify `SHOW CREATE TABLE`, information
schema indexes/FKs/checks, exact DECIMAL precision and status constraints. Add a
clean install and migration-up ordering check, plus a reviewed non-destructive
upgrade/backfill rehearsal. Never use a destructive reset against production.

Required transaction tests should cover:

- Schema constraints: FK mismatch rejection, unique role/profile mappings,
  nullable roll uniqueness, status checks, deletion restrictions and precision.
- Posting: receipt/GRNI, service accrual/bill variance, transfers, production
  consumption/output/waste costing, packing, dispatch/invoice, returns/credits,
  balanced journals and audit transitions.
- Concurrency: last-stock issue, rate interval overlap, idempotency retries,
  settlement allocation, period close vs posting, sequence generation, credit
  limit and contractor custody return caps.
- Reconciliation: balances rebuilt from ledgers; inventory value agrees with
  stock GL; journals balance; trial balance and open items agree; FIFO and cost
  genealogy conserve source amounts; reversals net their original effects.
- Security: policies for posting/reversal/rate overrides/payroll/party bank data,
  user deactivation, private attachments and sensitive audit redaction.

Race tests must use independent connections and assert final ledger facts, not
only HTTP status. Test duplicate retries after both an uncommitted failure and a
committed lost response. Review `DATABASE_REVIEW.md` and `BUSINESS_RULES.md` before
writing business posting tests.

## Phase 6 executed inventory checks

InventoryTest covers evidenced openings, typed draft HTTP create/edit/post,
request validation, FIFO balances/rounding, transfer quantity/value conservation,
reservations/expiry/release/fulfilment/cancellation, stale counts/versions,
compensating reversals/downstream limits, zero-cost stock, roll identity,
contractor custody, permissions, immutable history, schema CHECK/generated-key
constraints, stock-use Catalog guards, late-line rollback and projection repair.

InventoryConcurrencyTest uses committed fixtures and separate PHP/PDO worker
processes synchronized by a start barrier. It tests competing issues, identical
posting retries and competing reservations, then asserts persisted ledger facts.
It may recreate only `irfan_textile_phase3_test`, guarded by APP_ENV and exact DB
name. Do not run tests against application data, concurrently with another test
suite on that schema, or against production. Worker DB secrets are passed through
environment variables, never command arguments or task files.

Run `php artisan test`, Pint, Composer validation/platform checks, route/view
cache compilation and Vite build using PHP 8.3+. Final counts/results are in
PROJECT_STATE.md. MySQL 8.4 DDL/runtime and interactive browser acceptance remain
outstanding; MariaDB success is not proof of target compatibility.

## Phase 7 purchasing validation

PurchasingTest covers approval/submission/closure, full and partial receipts,
accepted/rejected goods, zero tolerance, Supplier roles, immutable conversion
snapshots, duplicate posting/invoice references, partial/exact-cost matches,
physical returns and inventory/GRNI/AP conservation, reservations, roll identity,
HTTP lifecycle validation, view/print/history/payable pages, module permissions
and cross-module bypass rejection. Schema checks validate DATETIME(6) and blocked
future tax dimensions.

PurchasingConcurrencyTest launches independent synchronized PHP/PDO workers for
competing partial GRNs and competing supplier bills. Final stock/open-item facts
are asserted. Shared DatabaseRace/inventory_worker helpers retain the original
inventory race tests. They reset only the hard-guarded isolated
irfan_textile_phase3_test schema, never application data. Do not run concurrent
test suites against the same disposable database. Final executed results are in
PROJECT_STATE.md; MySQL 8.4 and browser/device acceptance remain outstanding.

## Phase 8 processing validation

ProcessingTest covers full/partial accepted receipts, zero-cost rejected returns
and re-dispatch, normal/abnormal loss costing, period-bound document posting,
historical master-rate changes versus frozen job rates, negotiated rate authority,
repeat request keys, stock/cost/GL conservation, protected custody, partial and
duplicate bill matching, roll genealogy, output reconciliation, cancellation,
all form/show/print/report routes, denied access and numeric precision boundaries.
ProcessingRoutesTest covers arbitrary combined/separate service sequences,
versioning/immutability, multi-vendor stage continuity and later-stage rejected
return re-dispatch, unit mismatch, route continuity and configurable new services.

ProcessingConcurrencyTest runs synchronized separate PHP processes and PDO
connections for competing dispatches, duplicate dispatch retries, over-receipts
against the same custody line and competing contractor bill matches. Persisted
stock quantities and AP sources are asserted. The shared worker retains the five
existing Inventory/Purchasing race tests. Only APP_ENV=testing and the exact
irfan_textile_phase3_test database may be reset. Never run two suites concurrently
on this database or substitute the application connection.

Final complete suite: **110 passed, 625 assertions** (PHPUnit 12.5.40, PHP 8.3.35,
MariaDB 10.4.32; 5m 17s). This includes 32 Processing tests and all 78 prior tests,
with nine real independent-connection race tests overall. The four final decimal,
unit-conversion and stale-period tests also passed separately (seven assertions).
No tests were skipped or left failing. Use the compatible PHP executable for:

```powershell
php vendor/phpunit/phpunit/phpunit --stop-on-error --stop-on-failure
php vendor/bin/pint --test app database tests config
```

Composer validate --strict and check-platform-reqs passed. Final Vite production
build passed. Route caching/listing passed (20 Processing routes including the
eight existing vendor/rate routes); route cache was cleared. Blade compilation
and PHP syntax checks passed for 112 compiled templates; compiled views were
cleared. Form/show/print/report HTTP rendering is covered by the full suite.
Relative documentation links passed validation. An intermediate print-template
typo was corrected before the final green suite/cache checks. Exact MySQL 8.4,
interactive browser/mobile and printed-page acceptance remain separate deployment
requirements. No application database was migrated/reset or populated by tests.
