# Business rules and integrity contracts

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


## Phase 12 active finance rules (2026-10-26)

Payments and receipts are now implemented. They post once to party controls and
configured cash/bank accounts, with optional atomic allocations. Outstanding is the
original immutable open amount minus effective allocations, never an editable field.
Manual journals cannot bypass control accounts. Cash journals require configured
bank/cash plus evidence; stock openings remain in Inventory. Allocations match party,
account and polarity and cannot exceed either side. Reversals unwind allocations,
post exact opposite lines and retain all originals. Finance cannot void operational
stock sources. Bank statements reconcile to actual book lines; no unexplained plugs
or backdated entries into reconciled dates. Tax/payroll remain deferred.
See [ACCOUNTING_FINANCE.md](ACCOUNTING_FINANCE.md).


## Phase 11 active Wholesale rules (2026-10-26)
Only active shared Customer roles may enter Sales. Zero credit limit means no
credit, hold blocks new exposure/reservation/dispatch, and no credit/stock override
is enabled. Order may backorder; dispatch may never exceed available stock or
remaining order balance. Reservations reference actual order lines; expiry requires
explicit release. Dispatch freezes net revenue, invoice exactly matches unbilled
dispatch quantities/value. Physical return restores original cost without reducing
AR; matching bounded credit handles commercial correction. Returns do not reopen
order fulfillment. Close remaining commitment with audit/release; never delete
posted history. Tax, payments and settlements are deferred. See [SALES.md](SALES.md).

## Phase 10 enforced finished goods rules (2026-10-26)

1. Existing posted Production output is suit stock. Batch completion reuses its
   final-report checks; Finished Goods never receives those suits a second time.
2. Published PACK specifications list exact SUIT content and real packing supplies.
   No M/SUIT/PACK conversion factor or editable item/container stock quantity.
3. Packing consumes each required component exactly per received PACK unit and
   receives output lots once. Containers fully cover output and actual source
   attribution; posted package facts and specification versions are immutable.
4. Per-output packing cost follows actual PACK quantity shares, four-place money
   rounding and deterministic residuals. Shared distribution reconciles every
   source and destination cost exactly; different physical units are not summed.
5. Full recovery requires the proven original spec, exact recovered components,
   total cost shares 1 and documented allocation basis. Partial-loss recovery and
   nested/post-sales repacking are rejected. Held/rejected recovery cannot become
   released stock without separate inspection.
6. Original acquisition cost is separate from current remaining stock value.
   Signed originals/reversals remain in the ledger; later rates/recipes/stock
   issues cannot rewrite old costs. Missing actual costs are never invented.
7. Rejects retain their own valued quantities and cannot be picked. Actual disposal
   delegates to Inventory damage/FIFO/journal services with separate permissions.
8. Only internal company stock is consumed; output goes to active internal or
   quarantine receiving storage. Shared mutex/transactions/keys prevent duplicates.
9. Finished Goods view/manage/confirm/quality permissions protect both UI/backend;
   Wholesale-only users stay outside internal manufacturing/costing workflows.

See [FINISHED_GOODS_COSTING.md](FINISHED_GOODS_COSTING.md).

## Phase 9 enforced manufacturing rules (2026-10-26)

1. Publish an active SUIT variant recipe before confirming a production plan.
   Each component specifies base-unit net quantity and planned loss fraction <1.
   Recipes cannot consume themselves or form recursive component graphs.
2. Batch confirmation freezes net/gross material requirements and design/color
   identity through the immutable BOM. It creates no inventory or reservation.
3. Ordinary production reports require real released internal company inputs,
   positive actual quantities, compatible same-unit substitutions and explicit
   authorization/reasons for extra or over-plan consumption. Contractor custody
   must return through Processing before internal consumption.
4. Wastage is a subset of consumed input; never issue it a second time. Normal
   cost is absorbed; abnormal cost is expensed. Recoverable scrap is an output.
5. Cumulative finished and retained rejected output cannot exceed the plan and
   must be backed by cumulative BOM net input after measured loss. Same-unit
   intermediates reconcile physical quantities. Different dimensions are not summed.
6. Intermediate WIP cannot satisfy the same frozen raw-material requirement
   twice. It must be consumed as authorized extra/intermediate input. Another
   batch's WIP is rejected at consumption and batch-linked dispatch, including
   through transfer/processing ancestry; no cross-batch reallocation is offered.
7. Cost shares total exactly one. Actual FIFO costs and direct cost allocations
   preserve rounded source and destination totals. Already carried contractor
   charges are not added again. Only eligible existing posted source costs can
   be absorbed, within their remaining debit capacity.
8. Final completion requires whole planned output accounted, no intermediate WIP
   (including downstream transfer/processing layers), and no linked outstanding
   custody. A closure-only report records no further stock or money. Completed
   batches reject further production and linked processing execution.
9. Posted history is immutable. Company locks, request keys and payload hashes
   prevent duplicate conversion and competing final reports. Wholesale-only
   users remain denied through routes, services and navigation.

See [PRODUCTION.md](PRODUCTION.md).

## Simple admin confirmation and PDFs (2026-10-22)

Direct Confirm requires both preparation and document-kind finalization permission. Purchase order submission/approval is orchestrated atomically; existing approval events are retained. Failed posting rolls back preparation and side effects. A deterministic confirmation key plus the original input hash prevents duplicate new-create effects. Stored states remain draft/posted/cancelled, with plain UI labels. Printing/downloading never posts. Wholesale-only accounts cannot access operational documents without their module permission.
See [SIMPLE_ADMIN_FLOW.md](SIMPLE_ADMIN_FLOW.md).

## Guided Textile master follow-up (2026-10-22)

The simplified item form does not change master rules: fabric M/stocked with
composition, finished SUIT/stocked, service nonstock/no rolls, same-dimension exact
conversions and whole physical rolls. Fabric specification/conversion/variant
references freeze type/unit/tracking even before stock; purchase lines/stock lots
also protect SKU/specification. The UI displays these locks and uses persisted
values on protected edits. Category labels are groups, never stock identities.
Master entry creates no quantity; posted snapshots are unaffected by conversion
factor maintenance. See [TEXTILE_MASTER_UX.md](TEXTILE_MASTER_UX.md).

## Guided Purchasing follow-up (2026-10-22)

Purchasing screen separation does not change posting rules. New receipt context scopes supplier/warehouse/order choices; bills exclude already billed or returned quantities; returns select original available lot/roll stock. Preserve multi-source preparation and editable drafts. Recheck all choices inside existing posting transactions. Payable links use document IDs, not journal IDs.
See [PURCHASING_UX.md](PURCHASING_UX.md).


## Phase 8 guided workspace (2026-10-22)

Processing screen choices are guidance, not authorization. Dispatch choices must match the selected stage and eligible internal stock; receipt choices retain their original dispatch; bill choices include only unbilled accepted services. Recheck limits on posting. Whole tracked rolls move together. UI estimates never override decimal ledger/journal values.
See [PROCESSING_UX.md](PROCESSING_UX.md).

> Phase 8 implementation (2026-10-21): configurable processing routes/jobs,
> contractor dispatch/custody, partial accepted/rejected returns, loss costing,
> roll lineage and exact-rate contractor bill/AP sources are implemented.
> See [PROCESSING.md](PROCESSING.md) for actual behavior and explicit boundaries.
> Production/sales/payments/tax remain future scope; MySQL 8.4 validation is pending.

Status: Phase 1 baseline with the explicitly documented Phase 3-8 setup, party,
catalog, inventory, purchasing and processing rules implemented. Rules outside
those subsets remain requirements for later authorized phases.

## BR01 — Identity, roles and authorization

One legal counterparty has one `parties` row. Supplier, vendor and customer roles
can coexist. A transaction selects the correct role/profile while accounting
retains the shared legal party. Creating a profile requires the corresponding
role code; deactivation prevents new commitments but permits authorized settlement
and returns against existing work. No merging identities with posted history
without a reviewed reconciliation procedure.

Role/profile changes lock the party. Authorization roles are distinct from party
roles. Create, edit, post, reverse, rate override, period close and user
administration are separate permissions. Revalidate permission and active actor
at posting, not only when a form first loads.

### Phase 3 enforcement

- Sign-in requires a matching active user; every authenticated request checks
  the user is still active and invalidates that session if the account was
  deactivated. Deactivation and password reset clear database-backed sessions.
  Login and password-reset requests are throttled.
- Authorization uses `access_roles`, `permissions`, `user_roles` and
  `role_permissions`; these never reuse `party_roles`. Blade hides unauthorized
  setup links; middleware returns 403 for direct requests. Policies and Form
  Requests repeat authorization at the action boundary.
- Delegated role managers may only grant permissions they already hold and may
  only assign roles whose permissions are a subset of their own. Only a system
  administrator can grant that role; it cannot be disabled or stripped of its
  registered permissions. At least one active system administrator is retained.
- User/role assignments, password resets, company settings and financial
  calendar changes create redacted append-only audit events inside the same
  database transaction. Passwords, reset tokens and session payloads are never
  audit snapshots.
- Calendar changes serialize on the singleton `companies` row. Financial-year
  and period ranges cannot overlap; periods must be within their year. Closing a
  year requires contiguous periods covering the full date range, all closed.
  Reopening is an authorized and audited configuration change. Posting services
  must still recheck the open period while locked in a later accounting phase.

### Phase 4 enforcement

- `parties` is the sole counterparty identity. Role creation locks that party and
  creates the role and its one-to-one role profile in a transaction. Existing
  eligible parties can receive another business role; no second party is made.
- Party addresses and contacts belong to the shared identity. UI omissions
  deactivate rows and retain audit history; no party, role, address or contact
  hard-delete route exists. Inactive roles remain searchable and can be edited
  or reactivated by authorized users.
- Supplier, vendor and customer management require independent module
  permissions. Route middleware and Form Requests enforce backend authorization.
- Vendor processing services are data records. The static configuration seeder
  adds Dyeing, Dan, Combined Dan/Dyeing, Embroidery, Pressing, Calendaring and
  Finishing. Fabric-length services default to meters; Embroidery defaults to
  pieces/sets. These are initial commercial assumptions, and future approved
  catalog maintenance can add services without schema changes.
- Rate intervals are `[effective_from,effective_to)`. Publishing locks the
  `vendor_services` row, checks every interval for overlap and requires the unit
  to match the service. A later successor can close an open interval
  prospectively; historical rates are append-only, reasoned, approved and PKR.
  Future processing jobs must snapshot selected rate ID, unit, rate and currency.
  Existing job-date invalidation checks are deferred until processing jobs exist.

## BR02 — Quantities, units and precision

### Phase 5 enforcement

Fabric items use M and require composition; optional width/GSM must be positive.
Complete finished textile variants use stocked whole SUIT units and a positive
component piece count. Materials/consumables use trim/category classification;
collections use the approved design-season descriptor. No stock quantity can be
entered through a master request.

Units have stable codes/dimensions/precision. Conversions are item-specific,
positive exact decimals between compatible dimensions. Roll length and component
pieces cannot be used to manufacture a fixed meter-to-suit conversion. BCMath
conversion checks both input precision and base precision without silent loss.
Whole-suit SET factors must be integers. Category cycles are rejected under the
company-row mutex. Referenced item measurement definitions are protected;
Inventory must extend this protection to stock usage and posting snapshots.
See TEXTILE_MASTER_DATA.md for lifecycle, constraints and module boundaries.

Entered quantity must be positive on ordinary source lines; zero is allowed only
for explicitly optional planning/cost fields. Physical ledger quantity is signed
and nonzero. Returns/reversals are distinct documents with positive business
quantities and inverse ledger effects. Count quantities for suits and pieces
must be integral; meter/kg quantities obey the unit's precision.

Convert entered quantities once to canonical item base units using a stored
factor snapshot. Reject loss of precision or incompatible dimensions. A roll has
an individually measured length. A fabric-to-suit conversion is actual BOM
consumption/output, not a global unit factor.

Use DECIMAL and decimal arithmetic end to end. Quantity scale is six, rates eight,
conversion factors ten, PKR ledger values four. Invoice/payment/tax presentation
is two decimal places; invoice line amounts are rounded explicitly to two, then
summed. Inventory values retain four; cost allocation final remainder goes to a
deterministically selected last line. Journals balance exactly at stored scale;
an explicitly bounded rounding line is used where a two-decimal commercial amount
settles a four-decimal accrual. Do not hide discrepancies behind a large tolerance.
The maximum permitted rounding discrepancy is a configurable accounting policy
validated per calculation, not arbitrary journal imbalance.

## BR03 — Effective history and configurable processing

Rates, employment terms and applicable component history use inclusive start and
exclusive end. End NULL means infinity. For candidate `[s,e)`, an existing interval
overlaps when `(existing.end IS NULL OR s < existing.end)` and
`(e IS NULL OR existing.start < e)`. Lock the stable owning service/tax/employee
row **before** this query and write; unique start dates alone cannot prevent
overlap.

Closing an open-ended interval prospectively is allowed under audit if no
historical effective attribution is invalidated. Monetary/basis fields of used
history cannot be rewritten. Posted job steps and payroll lines retain snapshots.
Processing route versions are immutable once selected by a posted job. Repeated
services, additional finishing and rework use explicit steps; arbitrary loops
are not inferred from a fixed stage enum. New work may use a successor version.

## BR04 — Document state, numbering and idempotency

Drafts are mutable with optimistic version checks. Cancelled drafts have no
posting number or ledger effects. Posting locks the root, revalidates all lines,
period, source capacity, permissions, totals and required role/profile, then
allocates a sequence number and creates every required physical/financial record
atomically. Posted documents are immutable except for audited lifecycle metadata.

Format is `<type prefix>/<financial year code>/<zero-padded counter>` with a
64-character bound. The future validator ensures allowed prefix/year characters
cannot introduce separators or exceed the bound. Sequences are per type/year,
numbers globally unique. Draft display uses internal ID, never a counterfeit
official number. A cancelled draft is not an issued invoice.

Create and post use unique request UUIDs. The post also stores a canonical
payload hash. Retrying the same key/hash returns the already-posted result;
reusing a key with different payload fails. A failure rolls back number, ledger,
journal, audit transition and balances together. Unique ledger sequence keys
protect duplicate effects even if a client retries after a lost response.

## BR05 — Posting transaction and lock order

Use explicit InnoDB transactions and a consistent resource ordering:

1. Lock affected accounting periods in ID order, then document roots in ID order.
   Period close takes the same period lock and prevents new accepted postings.
2. Lock shared party/profile, source commitment/job/batch, and master configuration
   parents needed for invariants in a documented table-rank then ID order.
3. Lock sequence, stock lot/roll/position/balance/reservation and cost-layer
   resources in stable resource-rank then ID order; create missing dimension rows
   with unique-key conflict handling before consuming quantity.
4. Lock open items and other financial allocation targets in ID order. Verify
   aggregate limits after acquiring the locks, not from a previous unlocked read.
5. Insert immutable effects, update projections and lifecycle, and commit.

Implementation must refine this into one shared lock-order contract across all
posting/reversal/close services. It must retry whole transactions on deadlock or
retryable lock timeout with the same idempotency key and bounded attempts. Never
use SKIP LOCKED to treat temporarily locked stock as absent or to bypass an
accounting validation. Balance reads for posting are current locking reads.
Optional financial-year/company calendar edits must obey the same outer rank;
they cannot lock company after period while another path does the reverse.

## BR06 — Stock-ledger integrity

Stock position is the unique lot/location/optional roll dimension. Its item's
identity and base unit derive from the lot; do not duplicate them without a
consistency constraint. Normalize nullable roll identity for unique position
keys. The roll, if present, must belong to the lot. The location belongs to one
warehouse. A roll cannot simultaneously hold positive stock at incompatible
locations; dispatch/cut/split updates lock its identity.

`stock_movements` is authoritative quantity history. On-hand = sum of all signed
posted movements including compensations; never filter out originals marked
reversed. Available = on-hand minus active reservations, with reservations
released/consumed under the same lock. Rebuilding stock_balances must reproduce
the ledger exactly. No manual writes to cached balances.

An intra-company transfer posts equal canonical quantity out/in; through-transit
transfers post issue-to-transit and transit-to-destination separately. Quantity
differences require approved loss/adjustment entries. A transform changes lot/item
through input consumption and actual output; it must explain retained inputs,
accepted output, normal shrinkage, abnormal waste and by-products by applicable
measurement, not assume meters equal suits.

Counts, openings, writeoffs and corrections are numbered inventory adjustments
with reasons and journals where value changes. Opening stock has lots/layers and
opening-equity accounting, not direct balance seed data.

## BR07 — Contractor custody and ownership

Contractor dispatch moves company-owned stock from an internal/transit location
to a location identifying the vendor role. A challan is evidence of custody;
it is neither a sale nor a contractor invoice. Company inventory remains an
asset while outside. Inter-contractor movement records both custody endpoints
and authorization.

A processing receipt reconciles a specific job step and dispatched inputs.
Partial and multiple returns cannot exceed net unreturned custody. Accepted
processed output has traceable new lots/roll lineage where identity changes.
Unprocessed returns preserve original identity. Normal shrinkage and abnormal
loss are explicit, authorized and costed differently. Step closure is blocked
while unexplained contractor stock remains. Reports reconcile ledger custody
with open job dispatch/return quantities.

Customer-owned fabric is outside this approved ownership model. Adding it later
requires an owner dimension and distinct recognition rules; never misuse a
vendor custody column to imply ownership.

## BR08 — Purchasing and contractor billing

PO approval commits quantity/rate but does not create stock or AP. Goods receipt
accepts measured stock, creates cost layers and debits inventory/credits GRNI.
Rejected goods remain outside accepted ownership or in an explicitly approved
quarantine process; disposition is recorded, not silently subtracted.

Supplier invoice matches receipt lines under source-line locks. Cumulative net
invoiced quantity cannot exceed accepted net receipt quantity unless an explicit
approved unmatched service/expense basis applies. Service lines use vendor role
and accepted processing quantities. Same-party, currency, item and source
membership are validated.

At processing acceptance, eligible service cost is accrued from the job's
historical quantity/rate snapshot and incorporated into resulting stock/WIP.
Invoice clears the accrued contractor liability, posts recoverable/nonrecoverable
tax appropriately, and capitalizes or expenses **only the difference** using the
cost graph. No double charge of the full service amount. GRNI and processing
accrual aging compare receipt acceptance against matched invoices/credits.

Physical purchase return reduces company stock and reverses appropriate receipt
accrual or recognizes a supplier-return clearing claim as policy specifies.
The supplier credit note separately changes AP/tax and clears the return claim.
Net returned and net credited limits are tracked independently. Their final
clearing-account policy must be reflected in the account mappings and journals.

## BR09 — Actual cost, WIP, wastage and per-suit cost

Input FIFO cost follows each consumed layer into transformation output layers,
remaining WIP or an expense destination. Eligible contractor charges, actual
materials, approved direct labor and allocated production overhead are included
once. Internal custody moves do not add margin or revenue. Costs already debited
to production WIP must be reclassified on completion, not expensed/capitalized a
second time.

Normal loss is part of the expected conversion cost borne by good output.
Abnormal loss is separately expensed; it cannot inflate good-suit unit cost.
Recoverable scrap/by-products receive an approved measured value with the
corresponding reduction of the primary output cost. Sale of scrap subsequently
has its own stock/revenue effects. Allocation bases and overhead capacity policy
are frozen with the batch; arbitrary retrospective cost smoothing is prohibited.

Example, all values PKR:

| Batch cost component | Amount |
|---|---:|
| Fabric FIFO cost | 100,000 |
| Accepted external processing | 20,000 |
| Trims | 5,000 |
| Direct labor | 10,000 |
| Eligible overhead | 5,000 |
| Total before allocation | 140,000 |
| Less abnormal loss charged to expense | (4,000) |
| Less recoverable scrap value | (1,000) |
| Good-output cost | 135,000 |

For 90 accepted complete suits, pre-packing cost is 1,500 per suit. Packing
materials of 4,500 bring the lot to 139,500 and 1,550 per packed suit.
Normal shrinkage has already reduced good yield; do not subtract its cost again.
If outputs contain multiple variants, allocate the 135,000 using an approved
equivalent-unit/weight basis first, then divide each variant allocation by its
accepted quantity. Incomplete components remain WIP and cannot be counted as
complete suits. Zero good output leaves explainable WIP/loss; division by zero
is rejected.

A later accepted cost increase of 9,000 on this 90-suit output adds 100 per suit
before subsequent transformations. If 30 equivalent suits are sold and 60 remain,
3,000 reaches COGS and 6,000 reaches remaining inventory, subject to actual cost
graph lineage and by-product/loss shares. The original invoice/movement rows do
not change. Closed-period differences post in an open period with an audit trail.

## BR10 — Sales, packing and credit

An order creates no revenue. Reservation and customer credit decisions lock their
shared targets; concurrent orders cannot each spend the same available stock or
credit. Dispatch references exact lots/rolls/packages and consumes reservations.
A package's contents are logistics membership; carton count is not extra suit
quantity. Repacking records release/new content and material usage atomically.

Proposed default: accepted dispatch is transfer of control. Dispatch recognizes
net revenue to unbilled receivable and FIFO COGS; invoicing reclassifies matched
unbilled value to AR and adds configured tax. Price/discount variance is explicit.
An advance receipt creates a customer credit open item, not revenue. Unsupported
invoice-before-control scenarios must be rejected or handled as an explicit
deferred-revenue policy with matched later recognition.

Physical sales returns restore only inspected accepted stock, normally to
quarantine, at the recoverable original issue cost with subsequent cost adjustments
traceable. Return/refund and revenue/tax credit effects are matched but distinct.
Do not restock at the current selling price or issue a second financial reversal
when a credit note follows the physical return. Return quantities and credit
amounts are bounded against net original dispatch/invoice less prior returns.

## BR11 — Journals, open items and settlement

Each financial posting has one journal containing at least two lines, with
positive debit XOR positive credit per line. Sum debit = sum credit exactly;
postable accounts, accounting period/date and permitted party/control dimensions
are verified. No journal lines can be changed after insertion/commit.
Manual journals use a registered document kind and the same validation.

GL is the journal line sum. Trial balance, general ledger, AP, AR and employee
payables are queries/projections, not mutable independent ledgers. A control
journal line creates one open item; original amount and direction remain fixed.
Allocations are append-only linked settlement effects. Remaining amount is
original amount less net allocations, including allocation reversals. No stored
party running balance is accepted as authority.

Payment debits AP/employee liability or creates a supplier advance and credits
bank/cash; receipt debits bank/cash and credits AR/customer advance. Withholding
creates its own payable/tax lines and snapshots. Allocations cannot exceed either
source or target, cannot cross parties without an explicitly authorized netting
transaction, and cannot reuse a reversed payment. Credit notes and advances are
allocated by the same rules. Never set an invoice paid flag independently.

Reconciliation matches bank statement lines to posted bank journal lines and
allows documented splits/fees with exact residual accounting. Reconciliation
changes matching, not the source journal. A fee or correction is a separate
posted expense/journal. Cash count adjustments are likewise explicit.

## BR12 — Reversals, periods and payroll

A full reversal locks the original document and all affected sources and
destinations. It inverts original stock/value/journal effects at their recorded
amounts, releases/reverses relevant allocations and links the original once.
It is blocked if downstream consumption, invoices, settlements or closed
reconciliation makes exact compensation invalid. Reverse dependent documents
first or use a current-period return/credit/adjustment. A source document's
status becoming reversed never hides it from ledger sums.

Fiscal years/periods cannot overlap. Period date membership, open state and close
permission are validated under lock. Closing requires trial-balance equality,
stock/value reconciliation, reviewed GRNI/contractor accruals, negative-stock
absence, unresolved document review and payroll/bank checks. Carry-forward is
controlled; balance-sheet balances derive from history, not duplicated opening
journals every month.

Payroll selects one valid employment-term/component version for each employee
period, snapshots attendance and calculation inputs, freezes approved component
amounts, and posts expense/WIP allocation, deductions and net employee payable.
Payroll settlement uses the same payment/open-item system. Attendance changes
after payroll posting require an adjustment run; they never rewrite a payslip.
Employee advances/deductions have explicit accounts and authorized recovery
amounts. Statutory deduction configuration requires local review.

## BR13 — Integrity enforcement responsibilities

PK/FK/UQ/CHECK enforce identity, referential membership where modeled, row-domain
validity and race guards. Posting services enforce cross-row sums, interval
overlaps, exact subtype/role compatibility, over-allocation and state transitions.
All draft-line writes take the root lock to prevent a race with posting.

Operational ledgers/audit must have no application UPDATE/DELETE path; use
restricted database privileges on append-only tables where deployable. Draft
business tables share posting guards; an administrator with unrestricted SQL can
bypass application invariants and is outside the normal trust boundary. Future
tests must prove enforcement rather than assuming Eloquent callbacks suffice.

## BR14 - Implemented Phase 6 inventory contract

Inventory documents use draft -> posted -> reversed, or draft -> cancelled.
Each posted correction is an explicit compensating document. Only successfully
posted documents allocate a type/year number. Every request uses a unique
creation/posting key and version checks; duplicate committed requests return the
same result. Opening stock needs supporting evidence, a reference, explicit
quantity/cost and separate posting permission. Seeders never create stock.

The company mutex precedes inventory, Catalog and calendar writes. Quantity
movements are authoritative; caches are rebuilt from movements/reservations and
allocations. Transfers preserve lot/roll, FIFO age, quantity and carrying value.
One tracked roll cannot finish a transaction in two locations. Held/quarantine
stock is excluded from picking/reserving, but explicit relocation to quarantine
and evidenced disposal are allowed. Contractor locations retain company ownership.

Counts freeze expected quantity and a movement watermark. A changed watermark
requires a recount even if intervening movements net to zero. Reservations are
committed until fulfilled or explicitly released, including after expiry.
Posting/reversal checks open periods, available quantities, exact cost allocations
and balanced stock journals atomically. Reverse downstream consumption first;
compensation restores original layers and journals rather than selecting new FIFO.
The complete operational rules and limitations are in WAREHOUSES_INVENTORY.md.

## BR15 - Implemented Phase 7 purchasing rules

Use active Supplier party roles/profiles and active stock items/warehouses.
Approval freezes an order without quantity/GL effects. Submitted orders cannot be
edited until returned by an approver; root versions prevent stale changes.
Partial GRNs consume accepted base quantity against the approved order under the
company lock. Zero over-receipt tolerance and closure are checked again at post.
Rejected quantities remain supplier owned. Each GRN creates new origin lots/rolls
and one positive movement per line, exactly once. Returns preserve original
receipt lot/roll genealogy and cannot exceed accepted or available stock. They do
not reopen ordering or silently reduce AP.

Bills match accepted/unreturned/unbilled receipt quantity at original cost.
Normalize supplier references across the same party identity. One source journal
and one AP open item per payable control line prevent duplicate capitalization or
financial records. Physical returns, commercial credit and payments are distinct
facts. Base-unit bill lines and original PO conversion/price snapshots are
mandatory; rejected/zero accepted goods produce no owned stock. Immutable posted
purchasing facts cannot be changed by Inventory endpoints. See PURCHASING.md.

## Phase 8 implemented processing rules

Use PROCESSING.md for operator steps, quantity/cost equations and boundaries.
Published route stages and posted documents are immutable. Stage order is data;
combined and separate Dan/Dyeing are ordinary service choices. Input/output item
continuity is required. Jobs freeze contract, unit, date, rate and loss allowance;
any negotiated rate needs processing.rates.override and recorded justification.

Dispatch quantities cannot exceed unreserved stock or the approved stage input
(net of rejected unchanged returns). Later stages accept previous accepted
output from this job or their own unchanged rejected returns. Contractor custody stays company owned and is closed only
through the matching dispatch's FIFO layer. Receipts may be partial but cannot
over-receive individually or across lines/concurrent requests. Source closed =
usable output + normal loss + abnormal loss. Rejected returns preserve identity
and cost with no service charge/loss. A newly returned lot/roll is traceable to its
source; release pending stock after inspection before another dispatch.

One receipt creates one balanced service/loss journal; one exact-cost matched bill
relieves that accrual and credits the shared Vendor party payable once. Reference
deduplication spans a party's Supplier and Vendor roles. Unmatched quantities and
rounding residuals are checked again under the company mutex when posting.
No posted processing edit/delete/cancellation or unsupported reversal is offered.
