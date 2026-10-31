# Phase 6 — Warehouses and Inventory

Current integration: Phase 8 Processing now uses the shared party, stock/FIFO,
calendar and source-journal foundations. See [PROCESSING.md](PROCESSING.md).
The phase-specific scope below remains the historical implementation contract.

Implemented 2026-10-21 on Laravel 13.38.0, PHP 8.3.35 and BCMath. MySQL 8.4 LTS
is the target; executable checks use the available MariaDB 10.4.32 server and
the isolated `irfan_textile_phase3_test` database. Application `.env` and
application data have not been reset. No operational stock is seeded.

## Operating guide

1. On the intended application connection, install pending migrations with
   `php artisan migrate`, then `php artisan db:seed`. Configuration includes
   permissions, two document types and three stock accounts, without stock or
   login credentials. Complete company setup and an open financial year/period.
2. In **Warehouses**, create a warehouse and locations. Types are internal,
   transit, quarantine and contractor. Contractor locations reference an existing
   active shared vendor role/profile. Stock at every location remains company
   owned. Third-party-owned inventory is outside this phase.
3. In **Inventory → Documents → Opening / adjustment**, select Opening and enter
   a date, supporting record reference and evidence explanation. Choose an item,
   location and unique lot number. Tracked fabric requires a unique roll number.
   Enter the evidenced quantity and explicit PKR per base unit. Fabric uses
   meters; suits/pieces use whole units. Explain zero-cost stock in the evidence.
   Save, review and **Post document** with posting permission. Drafts change no
   stock; openings create no supplier purchase or payable.
4. **Stock availability** derives quantity from movements and displays committed
   reservations, unreserved quantity, quality, location, lot/roll, base unit and
   carrying value. Search item/SKU/lot/roll and filter warehouse/quality. Quality
   changes require permission and an audited reason and affect the whole lot.
5. **New transfer** selects source, destination and positive base-unit quantity.
   Saving prepares exact FIFO layer lines. Reserve optionally, review, then post.
   Both sides commit atomically with the same lot/roll, quantity and carrying
   value. Tracked rolls move in full; unrolled fabric may transfer partially.
   Shipping via transit uses two posted legs: source → transit → destination.
   An optional related transfer must be a different already posted transfer.
6. Damage/write-off uses a negative delta at an existing position. A count uses
   the physically counted quantity; the draft computes the difference and freezes
   expected stock plus the latest movement watermark. Intervening movement makes
   it stale: recount and edit. A matching count needs no adjustment. Gains require
   supported cost; losses use FIFO. Stock receipts/issues here are opening/count
   gains and count/damage/write-off issues, not future purchasing documents.
7. Draft editing uses a version check. Release reservations first. Draft
   cancellation requires a reason, releases commitments and allocates no number.
   Lot/roll codes remain reserved for audit, including cancelled drafts.
8. Posted corrections use **Reverse this posting** with an open-period date and
   reason. The new linked document exactly compensates original movements,
   allocations, values and journal. Reverse downstream consumption first if its
   original layer cannot support reversal. Reversing a reversal is unavailable.
9. **Reconciliation** compares balances/FIFO caches with immutable histories,
   checks movement allocation totals and inventory value against its GL control.
   Rebuilding requires authorization and a reason; it changes only projections.
   History disagreement requires investigation. Physical differences require a
   count adjustment. Stock, movement and document screens have print controls.

## Authorization

`warehouses.view/manage` protects setup. Inventory permissions are `view`,
`manage` (drafts, cancellation, quality), `post`, `reverse`, `reserve`
(reserve/release) and `reconcile` (projection repair), each prefixed `inventory.`.
Policies, middleware, Form Requests and transactional service checks protect
actions. Services reload active actor permissions. Seed configuration extends
super-admin; other roles need explicit assignment through existing role screens.
A preparer/poster requires both permissions; mandatory two-person approval is not
part of the approved lifecycle. Evidence is a reference and explanation, not an
attachment upload or an automated claim that the physical stock was verified.

## Tables and migration

One additive forward-only migration:
`2026_10_21_000002_create_warehouse_and_inventory_tables.php`.

| Dependency group | New approved tables |
| --- | --- |
| Documents | document_types, document_sequences, documents, document_status_events |
| Minimum inventory accounting | ledger_accounts, cost_centres, posting_accounts, journal_entries, journal_lines |
| Storage/physical identity | warehouses, warehouse_locations, stock_lots, fabric_rolls, stock_positions |
| Stock/value ledgers | stock_balances, stock_movements, stock_cost_layers, inventory_layer_allocations, inventory_value_entries, inventory_cost_links, stock_reservations |
| Typed documents | inventory_transfers, inventory_transfer_lines, inventory_adjustments, inventory_adjustment_lines |

There are **25 new tables**, bringing the implemented application schema to **58**
tables excluding migration bookkeeping. Full type/null/default/PK/FK/UQ/index
specifications are in the approved `docs/database/` chapters. Every new table uses
InnoDB, restrictive FKs and matching candidate keys. Quantities are DECIMAL(20,6),
rates (20,8), money (20,4), fractions (20,10). Generated stored
`roll_key=IFNULL(fabric_roll_id,0)` deduplicates positions without rolls.
Index names respect MySQL's 64-character limit. Official numbers use the stable
format `<type prefix>-<financial year ID>-<padded sequence>`. Row checks protect states,
nonzero deltas, balances, reservation limits, costs and journal sides.

The existing `audit_events.document_id` receives its deferred restrictive FK.
`journal_lines.employee_id` is nullable and temporarily constrained to NULL until
a later approved employee migration adds its FK and removes that CHECK. No
employee/payroll module is implemented. Cost centres are an FK prerequisite
without maintenance UI. Accounts 1100 Inventory control, 3000 Opening stock
equity and 5100 Inventory variance are seeded once with purpose mappings.
Reseeding preserves existing mappings; confirm them with the accountant before
live opening stock. No general accounting CRUD, tax or compliance claim exists.

The proposed account/cost-centre `parent_id<>id` CHECK cannot reference an
AUTO_INCREMENT column on MySQL 8.4. It is omitted from these new tables. No tree
editing endpoint is exposed; later tree maintenance must enforce self/descendant
cycle checks under the company lock. This corrects new DDL only, with no existing
data migration. See [MySQL CHECK restrictions](https://dev.mysql.com/doc/refman/8.4/en/create-table-check-constraints.html).

## Posting, locking and idempotency

Warehouse, inventory, quality, reservation and repair writes lock the existing
company singleton **first**, inside a transaction. Catalog and calendar changes
share this mutex. Calendar/document/number-sequence/position/balance/layer locks
follow inside the gate. This conservative single-company serialization prevents
stock races and period-close lock inversion; it limits write throughput. A future
move to finer locks needs a reviewed lock order and new concurrent tests. All
application writes must use the services; direct SQL bypasses cross-row rules.

Inquiries SUM stock movements, including original rows whose headers are now
reversed. Balances are checked rebuildable projections, never editable stock.
FIFO remaining quantities derive from layer allocations. Posting validates all
touched projections before writes. No final position may be negative or
over-reserved, and no physical roll may have positive quantity at two locations.
Used location warehouse/type/custodian and stock identity fields are immutable.
Catalog item/SKU/classification/measurement/specification and finished variant
identity are frozen once referenced by a lot.

Draft creation uses a UUID with an input hash in its immutable creation audit.
An identical retry returns the existing document; different data under that key
is rejected. Draft payload_hash remains NULL. Edits use lock_version. Posting
uses a separate globally unique UUID plus BINARY(32) SHA-256 of typed payload.
A committed identical retry returns the original result. A reused posting key
cannot post another document. Numbers are locked per type/year and allocated
inside successful posting; no MAX+1 numbering. New financial years initialize
inventory sequences, with a mutex-protected fallback at first post.

Transfer drafts preserve FIFO source IDs. Posting rejects a stale allocation
plan instead of silently replacing it. Counts check a movement watermark, so
even an intervening equal receipt/issue pair invalidates the snapshot. Posting
rejects closed periods/years, future business dates and dates preceding the
latest touched-position movement or FIFO acquisition. Business dates use the
configured company timezone; timestamps and reservation expiry input use UTC.

Reservations attach to draft transfers in this phase. Their remaining commitment
is quantity − fulfilled − released. Reserve, fulfil and release are audited.
Expiry alone does not release stock: authorized transactional release updates the
reservation and balance cache. Expired commitments block posting until released
or replaced. There is no scheduled release worker in this phase.

## Value and reversal integrity

BCMath decimal strings avoid binary floats. Positive quantity × cost rounds
half up to four PKR decimals. Partial issues take proportional current layer
value; final depletion takes the entire remaining value, including rounding
residual. Transfers create equal opposite quantities/values and preserve original
FIFO acquisition dates. Ordinary issues record a fraction-1 cost link to expense;
transfers link to the new destination layer. Zero-cost stock has quantity
allocations but no zero-value entries or empty journal.

Openings debit Inventory and credit Opening equity. Count gains/losses and
damage/write-off use Inventory variance as the offset. Aggregated journal debit
and credit totals must match exactly before commit. Pure same-item transfers
need no GL journal because inventory ownership/account and total value are
unchanged. Reversals mirror original journals and values, not current rates. A restored
adjustment line displays the derived average original booked cost; exact layer
value entries remain authoritative when that display rate rounds.

Reversals invert **original allocations into the original layers**. Positive
compensation restores an issued layer instead of creating a new receipt layer;
negative compensation consumes the original receipt layer. This specific-cost
exception makes downstream-first reversal possible without losing FIFO lineage.
Original cost links remain traceable through compensated movements/values; no
new forward links are invented by reversal. Future late-cost propagation must
net compensations. Late-cost adjustment and transformed-output costing remain
future workflows.

## Implementation files and routes

- Document, warehouse, lot/roll, stock/FIFO/reservation and journal models under
  `app/Models`, with relationships, casts and immutable history guards.
- `Inventory/Services`: InventoryGuard, InventoryService, StockLedger,
  InventoryInquiry; `Support/Decimal`, InventoryController, two Form Requests,
  InventoryPolicy, module routes and seven Blade screens/partials.
- Warehouses: WarehouseService, WarehouseController, SaveWarehouseRequest,
  WarehousePolicy, two Blade screens, two factories and five routes.
- InventoryConfigurationSeeder; authorization/audit/provider/navigation updates;
  calendar sequence initialization and Catalog stock-reference guards.
- InventoryTest, InventoryConcurrencyTest, InventoryFixtures and independent PHP
  worker; Phase 5 assertion updated to check no quantity columns on item masters.

Warehouse routes: GET `/warehouses`, `/warehouses/create`,
`/warehouses/{record}/edit`; POST `/warehouses`; PUT `/warehouses/{record}`.
The `location=1` parameter selects location setup.

Inventory GET routes: `/inventory`, `/inventory/movements`,
`/inventory/reconciliation`, `/inventory/documents`,
`/inventory/documents/create`, `/inventory/documents/{document}`,
`/inventory/documents/{document}/edit`. POST/PUT documents create/update drafts.
POST `/inventory/documents/{document}/{post|cancel|reserve|release|reverse}`
performs lifecycle actions. POST `/inventory/reconciliation` rebuilds projections;
POST `/inventory/lots/{lot}/quality` audits quality changes. No delete endpoints.

## Review checklist and remaining scope

- [x] Shared parties/vendor profiles and configurable future processing preserved.
- [x] No editable item quantity, invented opening stock or stock seeder.
- [x] Quantity/value/allocation/journal/audit history retained and compensated.
- [x] FIFO balances, transfer conservation, stale counts and rollback tests.
- [x] Reservations, quality, permissions, version checks and unique posting keys.
- [x] Independent-process posting/duplicate-request/reservation race tests.
- [x] Blade setup, stock/documents/movements, printable reports, reconciliation.
- [ ] Run DDL and full tests on required MySQL 8.4 before production deployment.
- [ ] Obtain operational/accountant acceptance of opening evidence and accounts.
- [ ] Complete browser/device acceptance of interactive multi-line forms.

No purchasing receipts/payables, processing-job dispatch/return matching,
roll split/join lineage, manufacturing/packing, sales, cost-only/late-cost changes,
third-party ownership, import wizard, scheduled expiry or general journal CRUD.
Contractor custody is a location dimension ready for later job documents.
Screens use base units only, so alternate-unit factor snapshots are unnecessary.
Partial roll consumption retains its identity; moving a physical portion requires
the future roll-split workflow. No Phase 7 work is authorized.

See PROJECT_STATE.md for executed quality checks and the completion report.
Concurrent tests recreate only the hard-guarded isolated test database and use
separate PHP/PDO processes. Never point tests at application or production data.

## Executed checks

Final full suite: **60 tests passed, 388 assertions**, including three actual
independent-process races. Pint check, Composer strict validation/platform checks,
Vite production build, Blade cache compilation and route cache/list/clear passed.
75 application routes excluding vendor routes, including 17 Phase 6 definitions.
Documentation UTF-8/fence and Markdown document-link scans passed. Runtime checks
used PHP 8.3.35 and MariaDB 10.4.32; target MySQL 8.4 and interactive browser
acceptance remain outstanding. No application database migration/reset was run.
