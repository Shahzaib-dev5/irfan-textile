# Phase 8: Processing and contractor management

## Processing follow-up ? 2026-10-27

The authorized [Fabric Out / Fabric In workflow](PROCESSING_FABRIC_FLOW.md) adds automatic
processed output identities, laat/component evidence, per-suit embroidery contracts
and atomic direct vendor handovers. Daily entry no longer requires manual routes
or jobs; historical and Production-linked workflows remain available. One additive
flow-history table and one service configuration preserve existing stock, journals
and permissions. Physical base-unit quantities remain authoritative; component
counts do not automatically create finished suits. Earlier Processing workflow
requirements below are historical where superseded by that guide.


## Phase 9 integration update (2026-10-26)

Jobs may now carry an optional manufacturing batch FK when created from a batch.
Selection is limited to an open confirmed batch, appropriate component/current
WIP route items and authorized users. Existing configurable route/job/dispatch/
receipt/rate/custody services own all stock and contractor effects. A completed
batch rejects further linked execution; existing matched contractor bills retain
their own authorized flow. Legacy unlinked job hashes remain compatible. See
[PRODUCTION.md](PRODUCTION.md). Earlier Phase 8-only scope statements are historical.

## Current simplified admin flow (2026-10-22)

The admin now chooses **Confirm** directly on a completed form. Save for later is
optional. Purchase order submission/approval is performed inside one operation
when the user has both permissions. Saved orders can also be confirmed once.
The existing transaction/stock/rate/custody rules and restricted-user permissions
remain. Every transaction has Download PDF and a clean Print document view.
See [SIMPLE_ADMIN_FLOW.md](SIMPLE_ADMIN_FLOW.md) for current instructions and the
completion report; earlier multi-screen draft instructions below are historical.

Implemented scope: configurable service types and versioned routes, immutable jobs,
per-stage contractor assignments and rate snapshots, dispatch challans, partial
accepted/rejected returns, normal/abnormal loss, roll lineage, custody inquiries,
receipt reports, exact-rate contractor bills and payable sources. No Production,
finished-suit consumption, sales, payments or tax implementation is included.

## Guided workspace (2026-10-22)

Open **Processing > Overview / Start here**. The sidebar provides separate service
types, route, job, dispatch, receipt, stock-with-vendors, bill, payable-source and
report screens. [PROCESSING_UX.md](PROCESSING_UX.md) explains prerequisites, field
meanings, direct next actions and the Phase 8 UX completion report. These changes
add no migrations and preserve the posting rules below.

## Operator flow

1. In Processing vendors, assign the shared Vendor role, add service capabilities
   and publish effective-dated PKR rates. A Supplier may use the same party identity.
2. In Warehouses, create an active contractor location linked to that Vendor role.
   Create an internal receiving location as well. Release inspected source lots.
3. Open Processing > Processing routes. Enter a route code/name and stage rows in the
   desired order. Each stage specifies a service, input item, output item, planned
   yield ratio and normal loss fraction (0.05 means 5%). Add Dan and Dyeing as two
   stages, or Combined Dan/Dyeing as one. Services may recur in any valid sequence.
4. Save the route draft and publish it. Every preceding output must match the next
   input. Use the same code to create another version when the route changes.
5. Create a job, load the published route and enter planned input quantity. Assign
   a matching vendor capability to every stage. Different stages may use different
   vendors. The effective rate date selects the historical contract. A permitted
   negotiator may supply another rate with an approval reason. Save, review and
   approve the job; approval moves no stock and creates no payable.
6. From the approved job, choose Dispatch stage N. Select its stage, matching input stock,
   contractor location and quantity. Save, review and post the challan. A tracked
   physical roll must move in full; use separate lines for distinct rolls.
7. Receive / return fabric against the original dispatch line. Enter the issued
   quantity being closed, actual returned meters, normal loss and abnormal loss.
   Their equation must balance. Choose accepted processing or rejected unchanged
   fabric, an internal location, a new lot and (when tracked) a new roll number.
   Pending quality is the default; release inspected stock before the next stage.
8. Check Processing > Stock with vendors. Partial receipts leave the remainder with the vendor.
   Later-stage dispatches may use accepted output of the previous stage of
   this job or unchanged rejected returns from that same stage. Rejected fabric is returned as the original input without a charge.
9. Create a Contractor bill against accepted receipt quantities. The frozen job
   rate sets the amount. Review and post to clear the processing accrual and create
   the payable source. Review original obligations in Processing > Payable sources.

Every business date needs an open financial year and open period. Processing
navigation and actions require their corresponding permissions. Existing vendor
rate publication screens require processing.vendor.view/manage separately.

## Posting, quantities and costing

All writes lock the company singleton first, then the affected document, vendor
capability/rate and stock layers within a retried transaction. This deliberately
serializes inventory writers for a single company. Client UUIDs prevent duplicate
creation/posting; stale lock versions fail; posted typed details cannot be edited.
Numbering uses the shared document type/year sequence and the established
PREFIX-yearID-counter convention. A SHA-256 digest freezes posted typed details.

Dispatch splits a requested quantity over its selected FIFO acquisition layers.
Each dispatch line creates an equal-quantity, equal-value contractor receipt layer.
The company retains ownership; contractor storage never implies a purchase/sale.
A receipt consumes only the layer created by its own dispatch line, even when jobs
share the same lot/location/roll position. Generic inventory issues cannot drain
these layers. Outstanding = posted dispatched quantity minus posted receipt source
quantities. The custody report compares that result with the layer remainder.

Physical input/output for these fabric stages must use the same base unit. Dyeing
and combined Dan/Dyeing require M for physical quantities and billing. Same-unit
services bill actual accepted output. Other compatible units require the item's
configured conversion. Embroidery billed in PCS needs an explicit inspected piece
count and documentary evidence in receipt remarks; meters remain the stock unit.
There is no invented meter-to-piece conversion. Billing precision follows units.

Receipt equation:
`source_quantity = output_quantity + normal_loss_quantity + abnormal_loss_quantity`.
Normal loss cannot exceed the frozen route fraction of source quantity. Excess
must be classified as abnormal loss. Rejected returns have no loss or charge.

Input FIFO cost is allocated proportionally to abnormal loss; normal loss is
absorbed by usable output. Output cost = input cost - abnormal loss cost + frozen
processing charge. Fractional cost links sum to one, preserving source genealogy.
New tracked output rolls link to their parent via fabric_roll_lineage; each
physical roll remains positive at at most one location.

Example: issue 100 M at PKR 10/M, receive 95 M, normal loss 3 M, abnormal loss 2 M,
and agreed service rate PKR 2/M. Charge = PKR 190; abnormal expense = PKR 20;
output carrying value = PKR 1,170 (PKR 12.31578947/M before display rounding).
Receipt journal: Dr inventory 170, Dr abnormal processing loss 20, Cr accrual 190.
The matched bill then Dr accrual 190 / Cr party payable 190. Dispatch itself has
no journal because ownership/value are conserved. No duplicate capitalization.

Partial bills cannot exceed accepted service quantity. The final match takes the
remaining four-place accrual residual. Bill references are normalized and checked
across all roles of the shared party, including drafts/cancelled records. One
journal sidecar and one open item per party control journal line are reused.

## Migration and implemented tables

`2026_10_21_000004_create_processing_tables.php` adds ten approved tables:

- processing_route_versions, processing_route_steps
- processing_jobs, processing_job_steps
- processing_dispatches, processing_dispatch_lines
- processing_receipts, processing_receipt_lines
- fabric_roll_lineage
- purchase_invoice_processing_matches

There are 78 application tables after migration, excluding migration bookkeeping.
Foreign keys restrict deletion/update; quantities use DECIMAL(20,6), rates
DECIMAL(20,8), PKR DECIMAL(20,4), ratios/fractions DECIMAL(20,10). Unique document
line numbers, route versions/stages, movement references and match keys prevent
structural duplicates. CHECKs constrain positive quantities, loss equations,
rejected returns, supported bill kinds and nonnegative amounts.

The existing nullable purchase_invoice_lines.processing_job_step_id now receives
its real FK. The Phase 7 stock-only CHECK is replaced with the approved stock OR
processing subset, preserving existing stock records. Expense/tax/rounding remain
blocked. No column/data removal, stock rewrite or independent contractor identity
is introduced. DDL is forward-only and not transactionally reversible on MySQL;
back up and rehearse upgrades on a disposable copy before live installation.

## Installation

Use the repository directory and its compatible PHP runtime, then normal additive
`php artisan migrate`, `php artisan db:seed`, `npm run build` and
`php artisan optimize:clear`. Do not run migrate:fresh on the application database.
Configuration adds four processing document types, eight permissions and account
purposes processing_accrual (2120) and abnormal_processing_loss (5120), reusing the
existing inventory and supplier_payable mappings. Seeders add no fabric/jobs,
opening quantities, users or passwords. Existing super-admin receives permissions;
assign other roles deliberately and confirm posting accounts before operation.

For this Windows checkout, the verified isolated PHP runtime can be used from
the repository folder:

```powershell
$erpPhp = Join-Path $env:TEMP 'irfan-textile-phase2-php83-retry/php.exe'
& $erpPhp artisan migrate
& $erpPhp artisan db:seed
```

Confirm the intended connection and backup before installation. These are normal
additive installation commands, not commands run against your application during
this implementation. Assets have already been built in this workspace.

## Explicit boundaries and correction policy

- One vendor is assigned per route stage per job, matching the approved unique
  job/route-step aggregate. Split parallel vendor work into separate jobs; vendors
  can differ across sequential stages. No parallel route branches are modeled.
- Fabric stages retain the same physical base unit. Cross-dimension manufacturing
  belongs to Production. Every receipt needs positive usable returned output;
  a total-loss-only custody closure is not enabled by this approved receipt schema.
- Receipts return through internal storage before the next contractor dispatch.
  Direct contractor-to-contractor transport is not a separate screen in this phase.
- Drafts can be cancelled with a reason, then recreated. Published routes and
  posted jobs, dispatches, receipts and bills are immutable. No posted processing
  reversal UI is provided; never edit/delete history or use generic inventory
  adjustments to bypass custody. Operational correction needs a separately
  reviewed compensating-document workflow before such live corrections are used.
- Negotiated rates are authorized on a newly created job and audited with source
  contract/rate/reason; posted snapshots never change. Vendor rate successors must
  not invalidate any job's source period. Reassignment requires another job.
- Contractor bills are exact-accrual matches, with no tax, additional price
  variance, late-cost allocation, supplier credits, payments or settlements.
  processing_charge_allocations remains approved design only until the associated
  late-cost engine is implemented. Original AP sources are not settlement balances.
- Local automated validation uses MariaDB 10.4.32. Exact MySQL 8.4 validation and
  interactive target-browser/printing acceptance remain deployment requirements.

The current preparation screens save immutable-to-the-user draft versions;
there is no draft-edit screen. Cancel/recreate job/challan/receipt drafts when
needed. Bill references remain reserved across drafts and cancelled history, as
in Purchasing; review contractor invoice references carefully before saving.
A correction to a reserved contractor bill reference requires a separately
reviewed amendment workflow. Do not rename a real supplier invoice to bypass
duplicate protection.

## Review checklist

- [x] Shared Vendor roles; no duplicate party masters.
- [x] Configurable combined/separate routes with continuity checks.
- [x] Frozen job contracts, authorized overrides, meters/PKR for dyeing.
- [x] Company mutex, FIFO dispatch layers, job-specific custody and bounded returns.
- [x] Loss equations, proportional abnormal expense and conserved roll genealogy.
- [x] One receipt accrual and one matched contractor payable journal.
- [x] Permission middleware, policies, service-level authorization and audit events.
- [ ] Exact target MySQL 8.4 upgrade/full-suite rehearsal.
- [ ] Accountant acceptance of purposes and explicit correction/total-loss limits.
- [ ] Interactive browser/mobile/challan print acceptance on deployment.

Original Phase 8 validation before the UX follow-up: 110 PHPUnit tests passed with 625 assertions, including all
nine independent-connection race tests. Pint, Composer validation/platform
requirements, Vite build, route/view caches and 112 compiled-template PHP syntax
checks passed. Exact details are in PROJECT_STATE.md and TESTING.md.
Stop after Phase 8.
