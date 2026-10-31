# Database architecture review

## Phase 12 implementation review (2026-10-26)

Reviewed eleven additive tables against F05/F09-F12/F15-F18/F30-F31. Shared party
identity, document PK journal linkage, restrictive FKs, unique reversed allocations,
single-sided lines and exact DECIMAL balances are retained. Account groups reuse
the ledger tree. Payments/receipts create open items; settlements never post a
second expense/revenue. Physical supplier credits reconcile actual return clearing.
Employee/tax fields and price/service cost-credit redistribution are deliberately
deferred as detailed in [ACCOUNTING_FINANCE.md](ACCOUNTING_FINANCE.md).
Review checks: separate-worker payment concurrency, exact reversal, account/role
authorization, closed periods, partial settlement and source totals. Actual final
results and exact-target DB limitation are in [TESTING.md](TESTING.md).

## Phase 11 review (2026-10-26)
Reviewed additive F33-F39/F41-F44 slice, FK ownership, decimal/check precision,
immutable posting, UUID/input hash replay, line caps, reservation/stock locks,
credit exposure and dispatch/invoice accounting separation. Returns restore
original issue cost and credits separately reduce AR. Whole SUIT/PACK conversions
are distinct; tax/package-serial/settlements are deferred. Test/activation evidence
is recorded in [TESTING.md](TESTING.md) and [PROJECT_STATE.md](PROJECT_STATE.md),
not inferred from this design checklist. Complete review: [SALES.md](SALES.md).

## Phase 10 implementation review (2026-10-26)

Approved seven-table packing slice retains normalized items, variants, documents,
locations, lots and the existing ledgers. Quantity conservation is per component
base unit; cost conservation is PKR only. Full container/source attribution is
revalidated at posting and never represents duplicate stock. Specs/posted metadata
are immutable and reference identities are protected.

Shared company locking, positive quantities, existing availability/FIFO checks,
stable request keys and frozen posted hashes protect retries/concurrent packing.
Historical costing reads source value entries before each issue and includes both
signed original/reversal history. Existing stock disposal and completion boundaries
are reused. Exact MySQL 8.4 and company allocation/inspection acceptance remain
open; actual check evidence is in TESTING and FINISHED_GOODS_COSTING.

## Phase 9 implementation review (2026-10-26)

The approved IP29-IP37 slice is now executable; this supersedes earlier design-only
Production notes. Review migration 2026_10_24_000001 and PRODUCTION.md for the nine
tables, exact DECIMAL/check/FK/UQ constraints and nullable Processing job batch FK.
No application data rewrite/reset was performed. Tests use an isolated guarded DB.

- Published BOM versions, line references and component/variant identities are
  protected; frozen batch requirements have no editable stock totals.
- Services enforce report/batch scope, material compatibility, quantity precision,
  BOM capacity, company ownership, WIP origin and no duplicate material backing.
- Existing ledger/FIFO/cost links/journal sidecars remain authoritative. Replayed
  or competing confirmations cannot create a second physical conversion.
- Source cost capacity and final completion share the company posting mutex.
- WIP remains traceable after transfers/contractor processing; another batch
  cannot consume it or count recycled input as another raw-material requirement.
- Zero-effect closure is explicit and cannot conceal missing output/WIP/custody.
- Actual full/focused/browser checks are in TESTING.md; exact MySQL 8.4 and
  company operator/accountant acceptance remain open deployment gates.

> Phase 8: configurable processing jobs/routes, exact-layer custody, partial
> returns/loss and matched contractor AP sources are implemented. See
> [PROCESSING.md](PROCESSING.md) for behavior and explicit deployment limits.
> Target MySQL 8.4 validation remains pending.

Status: Phase 1 architecture review complete and approved. Phase 3 setup and
Phase 4 party DDL/workflows were exercised on local MariaDB 10.4.32; this does
not certify MySQL 8.4 behavior or the unimplemented operational schema.

Phase 4 implementation review: all eleven approved shared-party/capability
tables and approved `units`/`payment_terms` prerequisites are present in the new
migration. FKs restrict deletes, profiles use party-role primary keys, vendor
capabilities reference vendor profiles, and rates use DECIMAL, PKR, unit checks,
parent-row locking and overlap validation. The full test suite passes on MariaDB.
MySQL 8.4 DDL validation and independent-connection rate contention remain open.

## Review scope

Phase 5 execution review: the seven approved catalog tables were migrated on
MariaDB 10.4.32 by isolated-database tests. Full suite: 39 tests, 241 assertions
passed. Fabric/unit/variant invariants, cycle checks, category FK and positive
conversion/piece constraints, policies, read-only access, reusable forms,
search/pagination, audit snapshots and seed/factory paths are covered. No stock
tables were created. MySQL 8.4 DDL verification and independent-connection
mutex/race validation remain open. See TEXTILE_MASTER_DATA.md for the complete
implementation checklist and columns/constraints.

Review the approved MySQL 8.4 architecture against the shared-party architecture, configurable textile processing, stock traceability, double-entry accounting, and safe migration requirements. Treat the detailed specifications as authoritative and compare diagrams, decisions, business rules, and migration ordering against them.

## MySQL constraints: guarantees and limits

| Topic | Review requirement |
| --- | --- |
| Row checks | Use enforced `CHECK` constraints for row-local arithmetic, ranges, allowed values, and mutually exclusive columns. Required values also need `NOT NULL` or an explicit null predicate. |
| Aggregate rules | Balanced journals, document totals, rate overlap, consumed versus available stock, and child membership require transaction-level validation; an ordinary row `CHECK` cannot enforce them. |
| Unique keys | MySQL allows multiple null values in a unique index. A nullable natural-key dimension needs an explicit normalization or scope strategy. Conditional uniqueness alone does not prevent interval overlap. |
| Referenced keys | Every FK target is a primary key or explicitly declared unique candidate key. Composite references use the same column order, compatible types, and matching string collations. |
| Nullable composite references | A composite FK containing a null component does not establish parent membership. Require all components together when that relationship is mandatory, and add explicit row-shape checks for optional pairs. |
| Immediate checks | InnoDB constraints are immediate. Break creation cycles with a later FK-addition migration, without assuming deferred checks. |
| Historical rows | `RESTRICT`/`NO ACTION` preserves references. Review any cascade or nulling action individually and check compatibility with columns used in `CHECK` constraints. |
| Locking | Use an explicit transaction and lock an existing aggregate/scope row before validating and mutating dependent rows. An empty result set is not a dependable universal mutex. |
| Readers | Availability and financial allocation checks must not use `SKIP LOCKED`, because that intentionally omits locked rows. |
| Decimal arithmetic | Use exact decimal inputs and explicit precision, rounding, and residual allocation rules. Never calculate money through binary floating point. |
| Integrity restoration | Do not assume that turning `foreign_key_checks` back on validates rows inserted while it was disabled. |
| Scaling | Do not propose native InnoDB partitioning for tables participating in FKs. Establish query indexes and retention policy first. |

These requirements follow the MySQL 8.4 manuals for [CHECK constraints](https://dev.mysql.com/doc/refman/8.4/en/create-table-check-constraints.html), [foreign keys](https://dev.mysql.com/doc/refman/8.4/en/create-table-foreign-keys.html), [unique indexes](https://dev.mysql.com/doc/refman/8.4/en/create-index.html), [locking reads](https://dev.mysql.com/doc/refman/8.4/en/innodb-locking-reads.html), [DECIMAL](https://dev.mysql.com/doc/refman/8.4/en/fixed-point-types.html), and [rounding](https://dev.mysql.com/doc/refman/8.4/en/precision-math-rounding.html). Parent-row serialization and the test scenarios below are design recommendations derived from these database behaviors.

## Architecture review checklist

Record evidence before changing a box to checked.

- [x] Every requested business area has an identified module, aggregate, and table specification.
- [x] Every specified table has columns, data types, nullability/default rules, PK, relevant FKs, unique constraints, and access indexes.
- [x] Table names and FK targets agree across the inventory, specifications, diagrams, and migration plan after review corrections.
- [x] Shared party identity supports simultaneous supplier, contractor, and customer roles without duplicate identity masters.
- [x] Role-specific relationships reject the wrong role through posting validation; screen ownership follows Purchasing, Processing, and Sales.
- [x] Processing stages are configurable and released jobs preserve the route/rate used historically.
- [x] Rates have unambiguous date boundaries and overlap validation serializes all writers to the same scope.
- [x] Each stock item has a canonical quantity unit; conversions are fixed and snapshotted where needed.
- [x] Lot, roll, item, warehouse, location, owner, custody, and quality dimensions are separated by schema and posting invariants.
- [x] Posted stock movements are authoritative; any balance cache is rebuildable and updated atomically.
- [x] Stock availability, reservation, custody settlement, and reversals specify locking and conservation rules.
- [x] Purchase and contractor accruals are cleared by billing without duplicate inventory capitalization.
- [x] Manufacturing, wastage, work in process, packing, and finished output have an explicit cost allocation method.
- [x] Per-suit cost is traceable to actual component consumption and a deterministic allocation of shared costs.
- [x] Sales dispatch recognizes unbilled revenue and COGS once; invoice clears unbilled receivable and records tax without duplicating revenue.
- [x] Financial journals balance in PKR, preserve posted history, and identify typed source documents.
- [x] Partial payments, receipts, credit notes, advances, refunds, and payroll settlement retain allocation history.
- [x] Period closure and document numbering cannot race with posting.
- [x] Duplicate requests, stale edits, retries, and reversals have explicit outcomes.
- [x] Migrations have an acyclic table-creation order and explicit handling of any later FK additions.
- [x] Unapproved business policies, legal/tax settings, and implementation limits are clearly flagged.

## Scenario walkthroughs and future acceptance tests

The following are acceptance criteria for implementation phases. A documentation walkthrough is not an executed database test.

| ID | Scenario | Required outcome |
| --- | --- | --- |
| R01 | One party purchases as a supplier, processes as a contractor, and buys as a customer | One identity, three enabled roles and profiles; independent payable and receivable subledgers; no automatic offset. |
| R02 | Two users insert overlapping contractor rates at the same time | Both lock the same existing rate-scope parent. One commits; the second sees the committed interval and rejects overlap. Adjacent half-open intervals are allowed. |
| R03 | A posted job's rate master changes later | The posted job uses its stored rate, billing basis, quantity, and amount snapshots. A correction uses an adjustment/reversal document. |
| R04 | Two dispatches compete for the last 100 meters | Serialized stock-position locks prevent the aggregate accepted issue from exceeding available quantity. No transaction leaves partial ledger or journal rows. |
| R05 | 1,000 meters moves from the company's warehouse to a contractor | Company ownership and total owned quantity/value remain unchanged; warehouse and contractor custody balances change by equal opposite quantities. |
| R06 | Contractor returns 960 meters and records 40 meters of loss | Explicit returned, retained, and loss quantities reconcile with dispatch. Cost treatment distinguishes allowed normal loss from abnormal loss; loss is not hidden in an unexplained shortage. |
| R07 | Grey receipt is posted before the supplier bill | Inventory and receipt accrual post once. The bill clears the accrual and records only the documented variance/taxes/payable. |
| R08 | Contractor service completion precedes contractor billing | Completion capitalizes or expenses the approved service cost once against an accrual. Billing clears that accrual; it does not add the same service cost a second time. |
| R09 | A batch consumes 100,000 PKR materials and adds 20,000 PKR processing/labor to produce 100 equivalent suits | With no waste, by-product credits, or other costs, total output cost is 120,000 PKR and unit cost 1,200 PKR. Actual document lineage explains every component. |
| R10 | Costs are allocated across outputs and leave a smallest-currency residual | A deterministic allocation assigns the residual, so allocated amounts exactly equal the source amount; journal and inventory totals remain reconciled. |
| R11 | Packing consumes packaging materials and groups suits into cartons | Packaging costs are included once. Carton identity does not create duplicate saleable suit stock. Any actual SKU transformation has explicit input/output movements. |
| R12 | Sale dispatch occurs before invoice, with a partial return afterward | COGS occurs at the documented recognition event exactly once. Return quantity and value reference original dispatched lines and cannot exceed their eligible balance. |
| R13 | Two receipts allocate concurrently to the same invoice | A lock on the invoice/open-item scope serializes allocation; over-allocation is rejected. Unallocated customer funds remain a liability/advance. |
| R14 | One party has an AP bill and an AR invoice | Gross balances remain separately visible. A deliberate settlement/netting document creates balanced entries and allocations; a party total never silently substitutes for both ledgers. |
| R15 | A journal is missing one credit line | Posting fails atomically. The document cannot become posted while the accounting effect is absent or unbalanced. |
| R16 | A posted stock receipt has already been consumed | Reversal checks downstream dependencies and available original stock/cost. It cannot create a negative balance or silently change already recognized costs. |
| R17 | A request times out after its transaction commits and is retried | The idempotency key returns the existing posted result. A reused key with different input is rejected. No additional number, stock effect, or journal is generated. |
| R18 | A financial period closes while another session posts | Posting and closing serialize on the same period control row. The losing transaction observes the final closed/open state and behaves accordingly. |
| R19 | An employee receives an advance, a payroll run, and a partial salary payment | Advance recovery, payroll liability, and settlement are traceable per employee. Payments reduce the correct payable without duplicating payroll expense. |
| R20 | A posted document is corrected after year closure | The original remains intact; an authorized correcting document posts into an open period and links back to the original. |

## Final review evidence

The detailed specifications inventory 138 tables, with type/null/default/key/index contracts by domain. Cross-document review corrected references to the single `ledger_accounts` master, resolved package links to `packages.id`, aligned sales revenue/COGS timing between dispatch and invoice, removed an ERD edge without an FK, and placed employees before journal lines in migration order. A duplicated FIFO tie-breaker was removed in favor of the unique source movement ID.

The design delegates cross-row controls (balanced journal totals, rate overlap, aggregate source/return/allocation limits, role/profile kind, and typed source membership) to locked posting transactions. This is an explicit MySQL capability boundary; CHECK/FK constraints alone do not enforce those facts. The migration plan accounts for immediate FK checking and never disables `foreign_key_checks`.

The Phase 1 design review does not cover executable operational DDL or posting
behavior. Phase 3 has since executed its identity/company/calendar migration and
auth/setup feature tests on MariaDB 10.4.32, including service-level overlap
checks under parent-row locks. No independent-connection race test was run. These
results do not establish MySQL 8.4 compatibility or validate unimplemented stock,
purchasing, processing, production, sales or accounting postings.
Business/accountant policies listed in `DATABASE_DECISIONS.md` remain open before
their affected workflows are implemented.

## Phase 6 executable review

Reviewed the approved subset against actual DDL and posting services. Corrected
an overlength generated index name, documented the MySQL AUTO_INCREMENT CHECK
restriction, and constrained the future employee journal dimension to NULL.
All new FKs target candidate keys; nullable no-roll identity uses a generated
unique key. Company-first locking serializes stock and calendar writers.

Executed tests now cover R04 competing stock issues, the company-owned custody
transfer portion of R05, inventory journal consistency in R15, downstream receipt
reversal rejection R16 and committed duplicate retries R17. Inventory reservation
races and exact transfer/FIFO value conservation are also tested. Processing
returns, procurement/service accruals, production/sales/payroll and settlements
remain unimplemented. MySQL 8.4 validation and browser acceptance remain open.

## Phase 7 executable review

The approved purchasing subset creates ten tables and reuses stock/party/journal
keys. Reviewed caps at draft and posting, aggregate same-document receipt/match
quantities, original lot/roll sources, partial cost relief, root number/key
uniqueness, row-level checks and company-first locks. Short FK names avoid a
65-character generated constraint. SourceJournal's unique source PK and AP
journal-line uniqueness prevent duplicate books. Inventory boundaries now reject
purchasing roots. No tax/processing placeholder master was added; unused columns
are NULL-only pending later FKs.

Functional full/partial receipt, rejection, matching, return and role tests pass;
independent receipt and invoice capacity races pass. Historical acceptance
criteria for purchasing goods/GRNI and invoice clearing are now implemented only
for exact-cost stock bills. Price variance, credit/payment, processing accrual and
production/sales/payroll scenarios remain future scope. MySQL 8.4 runtime and
interactive browser acceptance remain open.

## Phase 8 review

The additive migration covers approved IP05, IP20-IP27 and F26, plus the deferred
invoice processing-step FK/subset CHECK. It preserves existing stock invoices and
shared party roles. Processing charge allocations/late-cost architecture remain
design only. Posted jobs/rates, dispatch layers, source-equivalent receipt/loss
quantities, FIFO value conservation and AP match caps are checked under the shared
company mutex. See the PROCESSING.md review checklist and PROJECT_STATE.md for
executed tests and explicit boundaries; no MySQL 8.4 certification is claimed.
