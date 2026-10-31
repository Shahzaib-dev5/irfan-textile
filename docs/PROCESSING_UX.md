# Phase 8 follow-up: guided Processing workspace

## Processing follow-up ? 2026-10-27

The authorized [Fabric Out / Fabric In workflow](PROCESSING_FABRIC_FLOW.md) adds automatic
processed output identities, laat/component evidence, per-suit embroidery contracts
and atomic direct vendor handovers. Daily entry no longer requires manual routes
or jobs; historical and Production-linked workflows remain available. One additive
flow-history table and one service configuration preserve existing stock, journals
and permissions. Physical base-unit quantities remain authoritative; component
counts do not automatically create finished suits. Earlier Processing workflow
requirements below are historical where superseded by that guide.


## Current simplified admin flow (2026-10-22)

The admin now chooses **Confirm** directly on a completed form. Save for later is
optional. Purchase order submission/approval is performed inside one operation
when the user has both permissions. Saved orders can also be confirmed once.
The existing transaction/stock/rate/custody rules and restricted-user permissions
remain. Every transaction has Download PDF and a clean Print document view.
See [SIMPLE_ADMIN_FLOW.md](SIMPLE_ADMIN_FLOW.md) for current instructions and the
completion report; earlier multi-screen draft instructions below are historical.

Date: 2026-10-22 (Asia/Karachi). Scope: improve the existing Processing interface.
No Production, payment, opening-contractor-stock migration or roll-split feature
is added. The accounting, ownership, rate-snapshot and ledger rules in
[PROCESSING.md](PROCESSING.md) still apply.

## Where to start

Open **Processing > Overview / Start here** in the sidebar. The category expands
on Processing pages and supports the existing mobile sidebar. It shows only
links permitted for the signed-in user. Vendor administration remains separate
from permission to view/manage Processing documents.

| Sidebar screen | What to do there | When it is needed |
|---|---|---|
| Overview / Start here | Check prerequisites and follow the four-step guide | First use, or whenever the next step is unclear |
| Vendors & rates | Maintain the shared Vendor role, services and published rates | Before assigning that vendor to a job |
| Service types | View configured services; authorized setup users can add another | Standard types are already configuration; add only when needed |
| Processing routes | Define item input/output and service sequence, save and publish | Before creating jobs using that route |
| Processing jobs | Plan quantity, assign vendors and review rates, then approve | For each new batch of processing work |
| Dispatch challans | Select an approved job and stage, dispatch matching released fabric | When physically handing fabric to the contractor |
| Processing receipts | Select the original challan, receive fabric and record actual losses | Whenever some or all fabric comes back |
| Stock with vendors | Filter by vendor/job names; inspect issued/closed/outstanding fabric | During custody and reconciliation; receive from a challan link |
| Contractor bills | Select an accepted receipt and match unbilled service quantities | When the contractor invoice arrives |
| Payable sources | View original posted contractor obligations | After posting the contractor bill; this is not settlement/payment entry |
| Reports | Filter posted receipt output, loss and charge history by date | Operational review |

### Prerequisites

1. Company settings must exist. The document date must fall within an open
   financial year and an open accounting period.
2. In **Textile master data / Textile items**, create actual input/output fabric
   SKUs. Item categories group those records; categories cannot be dispatched.
   Use M for dyeing and stock-managed fabric. Enable roll tracking only when
   physical roll identity must be maintained.
3. In **Vendors & rates**, assign the appropriate service to the shared party's
   Vendor role and publish a PKR rate covering the job's rate-effective date.
4. In **Warehouses**, provide active company receiving locations and active
   contractor locations linked to the correct Vendor role.
5. Define a route of any valid service sequence, review and publish it. Every
   preceding output item must match the next stage's input item. Yield 1 means
   planned output equals input; normal loss fraction 0.05 means a 5% allowance.
6. Record genuine warehouse stock through Purchasing receipts or the existing
   auditable opening-stock document, inspect it and release its quality.
   Existing stock already with vendors needs the separately planned go-live
   workflow; this follow-up does not create invented purchases or dispatches.

The overview checks are setup hints for today's records, rather than approval of
any particular vendor/job/date. Save/post services validate the actual document.

### Daily flow and example

**Job -> Dispatch -> Receipt -> Contractor bill -> Payable source.**

- Open Jobs, choose **New job**, select a published route and **Load details**.
  Enter planned input and each stage's vendor capability/rate date. An authorized
  negotiator can enter a rate override with a reason. Save, review and approve.
- From the approved job choose **Dispatch stage N**. The vendor is inferred from
  that stage. Only matching internal, released, unexpired, available stock is
  offered. Later stages are limited to this job's eligible preceding output or
  that stage's rejected returns. Select stock, custody location and quantity,
  save, review and post.
- From the posted dispatch choose **Receive fabric from this dispatch**. The
  job/vendor are inferred. Choose an outstanding dispatch line, receiving location,
  disposition and new lot/roll references. Enter actual measured quantities.
- Example: close 60 M of an outstanding 100 M, receive 57 M and record normal loss
  3 M, abnormal loss 0 M. The equation is **60 = 57 + 3 + 0**; 40 M stays with the
  vendor. At the saved PKR 2/M rate the estimated charge is PKR 114. The route
  must allow the measured normal loss. The server calculates the definitive charge.
- Pending returned quality must be released before another dispatch. Rejected
  returns preserve the input item and have no processing charge/loss. PCS/set
  billing uses a measured service count and inspection evidence; it does not
  convert stock meters into pieces implicitly.
- From an accepted posted receipt choose **Create contractor bill**. The screen
  offers only unbilled quantities and uses the frozen job rate. Enter the real,
  unique contractor invoice reference/date and due date, save, review and post.
- Open **Payable sources** to view the original obligation. Payment and settlement
  workflows are still future scope.

Partial receipts and partial bills refresh remaining quantities from posted
history. Fully received challans and fully billed services show a clear empty
state instead of an actionable line. Drafts do not consume the displayed limits;
concurrent changes are checked again inside the posting transaction.

### Whole-roll and correction limits

A tracked physical roll cannot be divided across two locations. The dispatch form
shows **Use full roll quantity** and the available roll size. A roll unavailable
in full is not offered. Do not change the item master, invent a roll or edit stock
to work around this rule. A separately authorized roll-split workflow is needed
for a genuine pre-dispatch cut; it is not implemented here. Partial processing
receipts remain supported through the existing new-output-roll/lineage mechanism.

Saved processing drafts have no edit screen. Cancel and recreate job/challan/
receipt drafts as needed; posted documents remain immutable. Contractor invoice
references stay reserved across drafts and cancellations. Review those references
before saving; do not rename a real invoice to bypass duplicate protection.

## Implementation and completion report

### 1. Features delivered

- Collapsible, permission-aware Processing sidebar category with focused screens.
- Setup checklist, source-first forms, contextual help and direct next actions.
- Read-only `ProcessingWorkspace` derives stock choices from quantity movements
  and reservations, scopes dispatch/receipt/bill sources and uses frozen rates.
- Separate typed URLs preserve list scope even if a query/body attempts another
  document kind. Invalid cross-job/source context returns 404. Existing URLs remain.
- Alpine line editing, quantity/custody/charge previews, loss/whole-roll and combined
  duplicate-source warnings. Client estimates never authorize or calculate postings.
- Existing service authorization, company/document locks, UUID idempotency and
  journal/stock posting remain authoritative. A clearer whole-roll posting error
  enforces the same existing invariant before any stock mutation.

### 2. Files created and modified

New application files:

- `app/Modules/Processing/Services/ProcessingWorkspace.php`
- `app/Modules/Processing/Resources/views/overview.blade.php`
- `app/Modules/Processing/Resources/views/payables.blade.php`
- `app/Modules/Processing/Resources/views/types.blade.php`
- `resources/views/components/erp/processing-navigation.blade.php`
- `resources/js/processing.js`

Modified application files:

- `app/Modules/Core/Resources/views/layouts/app.blade.php`
- `app/Modules/Processing/Http/Controllers/ProcessingController.php`
- `app/Modules/Processing/Http/Requests/ProcessingRequest.php`
- `app/Modules/Processing/Routes/web.php`
- `app/Modules/Processing/Services/ProcessingService.php`
- `app/Modules/Processing/Resources/views/{nav,index,form,line,show,routes,custody,reports}.blade.php`
- `resources/js/app.js`

Tests: new `tests/Feature/ProcessingWorkspaceTest.php`,
`tests/Frontend/processing.test.mjs` and `tests/Frontend/processing-browser.mjs`.
The optional browser fixture export is enabled only in tests via
`PROCESSING_BROWSER_FIXTURES=1`; HTML/screenshots/profiles are disposable artifacts
under ignored `storage/framework/testing/processing-browser`, using synthetic test
records. The browser smoke runner never submits forms or connects to the app DB.
Documentation: this guide, README, PROJECT_STATE, PROCESSING, PHASE_8_FILES, PRD,
PROJECT_OVERVIEW, ARCHITECTURE, DEVELOPMENT_PHASES, BUSINESS_RULES, CHANGELOG,
TESTING and DEPLOYMENT. Generated Vite assets are ignored build outputs.

### 3. Tables and migrations

**None.** The 78 implemented application tables, FK/CHECK/unique constraints,
configuration seeders and permission set remain unchanged. No application DB
migration/reset, business-data seeding, `.env` edit or password change was run.

### 4. Routes and screens

Nineteen added routes:

- `GET /processing` (overview), `GET /processing/types` (service types),
  `GET /processing/payables` (original contractor payable sources).
- For each of `/processing/jobs`, `/processing/dispatches`, `/processing/receipts`
  and `/processing/bills`: GET list, GET `/create`, POST save draft and GET
  `/{document}` review/details (16 routes).

There are 39 routes under `/processing`, including eight existing Parties-owned
vendor/rate routes. Existing generic document, action, print, route, report and
custody URLs continue to work. No new permission is introduced. New lists/details
require `processing.documents.view`; create/save also require `.manage`.
Posting still uses existing per-document permissions and policies/services.
Vendor and rate links use the independent `processing.vendor.view/manage` grants.

### 5. Checks and results

Full regression: **120 tests / 726 assertions**, including all nine race tests.
Final focused follow-up: **10 tests / 103 assertions**. Node: **8 passed**.
Final Edge browser desktop/mobile smoke, Pint, Vite build, route cache/list/clear
and lint of **116** compiled Blade views passed. No application DB change was run.
Detailed validation is recorded in [TESTING.md](TESTING.md) and
[PROJECT_STATE.md](PROJECT_STATE.md). Browser estimates have independent Node
checks; Laravel HTTP/service tests cover remaining quantities, workflow links,
stock eligibility, permission denial, context tampering and immutable posting.

### 6. Known limits

Exact MySQL 8.4 validation and operator acceptance on the deployment browser remain
outstanding. Local database testing uses MariaDB 10.4.32. Client previews use
JavaScript numeric estimates; authoritative amounts use server decimal arithmetic.
Existing one-vendor-per-stage, positive receipt output, no pre-dispatch roll split,
no draft edit/posted processing reversal, no payments and go-live migration limits
are preserved. Separate typed screens reuse shared Blade/controller conventions.

### 7. Updated documentation

The operating guide and project/reference documents named above describe current
navigation, prerequisite checks, changed URLs and unchanged posting boundaries.
Architecture beyond Phase 8 remains a design specification.

### 8. Suggested Git commit

`feat: add guided processing workspace and separate workflow screens`

### 9. Readiness

Ready for review and Phase 8 UX approval after the recorded checks; target MySQL, deployment browser and
operator/accounting acceptance remain production acceptance steps. This is a
Phase 8 interface follow-up. Stop here; no later phase is started.

## Review checklist

- [x] Separate navigation/screens and explicit source/details/review guidance.
- [x] Shared-party vendor ownership and existing permissions preserved.
- [x] Eligible stage stock, matched custody and unbilled service quantities.
- [x] Frozen server rate/cost/journal calculations; previews are estimates only.
- [x] Whole-roll restrictions and cancellation/reference limits explained.
- [x] Full regression, final focused tests, browser/Node/build/format/cache checks.
- [x] No schema change, opening-stock import, roll split or later module added.
- [ ] Target MySQL 8.4 validation and live operator/accountant/printing acceptance.
