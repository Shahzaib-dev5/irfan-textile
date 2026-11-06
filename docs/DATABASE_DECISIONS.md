# Database decisions and approval-sensitive assumptions

## Processing fabric flow addition (2026-10-27)

[Simple fabric flows](PROCESSING_FABRIC_FLOW.md) add `processing_fabric_flows`
with a unique request UUID, canonical input hash, JSON evidence, draft/posted status,
creator FK and nullable unique document/onward-document FKs. CHECKs require posted
flows to have a document and onward links to belong to inbound flows. Existing
Processing, stock and journal schemas and old payload hashes are unchanged.
`ProcessingFabricSetupSeeder` adds the per-suit embroidery service without changing
existing piece-rate contracts. No component count is an independent stock balance.


## Phase 12 additive finance decisions (2026-10-26)

Eleven new tables implement F05/F09-F12/F15-F18/F30-F31; existing journals, open items,
calendar and ledger hierarchy are reused. No existing row/schema rewrite. Groups are
non-posting ledger parents, not a separate identity. Employee fields await HR; tax
children await approved tax scope. Payment gross equals bank amount; supplier credits
are actual physical-return credits with zero tax/rounding. Bank reconciliation other
amount is constrained to zero. New ancillary timestamps follow Phase 11 nullable
TIMESTAMP(6), not a conversion of old history. Cash/bank journals use existing journal
tables and explicit document types. Financial opening is additive, not a balance
replacement. Full migration impact and restrictions: [ACCOUNTING_FINANCE.md](ACCOUNTING_FINANCE.md).


## Phase 11 implementation decisions (2026-10-26)
Approved dispatch recognition remains: Dr COGS/Cr inventory and Dr unbilled AR/Cr
revenue; invoice only reclassifies unbilled to billed AR. No duplicate financial
document. Return stock and commercial credit remain distinct. Customer credit
checks lock role/profile and include unfulfilled commitments.
Additive refinement: nullable stock_reservations.sales_order_line_id and composite
order ownership FK, no changes to existing transfer rows. Eleven operational
Sales tables added; tax_rate_id NULL-only and zero-tax checks follow the existing
Purchasing slice until tax configuration is authorized. No quotation/package-serial
shipment or settlement tables added. Return cost uses cumulative proportional
original dispatch FIFO component costs, with final residual rounding.
Sales ancillary created_at/updated_at use Laravel nullable TIMESTAMP(6) defaults,
filled by Eloquent; the Phase 1 proposal used non-null DATETIME(6). This is limited
to new Sales tables, with no conversion of existing document/audit/ledger metadata.
Actual dispatch/receipt business timestamps remain non-null DATETIME(6). Any later
metadata tightening must be a forward migration with existing-row review.
See [SALES.md](SALES.md) for migration impact and accountant review choices.

## Phase 10 implementation choices (2026-10-26)

- Implement approved IP38-IP44 without another variant, stock or completion master.
  Seven additive tables; no existing columns/checks/history are rewritten.
- Configure PACK (count, precision 0) on the existing finished item classification;
  SUIT variants remain separate. No generic item conversion between PACK and SUIT.
- A PACK SKU's saleable assortment is stable across versions; changing design/color
  contents/count needs another SKU. Published versions can revise actual supplies.
- Saleable components are individual active SUIT variants; no nested packs in this
  slice. A component appears once per spec, consolidating its physical requirement.
- Packing is an actual completed conversion when posted. Exact inputs/output and
  complete per-container attribution are rechecked; packages are trace metadata.
- Full-recovery unpacking requires original spec provenance, all contents recovered
  and explicit cost shares with a documented basis. Missing/lost contents are
  rejected; no inferred write-off or arbitrary reversal after downstream use.
- Actual rejected stock stays valued/blocked. Physical disposal is its own existing
  Inventory damage document, with independent manage/post authorization.
- Historical acquisition costs use immutable value-entry cutoffs and signed original/
  reversal facts. Current carrying value is separate. Master rates cannot restate
  old amounts. Manual monetary allocations are frozen/audited, not added estimates.
- Cost reporting reads source evidence; no general expense/payroll/transport CRUD
  or late-cost posting editor is introduced. See FINISHED_GOODS_COSTING.md.

## Phase 9 implementation decisions (2026-10-26)

- Manufacturing batch is the production order/plan; no duplicate planning master.
- Implement approved IP29-IP37. The nullable batch FK on processing_jobs is the
  only added existing-table column. It preserves all old NULL-linked jobs and
  excludes that NULL field from their existing payload-hash representation.
- Published BOMs and referenced component/variant measurement identities are
  protected. New recipe versions do not alter confirmed requirements. Requirements
  round upward to the component unit precision; no floating point is used.
- An ordinary report atomically converts actual consumed lots into physical
  outputs and losses. Intermediate output retains a source base unit; meters to
  finished SUIT is backed by the BOM. This slice does not invent a generic
  cross-dimension intermediate yield formula.
- A completion-only final report has no lines/effects and is allowed only when
  previous output already accounts for the whole plan and WIP/custody is clear.
- Rejected physical suits remain valued rejected stock; they are not counted as
  accepted stock and cannot be picked. Destruction is a separate stock event.
- Actual labor/overhead absorption credits the existing eligible source expense
  debit, capped across posted reports under the company lock. It never creates
  a second payable or fabric processing charge. No expense/payroll CRUD is added.
- Posted production reversal automation is deferred: dependent cost/stock history
  is retained; no edit/delete or unsafe generic reversal is exposed.

See [PRODUCTION.md](PRODUCTION.md) for migration impact and operating limits.

> Phase 8 implementation (2026-10-21): configurable processing routes/jobs,
> contractor dispatch/custody, partial accepted/rejected returns, loss costing,
> roll lineage and exact-rate contractor bill/AP sources are implemented.
> See [PROCESSING.md](PROCESSING.md) for actual behavior and explicit boundaries.
> Production/sales/payments/tax remain future scope; MySQL 8.4 validation is pending.

Status: **approved architecture baseline with Phase 3-5 implementation choices**,
2026-10-20. The user approved the architecture by authorizing Phase 2 and later
authorized implementation of company setup, party management and textile masters. Structural decisions below
govern future implementation; specifically marked business/accounting policy
choices remain open for the company and its accountant before affected posting
workflows are implemented.

| ID | Decision | Rationale / approval or migration impact |
|---|---|---|
| D01 | One company singleton; PKR only | Avoid fake tenancy/FX support. Multicompany or FX requires new ownership, key scopes, valuations and currency journals. |
| D02 | Shared parties + role types + roles + role-specific profiles | Approved. One legal party can have all three roles; party profile FKs never duplicate identity. Employees may reference the same identity without a commercial role. |
| D03 | Configurable versioned processing routes and service capabilities | Approved. Steps can repeat, be skipped by a new planned version, or represent rework; no hardcoded sequence. |
| D04 | InnoDB, utf8mb4, strict SQL mode, exact DECIMAL | MySQL authoritative; no float money or quantities. UTC timestamps, local DATE business dates. |
| D05 | Universal documents with typed PK/FK header extensions | Gives audit, numbering and ledger provenance actual FKs. Kind/header compatibility is service-validated; header FK does not prove subtype. |
| D06 | Draft → posted → reversed; draft → cancelled | Posted payload/lines immutable. A reversal is its own numbered compensating document; the original is retained. Partial returns use separate return/credit documents. |
| D07 | Number at posting, scoped sequence by type/year | Prefix/year/padded counter; global number UQ. Sequence lock in posting transaction. Numbers never recycled after committed posting. No promise of legally gapless numbering. |
| D08 | Company-owned stock only; custody is location | Vendor dispatch changes custody, not ownership or supplier balance. Third-party-owned fabric/consignment needs an explicit owner dimension and reviewed inventory/accounting rules. |
| D09 | Quantity ledger + separate value ledger + rebuildable balances | Stock movements are physical facts; late cost does not invent quantity. Caches cannot become alternate truth. |
| D10 | Perpetual FIFO within the identified eligible item/lot/roll stock | Traceable physical lot selection first; layer FIFO by posting sequence within selected position. Globally interchangeable SKUs use oldest eligible received lot selection. Exceptions require documented quality/traceability reason. |
| D11 | Actual cost and immutable cost-propagation graph | Follow consumption through processing, production, packing and sales; late costs split to remaining inventory, WIP and already expensed goods without changing original entries. |
| D12 | No negative physical or available stock | Lock balance and reservation/layer resources. Overissue, double reservation and double return are rejected atomically. |
| D13 | WIP and accepted output carry eligible actual cost | Normal loss absorbed by good output; abnormal loss expensed. By-product allocation and normal-capacity overhead basis must be approved. |
| D14 | Receipts/dispatches separate from invoices/credit notes | Physical returns do not themselves reverse tax/AP/AR. Invoice matching prevents double stock recognition and duplicate billing. |
| D15 | Recognize procurement at receipt; accrue GRNI/contractor acceptance cost | Later bill clears accrual and records approved variance/tax. Never capitalize full processing bill after already capitalizing accrual. |
| D16 | Revenue and COGS align to transfer of control | Proposed operational default: control transfers at accepted wholesale dispatch. Post customer unbilled receivable/revenue and COGS then; invoice clears unbilled receivable to AR plus tax. Exceptions must explicitly defer revenue/COGS together. |
| D17 | One balanced journal sidecar per financial source document | Double-entry equality is checked in the posting transaction, not a false row CHECK. AP/AR/employee open items originate from control journal lines. |
| D18 | No supplier/vendor identity offset and no automatic AP/AR netting | Display unified party identity but separate control balances. Explicit authorized settlement document required for a lawful approved offset. |
| D19 | Effective intervals use [start,end), NULL end infinity | Parent-lock overlap checks, not misleading uniqueness alone. Posted jobs keep snapshots; referenced history is not overwritten. |
| D20 | Suit quantities are integral; meters, kilograms and rates have decimals | A suit is a defined BOM output, never a universal meter conversion. Roll length is measured; conversions snapshot at posting. |
| D21 | Master deactivation, no posted deletion | FK RESTRICT by default; append-only ledgers/audit. No global soft-delete pattern masking posted relationships. |
| D22 | Fiscal year starting July is a configurable proposal | Actual company calendar and opening date need approval. Overlapping years/periods forbidden; closed period posting rejected under lock. |
| D23 | Tax and statutory payroll values are configuration, not assumed law | No invented Pakistani rate, withholding eligibility or compliance claim. Snapshot assessed taxes and preserve official references when configured. |
| D24 | Payroll is monthly attendance-informed salary/component calculation | Terms/components effective-dated, posted payslip details immutable, deductions/advances are explicit. Piecework and statutory filing integrations require separate approved scope. |
| D25 | Full reversal blocked when downstream facts depend on it | Reverse downstream documents first, or use a current-period correcting adjustment/credit note. Never let a reversal create negative stock or undo allocated funds twice. |
| D26 | Strict typed schema over generic EAV/JSON business data | JSON allowed for bounded settings/audit/snapshots, not for quantities, amounts, dependencies or journal lines. |
| D27 | Configurable process/status concepts use `VARCHAR` + CHECK; closed accounting classifications may use MySQL `ENUM` | The open-ended service/route vocabularies stay data-driven; financial sides and bounded calculation kinds are closed. Adding ENUM members requires a reviewed schema migration. |
| D28 | Phase 3 adds a PKR-only `currencies` reference table and `companies.functional_currency` FK | Explicitly requested currency master; `CHECK(code='PKR')` preserves D01. This is one additive table beyond the approved 138-table Phase 1 inventory and requires seeding PKR before company setup. It does not authorize foreign-currency balances, rates or journals. |
| D29 | Phase 4 brings forward approved `units` and `payment_terms` masters and implements the approved party/capability tables | Both prerequisites are in the Phase 1 inventory and required by profile/rate FKs; they are not new architecture tables. Requested soft deletion is represented by audited `is_active` deactivation because the approved schema has no `deleted_at`; physical deletion is unavailable. Vendor-rate successors must be future-dated and may prospectively close an open range under the locked vendor-service row. Processing-job rate snapshots and existing-job date checks are deferred to the job aggregate. Initial billing assumptions: fabric-length services use M; Embroidery uses PCS. Confirm these conventions with operations before rates are entered, or change them before the capability is used. |

## Phase 5 implementation decisions

**D30 — Approved textile mappings.** Phase 5 creates the seven approved catalog
tables and reuses units. Collections use designs.season; consumables/materials
use trim items and categories. No collection table, duplicate material/product
master, EAV attribute store or stock balance column was introduced. This has no
change to the approved inventory or existing Phase 4 table definitions.

**D31 — Measurement maintenance.** Unit codes/dimensions/precision are immutable
through maintenance; count precision is zero. Fabric is M, finished variants
are whole SUIT, and whole-product SET is an alternate count basis. Item-specific
conversions use DECIMAL(20,10), BCMath and dimension/precision checks, with no
fixed roll or meter-to-suit conversion. Conversion factors are audited mutable
master values for future use, and later document lines must snapshot them.
Catalog writes serialize on the company singleton, required by category-tree
cycle checks. Referenced item structure is protected now; stock-usage guards
must be added when Inventory starts. These are application policies and the new
approved-table migration; no existing stock data or unit columns are changed.

## Critical choices requiring approval before affected posting workflows

Confirm the company-specific policies in D08, D10-D16 and D22-D24 with the
business/accountant before implementing affected posting workflows: stock
ownership, FIFO selection, late-cost propagation, loss/overhead, control-transfer
timing, fiscal calendar, tax handling and payroll scope. The company singleton
and PKR-only structure in D01 and D28 is the current implementation baseline;
this is not approval of unresolved posting policies or statutory values. No
existing business data was migrated by Phase 3.

## Valuation and financial policy detail

FIFO is an inventory costing policy, not permission to ignore identified rolls.
Stock issue selection must respect lot/roll quality and traceability and record
the actual layer consumption. Packing a carton cannot double-count the suits
inside it. Cost-only corrections preserve original physical quantities and are
posted on an open business date; a closed period is not silently reopened.

The proposed capitalization boundary includes purchase and conversion costs
needed to bring inventory to its condition/location; inventory write-downs are
separate adjustments and sold inventory cost follows the corresponding revenue.
These policies are informed by the [IFRS Foundation IAS 2 overview](https://www.ifrs.org/issued-standards/list-of-standards/ias-2-inventories/).
This document specifies the approved architecture's proposed system policies, not a statement that the company or
future application is IFRS or Pakistani tax compliant.

## MySQL enforcement limits

- Row CHECK constraints can reject invalid local quantities/status combinations,
  but cannot query other rows or tables; nullable expressions need deliberate
  handling. [MySQL CHECK constraints](https://dev.mysql.com/doc/refman/8.4/en/create-table-check-constraints.html)
- Every FK targets an explicit primary/unique candidate key with compatible
  types. InnoDB checks FKs immediately. Re-enabling foreign_key_checks does not
  validate rows written while disabled, so the migration plan never relies on
  that shortcut. [MySQL foreign keys](https://dev.mysql.com/doc/refman/8.4/en/create-table-foreign-keys.html)
- Required concurrency rules use locked stable parent rows in an active
  transaction. SKIP LOCKED is not used to decide whether money/stock is available.
  [MySQL locking reads](https://dev.mysql.com/doc/refman/8.4/en/innodb-locking-reads.html)

Cross-row assertions (balanced journals, interval overlap, capacity allocations,
header totals, same-party matches) are mandatory posting/service validations and
future concurrency tests. Database constraints complement those assertions; they
do not replace them. Application DB access must be restricted to approved paths.

## D32 - Phase 6 posting baseline and migration impact

The Phase 6 request authorizes the approved warehouse/core inventory slice and
its minimum document/FIFO/journal prerequisites. FIFO is implemented for the
specific lot/roll position; all inventory is company-owned, including vendor
custody. Opening/variance accounts are seeded once and require operational and
accountant acceptance before live posting. No purchasing accrual, service-cost,
tax, production or sales recognition policy is implemented by implication.

New DDL creates 25 approved tables and adds the deferred audit-document FK; it
changes no existing stock data, units, precisions or party keys. Employee journal
dimensions are temporarily NULL-only until the future employee FK migration.
The invalid proposed AUTO_INCREMENT self-id CHECK is omitted from the new account
and cost-centre tables; there is no account-tree editing endpoint. See the exact
MySQL restriction and full inventory in WAREHOUSES_INVENTORY.md.

The company singleton is the first lock for warehouse/inventory/Catalog/calendar
mutations. This conservative serialization replaces the position-only lock-order
proposal for this implementation. Reservations commit draft transfers. Specific
reversals restore original allocations/layers and compensate original values;
future late-cost traversal must net compensations. Base-unit-only documents need
no alternate-unit factor columns or invented schema extension. Tracked rolls move
in full; split/join lineage remains a later physical transformation workflow.

## D33 - Phase 7 supplier procurement baseline

The explicit Phase 7 request brings forward ten approved purchasing/AP-source
tables, reuses shared Supplier roles and the existing stock/document/journal
infrastructure, and adds no independent identity, stock quantity or payable cache.
Approval is a submitted audit state followed by numbered immutable PO posting;
closure of an unfulfilled balance is an event, not a mutation of fulfilled facts.

Accepted receipt tolerance is zero with no override. Returns do not reopen an
order; a replacement requires a new approved order. Rejected goods never enter
company-owned stock. All-rejected deliveries are inspection audit evidence.
PO-less receipts were outside the original Phase 7 slice; a later authorized
Purchasing UX follow-up now enables them with a required audited reason.
Conversion snapshots retain eight-place schema precision and are checked for
exact compatibility with master conversion factors.

Accounting uses Inventory/GRNI once at receipt, GRNI/AP once at exact-cost bill
matching and one party open item per control journal line. Physical returns on a
wholly unbilled receipt relieve GRNI; if any quantity has been billed they use
supplier-return clearing and leave AP unchanged pending a future credit.
Tax, processing/service/expense bills, supplier credits, payment/settlement and
late-cost/price variance workflows remain out of scope; unresolved bill variance
is rejected, never silently capitalized or expensed. Accountant acceptance of
mappings remains required before live use. This is not a tax/compliance claim.

Migration impact is additive, forward-only, with no existing data rewrite:
68 application tables after ten new approved tables. Future PO tax FK and invoice
processing-step FK are deferred while those columns are CHECK-constrained NULL.
Stock-only invoice/tax/rounding subset checks prevent unsupported records; later
authorized modules must replace them and add the real FKs. There is no placeholder
processing or tax master. SourceJournal is shared with Inventory; generic Inventory
routes cannot act on purchasing documents. See PURCHASING.md for exact contracts.

## D34 - Phase 8 executable processing boundary (2026-10-21)

The explicit Phase 8 request authorizes the approved route/job/dispatch/receipt
slice and contractor bill matching. PROCESSING.md is the operational contract.
Physical fabric input/output retain one base unit; dyeing uses M and PKR/M.
Cross-dimension billing (for example embroidery PCS) records a separate inspected
service count and evidence, never a fabricated meter-to-piece stock conversion.
One vendor per route stage/job follows the approved unique job/route-step key;
parallel vendors use separate jobs. Negotiated overrides require an explicit
permission and source-contract/reason audit on a new immutable job snapshot.

Dispatch lines each retain a source FIFO layer and open a matching contractor
layer. Receipts consume that exact layer, preventing jobs at the same stock
position from stealing each other's custody. Normal loss absorbs input cost;
abnormal input loss is expensed proportionally, processing service charges are
capitalized on accepted output, and the bill clears the accrual into shared AP.
Positive returned output is mandatory in the approved schema; total-loss-only
closure, direct contractor-to-contractor dispatch and posted correction/reversal
screens are not enabled. These limits require operational acceptance.

Migration impact: ten additive approved tables bring the executable count to 78.
The existing nullable invoice processing-step column receives its FK and the
stock-only CHECK expands to stock OR processing; old stock rows remain valid.
No existing data is removed or reclassified. MySQL/MariaDB CHECK removal syntax
is selected by server identity. No application database reset/migration was run.
No tax/expense bill subset or late-cost variance engine is claimed; charge
allocation architecture remains design-only until that engine is implemented.
