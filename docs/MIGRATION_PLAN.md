# Proposed migration dependency plan

## Processing fabric flow addition (2026-10-27)

[Simple fabric flows](PROCESSING_FABRIC_FLOW.md) add `processing_fabric_flows`
with a unique request UUID, canonical input hash, JSON evidence, draft/posted status,
creator FK and nullable unique document/onward-document FKs. CHECKs require posted
flows to have a document and onward links to belong to inbound flows. Existing
Processing, stock and journal schemas and old payload hashes are unchanged.
`ProcessingFabricSetupSeeder` adds the per-suit embroidery service without changing
existing piece-rate contracts. No component count is an independent stock balance.


## Phase 12 additive order (2026-10-26)

After Phase 11, run 2026_10_26_000003_create_finance_settlement_tables, then
2026_10_26_000004_create_purchase_financial_credits. Eleven new tables; no historical
backfill or balance seed. FK names are explicitly shortened where needed for MySQL's
identifier limit. Scoped FinanceSetupSeeder adds document types and existing-admin
permissions; it does not assign users or create bank accounts. Back up first; verify
original-table fingerprints after activation. Never roll back posted finance tables
or reset the application DB. See [ACCOUNTING_FINANCE.md](ACCOUNTING_FINANCE.md).


## Phase 11 additive order (2026-10-26)
After Phase 10, run 2026_10_26_000002_create_wholesale_sales_tables.php:
orders -> order lines -> dispatches -> dispatch lines -> invoices -> invoice lines
-> immutable dispatch matches -> returns -> return lines -> credit notes -> credit
lines -> nullable reservation-line/composite ownership FKs -> CHECK constraints.
Existing tables/history remain intact; no reset. Backup first; scoped SalesSetupSeeder
adds configuration and role grants, never users, stock or balances. Exact applied
status and target-DB limits: [PROJECT_STATE.md](PROJECT_STATE.md). Down removes
new objects, so it is unsuitable for posted commercial data without an approved
migration/reversal plan. See [SALES.md](SALES.md).

## Phase 10 additive migration order (2026-10-26)

After Phase 9, 2026_10_26_000001 creates product_pack_specs, spec lines,
packing_orders, material lines, output lines, packages, then package_contents.
All reference existing Catalog/documents/stock tables; no cycle needs disabled
FK checks. Application total becomes 94. Constraints/precision are detailed in
FINISHED_GOODS_COSTING and the executable migration. Down is destructive for new
history and is not an operational reversal; never reset the application DB.

FinishedGoodsConfigurationSeeder adds only PACK and PK document configuration.
FinishedGoodsSetupSeeder is the scoped existing-install admin upgrade: preserves
other roles, adds only Finished Goods grants and audits attachments. DatabaseSeeder
includes configuration for fresh installs; do not run demo/business seeding.
Private backup and verification precede the authorized local migration. Actual
execution/target DB limits are recorded in PROJECT_STATE and DEPLOYMENT.

## Phase 9 local execution recorded (2026-10-26)

Explicitly authorized follow-up: the existing 2026_10_24_000001 Production migration
ran successfully on local irfan_textile as batch 7 after a private SQL backup.
No new migration, data reset or old-history rewrite was needed. ProductionSetupSeeder
now provides the scoped, audited admin/configuration upgrade. Operational row counts,
document payload/status hashes and other-role grants were verified unchanged.
This local MariaDB result does not replace target MySQL 8.4 validation.

## Phase 9 additive migration (2026-10-26)

After the existing Phase 8 schema, apply
`2026_10_24_000001_create_production_tables.php` in the ordinary Laravel migration
sequence. It creates BOM headers/lines, batch headers/material plans, report
headers/materials/outputs/wastage/cost allocations in dependency order, then adds
nullable indexed processing_jobs.manufacturing_batch_document_id referencing
manufacturing_batches.document_id. Nine new tables bring the application total
to 87. No existing column/data rewrite or destructive reset is required.

ProductionConfigurationSeeder provisions only MB/PR numbering types and minimum
production posting-account purposes; IdentityAuthorizationSeeder provisions five
permissions through the existing role system. Existing accounts/mappings are
preserved. No live migration/seeding was performed in this implementation turn.
Review backup/maintenance/rollback procedures and verify MySQL 8.4 before intended
production deployment. A down migration removes only this new slice, but is not
a safe operational rollback once manufacturing history exists. See
[DEPLOYMENT.md](DEPLOYMENT.md) and [PRODUCTION.md](PRODUCTION.md).

> Phase 8 implementation (2026-10-21): configurable processing routes/jobs,
> contractor dispatch/custody, partial accepted/rejected returns, loss costing,
> roll lineage and exact-rate contractor bill/AP sources are implemented.
> See [PROCESSING.md](PROCESSING.md) for actual behavior and explicit boundaries.
> Production/sales/payments/tax remain future scope; MySQL 8.4 validation is pending.

Status: Phase 1 dependency design plus the executed Phase 2/3/4/5/6/7 implementation
slices. The approved 138-table business schema remains mostly unimplemented.
The actual migrations are recorded below; future migrations must preserve this
acyclic FK order and the approved table names.

## Conventions

Use monotonically numbered, date-prefixed names such as
`YYYY_MM_DD_NNNNNN_create_identity_tables.php`, one coherent dependency group per
migration. Keep FK names explicit and globally unique within MySQL's identifier
limits. Prefer creating a referenced table before its FK. If one unavoidable
cycle remains, create the second table without that FK and add it in a named,
later `add_<table>_<column>_foreign_key.php` migration. Do not disable
`foreign_key_checks`, and do not rely on deferred FK validation. Each migration
must be safe to apply in order on an empty schema and reviewed for MySQL 8.4
compatibility. Production rollback is not presumed safe; never run `fresh`,
destructive `down`, or reset against production.

All ordinary IDs are `BIGINT UNSIGNED`; match FK type/signedness and exact string
charset/collation. Add explicit PK/UQ/CHECK and FK-support indexes in the same
migration as each table. Use InnoDB and explicit precision. Populate static role,
permission, document-type and unit configuration through deterministic seeders
after their tables exist, using stable codes rather than numeric IDs.

## Ordered groups

### Implemented foundation and Phase 3-5 migrations

1. `0001_01_01_000000_create_users_table.php` creates `users`, `sessions` and
   `password_reset_tokens`.
2. `2026_10_20_000001_create_identity_company_and_calendar_tables.php` creates
   `currencies`, `companies`, `company_settings`, `access_roles`, `permissions`,
   `user_roles`, `role_permissions`, `financial_years`, `accounting_periods` and
   `audit_events`, in FK order. It normalizes `users` timestamps to non-null
   `DATETIME(6)` defaults and adds the active-status boolean CHECK.
   Run `db:seed` after migration to insert the PKR row and stable permission/role
   definitions; run the one-time interactive `erp:admin:create` command last.
3. `2026_10_20_000002_create_party_management_tables.php` creates approved
   prerequisite masters `units` and `payment_terms`, followed in dependency order
   by `parties`, contacts/addresses, role types/roles, one-to-one profiles,
   processing services, vendor capabilities and immutable rate history. It adds
   explicit FK/search indexes, role/status checks and interval/rate constraints.
   Seed stable values with `PartyConfigurationSeeder` after migration. This
   migration's rollback deliberately throws to protect party and rate history;
   use reviewed forward fixes for production corrections.

4. `2026_10_21_000001_create_textile_catalog_tables.php` creates categories
   (self-FK), then items, item conversions and fabric specifications, followed by
   designs, colors and finished variants. It reuses Phase 4 units and adds no
   stock or unapproved collection table. Seed reference units/categories with
   `CatalogConfigurationSeeder`. The migration is forward-only to preserve
   master identities and future document references.

The **Phase 2-5 migrations currently define 33 tables**. `audit_events.document_id` FK is deferred because `documents` is not yet
created. Add its named FK after the document-root migration, checking that all
current values are NULL before enforcing it. Phase 3's migration `down()` throws
to prevent accidental deletion of configured/audit history; use an explicitly
reviewed, backed-up migration for any future schema correction.

### Planned business schema dependency groups

| Order | Migration group | Tables | Dependency rationale |
|---:|---|---|---|
| 1 | Company and identity base | `companies`, `users`, `access_roles`, `permissions`, `sessions`, `password_reset_tokens` | Root singleton and actor tables. Sessions/reset infrastructure may be conventional framework migrations. |
| 2 | Identity joins and settings | `company_settings`, `user_roles`, `role_permissions` | FKs now target company/users/role/permission. |
| 3 | Accounting calendar and chart | `financial_years`, `accounting_periods`, `ledger_accounts`, `cost_centres`, `bank_accounts` | Financial years precede periods; accounts precede tax/config and journals. |
| 4 | Shared party and terms | `payment_terms`, `parties`, `party_addresses`, `party_contacts`, `party_bank_accounts`, `party_role_types`, `party_roles`, `supplier_profiles`, `vendor_profiles`, `customer_profiles` | Profiles reference role rows and payment terms. Seed three role types by code. |
| 5 | Units, catalog and tax | `units`, `item_categories`, `items`, `item_unit_conversions`, `fabric_specifications`, `designs`, `colors`, `product_variants`, `tax_codes`, `tax_rates`, `processing_services`, `vendor_services`, `vendor_service_rates` | Units/accounts/users/roles exist. Rates follow service capabilities. |
| 6 | Document registration/root and employee identity | `document_types`, `documents`, `document_sequences`, `document_status_events`, `audit_events`, `document_attachments`, `posting_accounts`, `employees` | Documents reference users, types, years, periods. Composite FK uses already unique `(accounting_periods.id,financial_year_id)`. Sequences reference year and type. Posting account map follows GL. Employee master follows parties and cost centres, before journal lines that may dimension payroll postings. |
| 7 | Core physical dimensions | `warehouses`, `warehouse_locations`, `stock_lots`, `fabric_rolls`, `stock_positions`, `fabric_roll_lineage` | Locations depend on warehouse/vendor profile; lots on items/documents; positions on lot/location/roll. |
| 8 | Quantity and cost ledgers | `stock_balances`, `stock_movements`, `stock_cost_layers`, `inventory_layer_allocations`, `inventory_value_entries`, `inventory_cost_links`, `stock_reservations` | Stock movements reference documents/positions; layers and allocations reference ledger history; cost links reference layers and GL accounts. |
| 9 | Accounting books and open items | `journal_entries`, `journal_lines`, `party_open_items`, `settlement_batches`, `settlement_allocations`, `payments`, `receipts`, `payment_tax_lines`, `receipt_tax_lines` | Journal headers extend documents; lines reference accounts/parties/cost centers; open items reference control lines; settlement and cash docs follow those. |
| 10 | Inventory document types | `inventory_transfers`, `inventory_transfer_lines`, `inventory_adjustments`, `inventory_adjustment_lines`, `inventory_cost_adjustments`, `inventory_cost_adjustment_lines` | Typed headers follow documents; lines follow positions, stock movements, valuation and accounts. |
| 11 | Configurable route and processing plan | `processing_route_versions`, `processing_route_steps`, `processing_jobs`, `processing_job_steps` | Services, items, parties, routes and documents are ready. |
| 12 | Contractor movement/acceptance | `processing_dispatches`, `processing_dispatch_lines`, `processing_receipts`, `processing_receipt_lines` | Headers follow jobs/documents; lines follow custody positions and movement ledger. |
| 13 | BOM, batches and reporting | `bills_of_materials`, `bill_of_material_lines`, `manufacturing_batches`, `manufacturing_batch_materials`, `production_reports`, `production_material_lines`, `production_output_lines`, `production_wastage_lines`, `production_cost_allocations` | Product variants/catalog and inventory available; source cost journal lines/accounts already exist. |
| 14 | Pack definitions and packages | `product_pack_specs`, `product_pack_spec_lines`, `packing_orders`, `packing_material_lines`, `packing_output_lines`, `packages`, `package_contents` | Catalog/stock and document roots exist; output and input lines precede package content. |
| 15 | Purchasing documents | `purchase_orders`, `purchase_order_lines`, `goods_receipts`, `goods_receipt_lines`, `purchase_invoices`, `purchase_invoice_lines`, `purchase_invoice_receipt_matches`, `purchase_invoice_processing_matches`, `purchase_invoice_line_taxes`, `purchase_returns`, `purchase_return_lines`, `purchase_credit_notes`, `purchase_credit_note_lines`, `purchase_credit_note_line_taxes` | Supplier roles/catalog, processing acceptance, inventory and accounting are ready. |
| 16 | Cost bridge and delayed billing bridge | `processing_charge_allocations`; any explicitly deferred FK from `inventory_cost_adjustments` to invoice-match details | This bridge depends on both accepted processing/invoice matches and inventory cost adjustments; create last to avoid cycle. |
| 17 | Sales documents | `sales_orders`, `sales_order_lines`, `sales_dispatches`, `sales_dispatch_lines`, `sales_invoices`, `sales_invoice_lines`, `sales_invoice_dispatch_matches`, `sales_invoice_line_taxes`, `sales_returns`, `sales_return_lines`, `sales_credit_notes`, `sales_credit_note_lines`, `sales_credit_note_line_taxes` | Customer profiles, inventory, package rows, stock journals and account mappings are ready. |
| 18 | Bank reconciliation | `bank_statements`, `bank_statement_lines`, `bank_reconciliations`, `bank_reconciliation_matches` | Payments, receipts, bank accounts and journal lines exist. |
| 19 | Expense and payroll | `employment_terms`, `salary_components`, `employee_salary_components`, `attendance_entries`, `expense_claims`, `expense_claim_lines`, `payroll_runs`, `payroll_run_employees`, `payroll_run_lines` | Employee identity exists since group 6; dependent terms and payroll tables follow accounting journals, documents and settlement infrastructure. |

### Known ordering corrections before actual migration authoring

The groups above are coarse dependency layers, not permission to put all listed
tables into one very large migration. The first `vendor_service_rates` references
`users`, already created. `documents` references periods; periods do not reference
documents. `employees` is explicitly created at group 6 after parties and cost
centres, before group 9 `journal_lines` can reference an employee dimension.
`production_cost_allocations` references journal lines and is safely after group
9. Verify exact FK targets from each spec during migration authoring.

`purchase_invoice_lines` depend on `processing_job_steps`, so
purchase docs must remain after group 12. `sales_dispatch_lines.packing_package_id`
depends on `packages`, so sales follows group 14.

## Cycles and bridge handling

- `documents` → `accounting_periods` → `financial_years` is acyclic in this
  direction; keep `financial_years`/`accounting_periods` before documents.
- `journal_entries.document_id` extends the existing document root; documents
  do not FK back to journals. `party_open_items` follows journal lines.
- `stock_movements.document_id` points to the root document, not to every typed
  line. Typed lines point to movement IDs. A posting service verifies both
  document IDs match; no polymorphic FK is claimed.
- `processing_charge_allocations` references commercial invoice match and
  inventory cost adjustment line, so it follows both. No cost adjustment line
  should FK to the allocation table; provenance is reached through the allocation.
- If exact schema review finds a genuine mutual FK, create both tables, then add
  one FK in a later explicit migration. Document why and test create/rollback on
  the exact MySQL 8.4 target.

## Naming and change policy

Use plural snake_case table names and stable singular snake_case FK names such as
`vendor_party_role_id`. PK names `id`, except one-to-one document headers
(`document_id`) and one-to-one projections (`stock_position_id`). Indexes use
`idx_<table>_<columns>`, uniques `uq_<table>_<columns>`, checks `chk_<table>_<rule>`,
FKs `fk_<child>_<parent>`. Keep identifiers within MySQL limits and inspect
generated SQL before applying.

Later approved changes use additive expand/backfill/validate/constrain steps when
data exists. Never repurpose a column or modify a posted historical snapshot.
Any change to an approved key, ownership/cost policy, table split or precision
requires a written migration impact assessment and explicit schema review first.

## Migration review checklist

- [ ] Every table/FK is in the order above or has a documented reason and later FK migration.
- [ ] Every table uses exact agreed type, nullability, default, PK, FK, UQ, CHECK and index.
- [ ] No FK targets a nonunique index or mismatched signedness/collation.
- [ ] No DB FK checks are disabled to conceal dependency or invalid data.
- [ ] FK and CHECK behavior is verified against MySQL 8.4, including NULL and immediate checking.
- [ ] Decimal precision, rounding and generated nullable-key expressions are verified.
- [ ] `php artisan migrate` on an empty disposable MySQL 8.4 database succeeds in order.
- [ ] Down migrations are tested only on disposable data and never used as production rollback assumptions.
- [ ] Schema diff has no dropped/renamed approved field or destructive default/backfill.

## Phase 6 executable migration order

After the existing Phase 2-5 migrations, apply
`2026_10_21_000002_create_warehouse_and_inventory_tables.php`:

1. Document types, sequences, roots and status events; then add the existing
   audit_events.document_id FK.
2. Ledger accounts, cost centres, posting accounts, journal headers and lines.
   The employee dimension is CHECK-constrained to NULL pending the future
   employee master/FK; do not create payroll to satisfy an unused dimension.
3. Warehouses, locations (vendor profile prerequisite already exists), lots,
   rolls, positions and balances.
4. Stock movements, FIFO layers, allocations, value history, cost links,
   reservations, then typed transfer and adjustment headers/lines.
5. Seed InventoryConfigurationSeeder through DatabaseSeeder. First-create
   semantics preserve account mappings. No initial quantities are seeded.

This is additive forward-only DDL. It refuses destructive down migration. No
application database migration/reset was run in Phase 6; tests install in an
isolated disposable schema. Cross-engine checks must still run on MySQL 8.4.
Later employee support must remove the temporary NULL-only journal CHECK while
adding the real employee FK. Later roll lineage/cost-only corrections remain
unimplemented; no dependency is concealed by disabling FK checks.

## Phase 7 executable migration

After Phase 6, apply `2026_10_21_000003_create_purchasing_tables.php`:

1. Purchase order header/lines, using existing documents, Supplier profiles,
   warehouses, payment terms, items and units.
2. Goods receipt header/lines, using order lines and existing stock positions.
3. Purchase invoice header/lines and receipt-match children.
4. Purchase return header/lines and immutable party_open_items journal sources.
5. PurchasingConfigurationSeeder registers document types, permissions and static
   GRNI/AP/return-clearing purpose mappings through DatabaseSeeder.

InnoDB, DATETIME(6) defaults, restrictive candidate-key FKs, unique movement/source
keys, decimals and checks are deterministic. FK names use a stable short hash to
respect the 64-character identifier limit. Roll/lot/position tables are reused.
Unused future tax/processing columns remain NULL-only with no dangling foreign
values; later authorized migrations must replace subset checks and add their
FKs. Migration down refuses destructive rollback. No application DB migration or
reset was performed; all test writes remain in the isolated disposable schema.

## Phase 8 installation order

After Phase 7, apply 2026_10_21_000004_create_processing_tables.php:
route versions -> route steps -> jobs -> job steps -> dispatch/receipt headers ?
dispatch lines -> receipt lines -> roll lineage -> invoice processing matches.
Then add the deferred invoice job-step FK and replace the invoice subset CHECK
with the stock/processing subset. Existing stock lines satisfy the expanded CHECK.
There are 78 application tables after this additive migration. No data backfill
or destructive reset is needed. DDL is forward-only; use a checked backup and a
rehearsed recovery plan for partial MySQL DDL failures.

Run normal migrate and db:seed using compatible PHP, then build assets and clear
caches. The configuration seeder adds types, account purposes and permissions,
not jobs/stock/credentials. Verify processing_accrual and abnormal_processing_loss,
financial calendar, vendor capabilities/rates and custody locations before use.
Review PROCESSING.md limits and rehearse the migration/full suite on MySQL 8.4.
