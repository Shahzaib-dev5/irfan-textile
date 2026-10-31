# Complete database schema and table inventory

## Processing fabric flow addition (2026-10-27)

[Simple fabric flows](PROCESSING_FABRIC_FLOW.md) add `processing_fabric_flows`
with a unique request UUID, canonical input hash, JSON evidence, draft/posted status,
creator FK and nullable unique document/onward-document FKs. CHECKs require posted
flows to have a document and onward links to belong to inbound flows. Existing
Processing, stock and journal schemas and old payload hashes are unchanged.
`ProcessingFabricSetupSeeder` adds the per-suit embroidery service without changing
existing piece-rate contracts. No component count is an independent stock balance.


## Phase 12 implemented finance slice (2026-10-26)

Added bank_accounts, payments, receipts, settlement_batches, settlement_allocations,
bank_statements, bank_statement_lines, bank_reconciliations,
bank_reconciliation_matches, purchase_credit_notes and purchase_credit_note_lines.
Journal/calendar/open-item/ledger tables are reused unchanged. See the approved
[commercial design](database/COMMERCIAL_FINANCE.md) and the actual Phase 12 constraints,
column omissions, migration impact and file list in [ACCOUNTING_FINANCE.md](ACCOUNTING_FINANCE.md).
Application table target after migration: 116 (117 including migrations).


## Phase 11 implemented database slice (2026-10-26)
The additive Wholesale migration implements eleven approved F33-F39/F41-F44
operational tables: sales_orders, sales_order_lines, sales_dispatches,
sales_dispatch_lines, sales_invoices, sales_invoice_lines,
sales_invoice_dispatch_matches, sales_returns, sales_return_lines,
sales_credit_notes, sales_credit_note_lines. 105 application tables after migration
(106 with Laravel migrations). Approved tax children F40/F45 remain future.
Stock reservations gain a nullable sales_order_line_id FK and composite parent
FK to sales_order_lines(id,document_id); existing transfer rows remain NULL.
Actual constraints/columns and zero-tax-only limitations: [SALES.md](SALES.md).
Phase 1 below remains the full proposed architecture, not all implemented tables.

## Phase 10 executable slice (2026-10-26)

Seven approved IP38-IP44 tables bring application tables **87 -> 94**:
product_pack_specs, product_pack_spec_lines, packing_orders,
packing_material_lines, packing_output_lines, packages and package_contents.
Migration 2026_10_26_000001_create_finished_goods_packing_tables.php is additive;
no existing table/column, quantity, source hash or item-type CHECK is rewritten.
Detailed columns remain in [INVENTORY_PRODUCTION.md](database/INVENTORY_PRODUCTION.md).

PACK is a new configured whole-count unit. Existing finished classification can
use SUIT or PACK; product_variants remains SUIT only. There is no PACK/SUIT unit
conversion. Every spec component is unique within that version; saleable content
is SUIT variants and supplies are packaging items in their own base units. Typed
header/line document FKs, unique movements/spec versions/container codes, covering
FK indexes, DECIMAL precision and CHECKs follow the approved schema. Cross-row
component/container/cost conservation is service-enforced. See
[FINISHED_GOODS_COSTING.md](FINISHED_GOODS_COSTING.md). Older counts are historical.

## Phase 9 executable schema (2026-10-26)

The current migrations define **87 application tables**, excluding Laravel
migration bookkeeping: the earlier 78 plus nine approved Production tables:
`bills_of_materials`, `bill_of_material_lines`, `manufacturing_batches`,
`manufacturing_batch_materials`, `production_reports`, `production_material_lines`,
`production_output_lines`, `production_wastage_lines`, `production_cost_allocations`.
The detailed IP29-IP37 specifications remain in
[INVENTORY_PRODUCTION.md](database/INVENTORY_PRODUCTION.md). Migration
`2026_10_24_000001_create_production_tables.php` supplies actual constraints.

One additive integration extension is nullable
`processing_jobs.manufacturing_batch_document_id` (BIGINT UNSIGNED, indexed,
RESTRICT FK to manufacturing_batches.document_id). Existing jobs remain NULL;
no old history is rewritten. Document subtypes retain document_id PK/FK; lines
have document/line uniqueness, quantities/costs/fractions use exact DECIMAL and
movement references are unique when present. Batch progress, WIP and stock totals
are derived rather than editable master columns. See [PRODUCTION.md](PRODUCTION.md).
Older implementation counts below describe prior phases. MySQL 8.4 verification
remains pending; local test execution uses MariaDB 10.4.32.

> Phase 8 implementation (2026-10-21): configurable processing routes/jobs,
> contractor dispatch/custody, partial accepted/rejected returns, loss costing,
> roll lineage and exact-rate contractor bill/AP sources are implemented.
> See [PROCESSING.md](PROCESSING.md) for actual behavior and explicit boundaries.
> Production/sales/payments/tax remain future scope; MySQL 8.4 validation is pending.

Status: **Approved Phase 1 architecture with Phase 2-8 implementation slices**,
2026-10-21. Executable migrations define 78 application tables (excluding migration
bookkeeping): 33 foundation/setup/party/Catalog tables plus the 25 document,
warehouse, stock/FIFO and minimum journal tables listed in WAREHOUSES_INVENTORY.md,
plus ten purchasing/AP-source tables listed in PURCHASING.md and ten processing/
custody/matching tables listed in PROCESSING.md.
The remaining operational tables are architecture only. Target: MySQL 8.4 LTS;
checks so far use MariaDB 10.4.32. The PKR-only currencies master is the documented
Phase 3 extension beyond the approved 138-table architecture inventory.

## Scope and design summary

Phase 5 adds `item_categories`, `items`, `item_unit_conversions`,
`fabric_specifications`, `designs`, `colors` and `product_variants`, reusing
existing `units`. The Phase 2-5 migrations defined 33 tables; Phase 6 added 25, for 58, and Phase 7 adds ten, for 68; Phase 8 adds ten, for 78. No table
is added beyond the approved inventory: collections are `designs.season`,
materials/consumables are classified items. The implemented column, default,
constraint and index specifications are in
[Textile master data](TEXTILE_MASTER_DATA.md). Unit conversions are mutable
entities with both timestamps; future postings must snapshot factors. Item masters retain no quantities; the separate Phase 6 stock ledger now supplies inventory.

The database architecture covers one Pakistani textile company from grey/kora
procurement through configurable outsourced processing, suit manufacture,
packing, wholesale, cash settlement and double-entry books. MySQL 8.4 LTS with
InnoDB is authoritative. English UI, PKR functional currency, business dates in
Asia/Karachi, and UTC event timestamps are proposed defaults.

The schema has **138 tables** across three detailed specifications:

- [Core, identity, parties and catalog — 39 tables](database/CORE_CATALOG.md)
- [Warehouses, inventory, processing, production and finished goods — 44 tables](database/INVENTORY_PRODUCTION.md)
- [Purchasing, sales, accounting and payroll — 55 tables](database/COMMERCIAL_FINANCE.md)

The approved design covers 138 tables. The split is editorial. Shared entities such as `documents`, `parties`, `items`,
`ledger_accounts` and `stock_movements` have one authoritative definition.
Column tables in those domain specifications include full SQL types, nullability,
defaults, PK/FK/UQ/index/check requirements and cross-row posting invariants.
This document is the integration index and common contract. Open business and
accounting policy choices remain listed below and in `DATABASE_DECISIONS.md`;
they must be settled before the affected posting workflows are implemented.

### Phase 3 implementation extension

The Phase 3 request explicitly adds a currency master while preserving approved
decision D01 (PKR only). `currencies` uses `code CHAR(3)` as its primary key and
stores name, symbol, decimal places, active status and timestamps. A database
CHECK permits only `PKR`; the `companies.functional_currency` foreign key points
to this master. The standard seeder provisions `PKR / Pakistani Rupee / Rs`.
This is one additive table beyond the approved 138-table inventory; it does not
enable foreign-currency transactions. See D28 in
[`DATABASE_DECISIONS.md`](DATABASE_DECISIONS.md).

Migrations created 13 tables through Phase 3: Phase 2 `users`, `sessions` and
`password_reset_tokens`, plus Phase 3 `currencies`, `companies`,
`company_settings`, `access_roles`, `permissions`, `user_roles`,
`role_permissions`, `financial_years`, `accounting_periods` and `audit_events`.
The Phase 3 audit table's nullable `document_id` is indexed but has no FK until
the later `documents` root exists; the migration plan records this deferred FK.

### Phase 3 implemented table specifications

All listed tables use InnoDB and `utf8mb4`; ordinary timestamps are
`DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)` and mutable
`updated_at` columns update automatically. FKs use `ON DELETE RESTRICT ON UPDATE
RESTRICT` unless stated. Names below match the migration.

| Table | Columns and types | Keys, defaults and checks |
|---|---|---|
| `currencies` | `code CHAR(3)`; `name VARCHAR(100)`; `symbol VARCHAR(8)`; `decimal_places TINYINT UNSIGNED DEFAULT 2`; `is_active BOOLEAN DEFAULT 1`; timestamps | PK `code`; CHECK `code='PKR'`; CHECK `is_active IN (0,1)`; CHECK decimal places 0-4. Seeded with `PKR`, `Pakistani Rupee`, `Rs`. |
| `companies` | `id TINYINT UNSIGNED DEFAULT 1`; `legal_name VARCHAR(200)`; `trading_name VARCHAR(200) NULL`; `ntn VARCHAR(30) NULL`; `sales_tax_registration VARCHAR(40) NULL`; `address_text TEXT NULL`; `phone VARCHAR(40) NULL`; `email VARCHAR(254) NULL`; `functional_currency CHAR(3) DEFAULT 'PKR'`; `timezone VARCHAR(64) DEFAULT 'Asia/Karachi'`; `fiscal_year_start_month TINYINT UNSIGNED DEFAULT 7`; timestamps | PK `id`; FK `functional_currency -> currencies.code`; CHECK `id=1`, currency is PKR, month between 1 and 12. July is configurable/proposed. |
| `company_settings` | `id BIGINT UNSIGNED AUTO_INCREMENT`; `setting_key VARCHAR(100)`; `value_json JSON`; `updated_by BIGINT UNSIGNED`; timestamps | PK `id`; UQ setting key; FK updated_by -> users.id. JSON is for bounded nonfinancial configuration; company logo stores a private storage path here. |
| `access_roles` | `id BIGINT UNSIGNED AUTO_INCREMENT`; `code VARCHAR(50)`; `name VARCHAR(100)`; `description VARCHAR(500) NULL`; `is_active BOOLEAN DEFAULT 1`; timestamps | PK `id`; UQ code; index `(is_active,name)`; CHECK boolean. Codes are stable and distinct from `party_roles`. |
| `permissions` | `id BIGINT UNSIGNED AUTO_INCREMENT`; `code VARCHAR(100)`; `module_code VARCHAR(40)`; `description VARCHAR(255)`; timestamps | PK `id`; UQ code; index module_code. Permission definitions are code-managed and seeded idempotently. |
| `user_roles` | `id BIGINT UNSIGNED AUTO_INCREMENT`; `user_id`, `access_role_id`, `assigned_by BIGINT UNSIGNED`; timestamps | PK `id`; UQ `(user_id,access_role_id)`; indexes on access_role_id and assigned_by; all three FKs RESTRICT. |
| `role_permissions` | `id BIGINT UNSIGNED AUTO_INCREMENT`; `access_role_id`, `permission_id BIGINT UNSIGNED`; timestamps | PK `id`; UQ `(access_role_id,permission_id)`; index permission_id; both FKs RESTRICT. |
| `financial_years` | `id BIGINT UNSIGNED AUTO_INCREMENT`; `code VARCHAR(20)`; `starts_on DATE`; `ends_on DATE`; `is_closed BOOLEAN DEFAULT 0`; `closed_at DATETIME(6) NULL`; `closed_by BIGINT UNSIGNED NULL`; timestamps | PK `id`; UQ code; index `(starts_on,ends_on)`; FK closed_by -> users.id; CHECK end >= start and consistent closure fields. Cross-row overlap is serialized on companies.id=1. |
| `accounting_periods` | `id BIGINT UNSIGNED AUTO_INCREMENT`; `financial_year_id BIGINT UNSIGNED`; `code VARCHAR(20)`; `starts_on`, `ends_on DATE`; `status VARCHAR(10) DEFAULT 'open'`; `closed_at DATETIME(6) NULL`; `closed_by BIGINT UNSIGNED NULL`; timestamps | PK `id`; UQ `(financial_year_id,code)` and `(id,financial_year_id)`; index `(financial_year_id,starts_on,ends_on)`; FKs to year and closer; CHECK date order and open/closed closure shape. Range containment/overlap is service-validated under locks. |
| `audit_events` | `id BIGINT UNSIGNED AUTO_INCREMENT`; `actor_id BIGINT UNSIGNED NULL`; `document_id BIGINT UNSIGNED NULL`; `entity_table VARCHAR(64)`; `entity_key VARCHAR(100)`; `action VARCHAR(40)`; `before_json`, `after_json JSON NULL`; `request_key CHAR(36)`; `ip_address VARCHAR(45) NULL`; `created_at DATETIME(6) DEFAULT CURRENT_TIMESTAMP(6)` | PK `id`; actor FK -> users.id; indexes `(entity_table,entity_key,created_at)`, `(document_id,created_at)`, `(actor_id,created_at)`, and request_key. No `updated_at`; Eloquent rejects update/delete. Nullable document FK is deferred until `documents` exists. |

The Phase 3 migration also changes `users.created_at` and `users.updated_at` to
non-null `DATETIME(6)` defaults and adds `CHECK(is_active IN (0,1))`. The initial
admin, default roles and permissions are not inserted by HTTP requests: run the
configuration seeders, then the interactive one-time bootstrap command.

### Phase 4 party and vendor-capability implementation

Migration `2026_10_20_000002_create_party_management_tables.php` adds thirteen
tables. `units` and `payment_terms` are approved Phase 1 prerequisites that were
not yet materialized. The remaining eleven are the approved shared-party and
vendor-capability tables, all within the approved 138-table inventory. The
migration is forward-only to protect identities and published rates. Apply it
with the normal migration command; do not reset a database containing business
records.

All tables use InnoDB. Ordinary entities use `BIGINT UNSIGNED AUTO_INCREMENT`
IDs, microsecond timestamps and master `is_active` booleans. Role profiles use
`party_role_id` as both PK and FK with timestamps but no surrogate ID. Published
vendor rates have an auto-increment ID and immutable `created_at` only. FKs
restrict delete and update; indexes support relation lookups and search.

| Table | Columns and types | Keys, defaults and checks |
|---|---|---|
| `units` | `id`; `code VARCHAR(20)`; `name VARCHAR(60)`; `dimension VARCHAR(20)`; `decimal_places TINYINT UNSIGNED DEFAULT 6`; `is_active`; timestamps | PK id; UQ code; active/name index; dimension CHECK (length/mass/count/area/service); precision <= 6. Seeded M, KG, PCS, SUIT, ROLL, PACK. |
| `payment_terms` | `id`; `code VARCHAR(30)`; `name VARCHAR(100)`; `due_days SMALLINT UNSIGNED DEFAULT 0`; `is_active`; timestamps | PK id; UQ code; active/name index. Future invoices snapshot the selected due days. |
| `parties` | `id`; `code VARCHAR(40)`; `legal_name VARCHAR(200)`; `trading_name VARCHAR(200) NULL`; `party_kind VARCHAR(20) DEFAULT 'organization'`; `ntn VARCHAR(30) NULL`; `sales_tax_registration VARCHAR(40) NULL`; `cnic VARCHAR(25) NULL`; `email VARCHAR(254) NULL`; `phone VARCHAR(40) NULL`; `notes TEXT NULL`; `is_active`; timestamps | PK id; UQs code and nullable NTN/sales-tax/CNIC; indexes legal_name/status, phone and email; CHECK kind and active boolean. Names/phones/emails are not unique. |
| `party_addresses` | `id`; `party_id`; `label VARCHAR(60)`; `address_type VARCHAR(20) DEFAULT 'business'`; address lines `VARCHAR(255)`; `city VARCHAR(100)`; nullable province/postal code; `country_code CHAR(2) DEFAULT 'PK'`; `is_active`; timestamps | PK id; party FK; UQ `(party_id,label)`; index `(party_id,address_type,is_active)`; CHECK address type/active. Country code shape validated by request. |
| `party_contacts` | `id`; `party_id`; `name VARCHAR(150)`; nullable designation/email/phone; `is_active`; timestamps | PK id; party FK; index `(party_id,name)`; no contact-name uniqueness. |
| `party_role_types` | `id`; `code VARCHAR(20)`; `name VARCHAR(80)`; `is_active`; timestamps | PK id; UQ code; active/name index; stable seeded codes supplier/vendor/customer. |
| `party_roles` | `id`; `party_id`; `party_role_type_id`; `is_active`; timestamps | PK id; FKs to party/type; UQ `(party_id,party_role_type_id)` and `(id,party_id)`; index `(party_role_type_id,is_active)`; profile/type-code consistency is service-validated. |
| `supplier_profiles` | `party_role_id`; `payment_term_id`; `purchase_notes VARCHAR(1000) NULL`; timestamps | PK/FK party_role_id; FK payment term; payment-term index; one profile per role. |
| `vendor_profiles` | `party_role_id`; `payment_term_id`; `contractor_notes VARCHAR(1000) NULL`; timestamps | PK/FK party_role_id; FK payment term; payment-term index; one profile per role. |
| `customer_profiles` | `party_role_id`; `payment_term_id`; `credit_limit DECIMAL(20,4) DEFAULT 0`; `credit_hold BOOLEAN DEFAULT 0`; `sales_notes VARCHAR(1000) NULL`; timestamps | PK/FK party_role_id; FK payment term; payment-term index; CHECK credit >= 0 and boolean. Credit decisions later lock this profile and calculate full exposure. |
| `processing_services` | `id`; `code VARCHAR(40)`; `name VARCHAR(100)`; `billing_unit_id`; `description VARCHAR(500) NULL`; `is_active`; timestamps | PK id; UQ code; FK billing unit; indexes active/name and unit. Freeze billing basis once used. |
| `vendor_services` | `id`; `vendor_party_role_id`; `processing_service_id`; `lead_time_days SMALLINT UNSIGNED NULL`; `is_active`; timestamps | PK id; FK vendor profile and service; UQ vendor/service; service/active index. This row is the effective-rate mutex. |
| `vendor_service_rates` | `id`; `vendor_service_id`; `effective_from DATE`; `effective_to DATE NULL`; `billing_unit_id`; `unit_rate DECIMAL(20,8)`; `currency_code CHAR(3) DEFAULT 'PKR'`; nullable contract reference; required reason; `approved_by`; `created_at DATETIME(6)` | PK id; FKs service/unit/currency/user; UQ `(vendor_service_id,effective_from)`; period/unit/approver/currency indexes; CHECK end > start, rate >= 0 and PKR. No `updated_at`; rates are append-only except prospective closure of an open interval. |

`PartyConfigurationSeeder` inserts the three stable business role codes, payment
terms, unit conventions and Dyeing, Dan, Combined Dan/Dyeing, Embroidery,
Pressing, Calendaring and Finishing. Dyeing and fabric-length services initially
bill by meter; Embroidery defaults to piece/set billing. Dyeing therefore
supports PKR per meter without a hardcoded process sequence. Rate
selection is by date and returns a source row suitable for a future job snapshot.

## Workflow and aggregate ownership

| Workflow stage | Owner and aggregate | Important effects / next dependency |
|---|---|---|
| Buy grey/kora | Purchasing: purchase order, goods receipt, supplier invoice, return/credit | Receipt creates owned lots/rolls, quantity/value layers and GRNI; bill clears GRNI into AP. |
| Store/transfer | Warehouses and Inventory: location, lot/roll position, transfer, reservation, adjustment | Signed stock ledger, exact layer allocation, rebuildable balances and inventory journal. |
| Outsource finishing | Processing: route version, job, step, dispatch, acceptance | Configurable service sequence; vendor-location custody stays company-owned; accepted output/loss and rate-based accrual. |
| Make suits | Production: BOM, batch, report | Actual input issues, WIP/direct cost, good output, normal/abnormal loss, lot lineage. |
| Pack | FinishedGoods: pack specification/order, package | Consumes materials once, creates actual packed SKU or logistic package membership. |
| Wholesale | Sales: order, dispatch, invoice, return/credit | Reservation/credit validation; dispatch consumes stock, invoice bills matched delivery, return/credit effects stay distinct. |
| Settle and report | Accounting: journals/open items/settlements/bank; HRPayroll | One balanced journal per financial posting, append-only allocations, payroll liability and bank reconciliation. |

Dependencies flow through stable IDs and explicit services. One posting action
coordinates its inventory and accounting effects within a single InnoDB
transaction. A document envelope gives numbering and lifecycle; typed headers,
lines, journal entries, stock facts and open items retain real FKs.

## Complete table inventory

### Core, Identity, Parties and Catalog (39)

`companies`, `company_settings`, `users`, `access_roles`, `permissions`,
`user_roles`, `role_permissions`, `sessions`, `password_reset_tokens`,
`payment_terms`, `parties`, `party_addresses`, `party_contacts`,
`party_bank_accounts`, `party_role_types`, `party_roles`, `supplier_profiles`,
`vendor_profiles`, `customer_profiles`, `processing_services`, `vendor_services`,
`vendor_service_rates`, `units`, `item_categories`, `items`,
`item_unit_conversions`, `fabric_specifications`, `designs`, `colors`,
`product_variants`, `tax_codes`, `tax_rates`, `posting_accounts`,
`document_types`, `document_sequences`, `documents`, `document_status_events`,
`audit_events`, `document_attachments`.

### Warehouses, Inventory, Processing, Production, FinishedGoods (44)

`warehouses`, `warehouse_locations`, `stock_lots`, `fabric_rolls`,
`fabric_roll_lineage`, `stock_positions`, `stock_balances`, `stock_movements`,
`stock_cost_layers`, `inventory_layer_allocations`, `inventory_value_entries`,
`inventory_cost_links`, `stock_reservations`, `inventory_transfers`,
`inventory_transfer_lines`, `inventory_adjustments`, `inventory_adjustment_lines`,
`inventory_cost_adjustments`, `inventory_cost_adjustment_lines`,
`processing_route_versions`, `processing_route_steps`, `processing_jobs`,
`processing_job_steps`, `processing_dispatches`, `processing_dispatch_lines`,
`processing_receipts`, `processing_receipt_lines`, `processing_charge_allocations`,
`bills_of_materials`, `bill_of_material_lines`, `manufacturing_batches`,
`manufacturing_batch_materials`, `production_reports`, `production_material_lines`,
`production_output_lines`, `production_wastage_lines`,
`production_cost_allocations`, `product_pack_specs`, `product_pack_spec_lines`,
`packing_orders`, `packing_material_lines`, `packing_output_lines`, `packages`,
`package_contents`.

### Purchasing, Sales, Accounting, Expenses and HRPayroll (55)

`financial_years`, `accounting_periods`, `ledger_accounts`, `cost_centres`,
`bank_accounts`, `journal_entries`, `journal_lines`, `party_open_items`,
`settlement_batches`, `settlement_allocations`, `payments`, `receipts`,
`payment_tax_lines`, `receipt_tax_lines`, `bank_statements`,
`bank_statement_lines`, `bank_reconciliations`, `bank_reconciliation_matches`,
`purchase_orders`, `purchase_order_lines`, `goods_receipts`, `goods_receipt_lines`,
`purchase_invoices`, `purchase_invoice_lines`, `purchase_invoice_receipt_matches`,
`purchase_invoice_processing_matches`, `purchase_invoice_line_taxes`,
`purchase_returns`, `purchase_return_lines`, `purchase_credit_notes`,
`purchase_credit_note_lines`, `purchase_credit_note_line_taxes`, `sales_orders`,
`sales_order_lines`, `sales_dispatches`, `sales_dispatch_lines`, `sales_invoices`,
`sales_invoice_lines`, `sales_invoice_dispatch_matches`,
`sales_invoice_line_taxes`, `sales_returns`, `sales_return_lines`,
`sales_credit_notes`, `sales_credit_note_lines`, `sales_credit_note_line_taxes`,
`expense_claims`, `expense_claim_lines`, `employees`, `employment_terms`,
`salary_components`, `employee_salary_components`, `attendance_entries`,
`payroll_runs`, `payroll_run_employees`, `payroll_run_lines`.

## Common physical schema conventions

These conventions apply across the three detailed specifications unless a table
explicitly overrides them:

- Storage: InnoDB, `utf8mb4` with one fixed deployment-wide collation, strict SQL
  mode. All FK integer types/signedness match exactly. Every FK references a
  primary/explicit unique key; every FK has a supporting leftmost index.
- Ordinary entity: `id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY`,
  `created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)`,
  `updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6)`.
  Master rows add `is_active BOOLEAN NOT NULL DEFAULT 1` and an enforced boolean
  CHECK. Immutable posted facts omit `updated_at`; framework session tables are
  explicitly exempt. Typed document headers use `document_id BIGINT UNSIGNED`
  as PK/FK to `documents.id` instead of an independent ID.
- Business tables use explicit columns, not generic EAV. JSON is restricted to
  bounded settings and redacted audit snapshots, not relationships, amounts,
  quantities or journals. Text uses utf8mb4. Business dates are DATE; event times
  DATETIME(6) UTC.
- Functional currency is PKR. Money `DECIMAL(20,4)`, quantities
  `DECIMAL(20,6)`, unit prices/rates `DECIMAL(20,8)`, conversion/allocation
  factors `DECIMAL(20,10)`, percentages `DECIMAL(9,6)` unless explicitly noted.
  Suit-count units use integral precision. No FLOAT/DOUBLE for business facts.
- The physical specification uses `VARCHAR` with CHECK for lifecycle and
  configurable process codes; the finance specification uses MySQL `ENUM` only
  for closed financial/document classifications. This split is intentional:
  route/service choices remain data, while bounded ledger directions and
  calculations have DB-closed sets. Adding a closed-set value requires a reviewed
  schema migration. CHECK is for same-row facts only and nullable operands require
  care.
- FKs default to `ON DELETE RESTRICT ON UPDATE RESTRICT`. Posted rows are
  immutable; correction is by linked compensation, return, credit or cost
  adjustment. Master deactivation preserves history. No production reset/drop is
  part of this design.
- Document lines have `UNIQUE(document_id,line_no)` and point to typed header.
  Draft edits lock their root and check `lock_version`; a successful post assigns
  the document number and posting key atomically. Documents use registered
  subtype codes, not arbitrary SQL table names.

## Module dependencies

| Module | Owns | Depends on |
|---|---|---|
| Core | company, documents, sequences, audit, attachments | Identity and Accounting calendar |
| Identity | users and authorization roles/permissions | Core actor references |
| Parties | parties, role types, profiles, contacts/addresses | Core/users, Catalog units for service profiles |
| Catalog | units, items, fabric specs, designs/colors/variants, taxes | Parties/users for approval; Accounting ledger accounts |
| Warehouses | warehouses and locations | Parties vendor role for custody location |
| Inventory | stock positions/rolls, quantity/value/cost ledgers, reservations, transfers/adjustments | Core documents, Catalog, Warehouses, Accounting mappings |
| Purchasing | orders, receipts, supplier bills/returns/credits | Parties supplier role, Catalog, Inventory, Accounting |
| Processing | route/job/step, challan, receipt, charge | Parties vendor role/capabilities, Catalog, Inventory, Purchasing, Accounting |
| Production | BOM, batch, report, output/waste/cost | Catalog, Inventory, Accounting |
| FinishedGoods | pack spec/order/package | Catalog, Inventory, Production |
| Sales | order, dispatch, invoice, return/credit | Parties customer role, Catalog, Inventory, FinishedGoods, Accounting |
| Accounting | financial year/period, chart, journal/open item/settlement, bank | Core, Parties, all posting services |
| HRPayroll | employees, employment/attendance/payroll | Parties identity, Identity actor, Accounting |
| Reporting | read-only reports/projections | Posted facts across modules |

## Critical integrity policy

`stock_movements` is the quantity ledger. On-hand is its posted signed sum;
availability also accounts for active reservations. `stock_balances` and remaining
layer quantity are rebuildable projections updated under position/layer locks.
`inventory_value_entries` is the separate signed carrying-value ledger. FIFO
layers preserve lot/roll source; inventory cost links distribute a consumed cost
exactly to descendant stock or expense, including costs billed after use. The
company owns stock at warehouse, transit and contractor locations; contractor
dispatch changes custody only. No vendor invoice creates an ownership sale.

One financial document has one balanced journal sidecar. The posting transaction
checks debit = credit, period, account and dimensions before marking the document
posted. Party payable/receivable open items originate from control journal lines;
append-only settlement allocations derive remaining exposure. No independent AP,
AR or party running balance can override the GL. Inventory valuation and its
inventory-control GL reconcile at a common cutoff.

Manufacturing costs follow actual quantity/layer genealogy. Eligible materials,
contractor charges, direct labor and approved overhead capitalize once; normal
loss is absorbed into good outputs, abnormal loss is expensed, recoverable scrap
reduces primary output cost. Per-suit actual unit cost is each accepted product
variant's allocated output cost divided by good suit count, with deterministic
rounding. Read [BUSINESS_RULES](BUSINESS_RULES.md) and the physical specification
for worked examples and posting invariants.

## Risks, limits and approval gates

No database CHECK can balance journal child rows, prevent overlapping effective
intervals or cap aggregate invoice/return/allocation quantity. Services must lock
the existing stable parent row, validate aggregate state and persist effects in
one transaction; future tests must exercise concurrent writers. Multi-role party
profile type, subtype/document-kind matching, and typed line to source movement
document membership also require posting validation where the FKs cannot express
the complete condition. MySQL checks FKs immediately; schema dependency cycles
must be broken with later FK-addition migrations, not deferred constraints.

Approval-sensitive policy decisions are itemized in
[DATABASE_DECISIONS](DATABASE_DECISIONS.md): company-only ownership, FIFO,
late-cost propagation, dispatch as transfer of control, tax timing, loss/overhead,
financial year and payroll. Pakistan statutory tax/payroll values are not assumed.
Package provenance is not serial-level dispatch enforcement. Complex co-products,
quality rework loops and third-party-owned cloth need concrete business examples
before extension.

The independent review matrix and 20 future acceptance scenarios are in
[DATABASE_REVIEW](DATABASE_REVIEW.md). The acyclic creation sequence and bridge
FKs are in [MIGRATION_PLAN](MIGRATION_PLAN.md). Mermaid relationships are in
[ERD](ERD.md).
