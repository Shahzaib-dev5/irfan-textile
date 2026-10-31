# Commercial, accounting and payroll table specifications

## Phase 12 implementation status (2026-10-26)

F05, F09-F12, F15-F18 and the physical-return slice of F30-F31 are implemented in
additive migrations, alongside existing F01-F03/F06-F08. Account groups use F03's
hierarchy. Tax children and employee payment dimensions remain deferred. Read
[actual Phase 12 decisions](../ACCOUNTING_FINANCE.md) before treating the complete
proposed specifications below as implemented column-for-column.


## Phase 11 implementation status (2026-10-26)
Sales F33-F39 and F41-F44 are implemented. F40/F45 tax children remain future.
Orders reserve through the shared Inventory ledger; dispatch recognizes net
revenue/COGS and invoice reclassifies unbilled AR. Return cost and credit rules
follow the explicit implementation slice in [SALES.md](../SALES.md), including
zero-tax constraints and one match per current invoice UI line. The full proposed
schema below includes later accounting/tax/payment work and is not all migrated.

> Phase 8: [Processing](../PROCESSING.md) now implements IP05, IP20-IP27 and F26,
> adds the deferred invoice processing-step FK and permits processing bill lines.
> Ten processing/custody/match tables join the previous 68. Late-cost charge
> allocation, Production, sales, tax and settlements remain design only.
> Earlier phase-status notes below are historical; no party identity is duplicated.

> Phase 7: [Purchasing](../PURCHASING.md) implements F08/F19-F25/F28-F29,
> with NULL-only future tax/processing dimensions and exact-cost stock bills.
> Earlier phase-status notes are historical; future commercial workflows remain
> architecture only. Existing inventory tables are reused, not duplicated.

> Current implementation: [Phase 6 inventory guide](../WAREHOUSES_INVENTORY.md)
> lists the implemented subset of this architectural specification. Earlier
> phase-status notes below describe historical implementation boundaries.

This is a normative part of the approved Phase 1 architecture, read with [DATABASE_SCHEMA.md](../DATABASE_SCHEMA.md). It specifies 55 tables. Phase 6 implements only the minimum ledger account, posting-account, cost-centre and stock-journal subset; commercial/payroll workflows remain documentation, with no statutory compliance claim.

## Notation and shared columns

- `NN` means `NOT NULL`; `N` means nullable with `DEFAULT NULL`. Unless specified, NN business columns have **no default**, so callers must supply them.
- Every `id` is `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY`. Every `*_id` is `BIGINT UNSIGNED` unless explicitly stated. Every FK uses `ON DELETE RESTRICT ON UPDATE RESTRICT`, including optional references, unless explicitly overridden.
- Every table has `created_at DATETIME(6) NN DEFAULT CURRENT_TIMESTAMP(6)`. Mutable rows also have `updated_at DATETIME(6) NN DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6)`. Tables explicitly labelled **append-only** omit `updated_at`. Document headers/lines are mutable only while draft; after posting their timestamps and business columns freeze.
- `M` = `DECIMAL(20,4)` PKR; `Q` = `DECIMAL(20,6)` quantity; `R` = `DECIMAL(20,8)` unit rate; `P` = `DECIMAL(9,6)` percentage. These aliases specify exact MySQL types, not application aliases. Never use float/double.
- Every header with `document_id` has `document_id BIGINT UNSIGNED NN PRIMARY KEY REFERENCES documents(id)` instead of an auto-increment `id`. It inherits only timestamps, not duplicated document number/date/status/year/user columns. It must have the corresponding document type.
- Every named line table has `id`, `document_id NN REFERENCES its explicitly named typed header(document_id)`, `line_no SMALLINT UNSIGNED NN`, and `UNIQUE(document_id,line_no)`, plus timestamps. These are included through the **Line** marker below; no implicit business columns exist.
- An FK has a named supporting nonunique index unless its columns are the leftmost prefix of a stated PK/UQ/index. Additional indexes are listed per table. `CHECK` applies only to same-row expressions; cross-row invariants below require transaction locks and posting validation.
- Text snapshots preserve the document as agreed even when a master changes. Amount snapshots on invoice lines and journal headers are authoritative immutable posting results, not caches edited independently.
- All amounts are functional PKR. Payment bank currencies and foreign-currency billing are outside this approved scope. DECIMAL multiplication and division must use decimal arithmetic, with explicit rounding to 4 places for PKR and 6 for quantities.

## Full table inventory and dependency groups

| Group | Tables |
|---|---|
| Accounting configuration | `financial_years`, `accounting_periods`, `ledger_accounts`, `cost_centres`, `bank_accounts` |
| Books and settlement | `journal_entries`, `journal_lines`, `party_open_items`, `settlement_batches`, `settlement_allocations`, `payments`, `receipts`, `payment_tax_lines`, `receipt_tax_lines` |
| Bank reconciliation | `bank_statements`, `bank_statement_lines`, `bank_reconciliations`, `bank_reconciliation_matches` |
| Purchasing | `purchase_orders`, `purchase_order_lines`, `goods_receipts`, `goods_receipt_lines`, `purchase_invoices`, `purchase_invoice_lines`, `purchase_invoice_receipt_matches`, `purchase_invoice_processing_matches`, `purchase_invoice_line_taxes`, `purchase_returns`, `purchase_return_lines`, `purchase_credit_notes`, `purchase_credit_note_lines`, `purchase_credit_note_line_taxes` |
| Sales | `sales_orders`, `sales_order_lines`, `sales_dispatches`, `sales_dispatch_lines`, `sales_invoices`, `sales_invoice_lines`, `sales_invoice_dispatch_matches`, `sales_invoice_line_taxes`, `sales_returns`, `sales_return_lines`, `sales_credit_notes`, `sales_credit_note_lines`, `sales_credit_note_line_taxes` |
| Expenses | `expense_claims`, `expense_claim_lines` |
| HR/payroll | `employees`, `employment_terms`, `salary_components`, `employee_salary_components`, `attendance_entries`, `payroll_runs`, `payroll_run_employees`, `payroll_run_lines` |

## Accounting configuration

### F01 `financial_years`

Columns: `id`; `code VARCHAR(20) NN`; `starts_on DATE NN`; `ends_on DATE NN`; `is_closed BOOLEAN NN DEFAULT 0`; `closed_at DATETIME(6) N`; `closed_by BIGINT UNSIGNED N REFERENCES users(id)`.

Constraints/indexes: `UNIQUE(code)`; index `(starts_on,ends_on)`; `CHECK(ends_on >= starts_on)`; `CHECK(is_closed IN (0,1))`; `CHECK((is_closed=0 AND closed_at IS NULL AND closed_by IS NULL) OR (is_closed=1 AND closed_at IS NOT NULL AND closed_by IS NOT NULL))`.

Invariants: date ranges cannot overlap. Closing requires all periods closed and no pending posting transaction. Serialize year creation/close using the company singleton row. Reopening is an authorized, audited configuration change; posted facts do not change.

### F02 `accounting_periods`

Columns: `id`; `financial_year_id NN REFERENCES financial_years(id)`; `code VARCHAR(20) NN`; `starts_on DATE NN`; `ends_on DATE NN`; `status ENUM('open','closed') NN DEFAULT 'open'`; `closed_at DATETIME(6) N`; `closed_by BIGINT UNSIGNED N REFERENCES users(id)`.

Constraints/indexes: `UNIQUE(financial_year_id,code)`; `UNIQUE(id,financial_year_id)` for the document composite FK; index `(financial_year_id,starts_on,ends_on)`; `CHECK(ends_on >= starts_on)`; closure fields must both be NULL for open or both present for closed.

Invariants: period inside year; no overlapping periods; regular periods cover the financial year without gaps. The accounting document date belongs to exactly one open period; posting locks the period and year so close cannot race posting. `documents(accounting_period_id,financial_year_id)` references `(id,financial_year_id)` here.

### F03 `ledger_accounts`

Columns: `id`; `code VARCHAR(30) NN`; `name VARCHAR(150) NN`; `parent_id BIGINT UNSIGNED N REFERENCES ledger_accounts(id)`; `account_type ENUM('asset','liability','equity','income','expense') NN`; `normal_balance ENUM('debit','credit') NN`; `control_type ENUM('none','party_receivable','party_payable','inventory','bank_cash') NN DEFAULT 'none'`; `is_postable BOOLEAN NN DEFAULT 1`; `is_active BOOLEAN NN DEFAULT 1`.

Constraints/indexes: `UNIQUE(code)`; index `(account_type,is_active)`; `Application tree validation (a CHECK cannot reference AUTO_INCREMENT id on MySQL 8.4)`; boolean checks; `CHECK(control_type='none' OR is_postable=1)`.

Invariants: no account-tree cycles; child classification compatible with parent; never change type/control classification after use. Contra accounts can deliberately have the opposite normal balance. AP and AR require party dimensions, and cannot be posted through unrestricted manual journals. Inventory and bank accounts require their respective subledger source. The separate `posting_accounts` mapping assigns configurable purposes to these accounts.

### F04 `cost_centres`

Columns: `id`; `code VARCHAR(30) NN`; `name VARCHAR(150) NN`; `parent_id BIGINT UNSIGNED N REFERENCES cost_centres(id)`; `is_active BOOLEAN NN DEFAULT 1`.

Constraints/indexes: `UNIQUE(code)`; `Application tree validation (a CHECK cannot reference AUTO_INCREMENT id on MySQL 8.4)`; boolean check. No cycles; historical references survive deactivation. A department or production cost centre is a dimension, not an independent ledger.

### F05 `bank_accounts`

Columns: `id`; `code VARCHAR(30) NN`; `name VARCHAR(150) NN`; `account_kind ENUM('bank','cash') NN`; `ledger_account_id NN REFERENCES ledger_accounts(id)`; `bank_name VARCHAR(150) N`; `branch_name VARCHAR(150) N`; `account_reference VARCHAR(100) N`; `iban VARCHAR(34) N`; `is_active BOOLEAN NN DEFAULT 1`.

Constraints/indexes: `UNIQUE(code)`; `UNIQUE(ledger_account_id)`; `UNIQUE(iban)` (multiple NULL allowed); boolean check; `CHECK(account_kind='bank' OR (bank_name IS NULL AND branch_name IS NULL AND iban IS NULL))`.

Invariants: ledger account must be postable `bank_cash`. One cash box or bank account maps to one GL account. Sensitive banking fields require restricted access and encrypted backups; no bank secrets or online credentials are stored here.

## Books and settlements

### F06 `journal_entries`

Document header. Columns: `document_id`; `entry_kind ENUM('operational','manual','opening','reversal','year_close') NN`; `description VARCHAR(500) NN`; `total_debit M NN`; `total_credit M NN`.

Constraints/indexes: `CHECK(total_debit>0 AND total_credit>0 AND total_debit=total_credit)`.

Invariants: exactly one journal per financially posting root document, with at least two lines, and totals exactly equal line sums. A journal is never a second root document for the same source. Zero-value physical documents may have no journal, with an explicit posting rule. The root's unique posting key prevents duplicate entries. Year-close/opening entries follow a separately approved account mapping; they never replay inventory quantities.

### F07 `journal_lines`

**Append-only once inserted at posting; created_at only.** Columns: `id`; `journal_entry_id NN REFERENCES journal_entries(document_id)`; `line_no SMALLINT UNSIGNED NN`; `ledger_account_id NN REFERENCES ledger_accounts(id)`; `party_id BIGINT UNSIGNED N REFERENCES parties(id)`; `party_role_id BIGINT UNSIGNED N REFERENCES party_roles(id)`; `employee_id BIGINT UNSIGNED N REFERENCES employees(id)`; `cost_centre_id BIGINT UNSIGNED N REFERENCES cost_centres(id)`; `description VARCHAR(500) NN`; `debit M NN DEFAULT 0`; `credit M NN DEFAULT 0`; `due_on DATE N`.

Constraints/indexes: `UNIQUE(journal_entry_id,line_no)`; index `(ledger_account_id,journal_entry_id)`; index `(party_id,ledger_account_id,journal_entry_id)`; index `(employee_id,journal_entry_id)`; `CHECK((debit>0 AND credit=0) OR (credit>0 AND debit=0))`; `CHECK(party_role_id IS NULL OR party_id IS NOT NULL)`; `CHECK(employee_id IS NULL OR party_id IS NOT NULL)`.

Invariants: account active/postable; party/role and employee/party agree; role identifies commercial context where applicable, never a second party identity. Employee dimension required for payroll payable/advance postings. AP/AR lines require party and due date; other lines may omit them. Inventory/COGS dimensions reconcile to valuation sources through the common document ID. Reports derive transaction date/period from `documents`, not insertion timestamp.

### F08 `party_open_items`

**Append-only.** Columns: `id`; `journal_line_id NN REFERENCES journal_lines(id)`; `original_amount M NN`; `due_on DATE NN`.

Constraints/indexes: `UNIQUE(journal_line_id)`; index `(due_on,id)`; `CHECK(original_amount>0)`.

Invariants: one open item for every party-control journal line, including invoices, credit notes, cash advances, on-account receipts, payroll payables and reversals. Original amount equals the nonzero journal side; party, account, polarity and employee are derived through the line. The non-control `unbilled_receivable` clearing account retains party dimensions on dispatch/invoice journal lines but is reconciled through immutable dispatch-to-invoice matches, not settled as a second customer open item. No duplicated AP/AR balance table or mutable `remaining_amount` column. Outstanding = original amount minus effective posted allocations. Aging uses `due_on`; original journal date remains available.

### F09 `settlement_batches`

Document header. Columns: `document_id`; `party_id NN REFERENCES parties(id)`; `ledger_account_id NN REFERENCES ledger_accounts(id)`; `settlement_kind ENUM('apply','reverse') NN`; `description VARCHAR(500) N`.

Constraints/indexes: index `(party_id,ledger_account_id,document_id)`.

Invariants: this document changes allocation facts, not GL totals. A batch can allocate many debit/credit open items for the same party and control account. Same-party supplier/customer balances are not silently netted. Cross-control-account offset needs an authorized balanced journal that first creates opposite open items on the respective accounts, followed by allocations. Employee allocations additionally match employee dimensions.

### F10 `settlement_allocations`

**Append-only.** Columns: `id`; `document_id NN REFERENCES settlement_batches(document_id)`; `line_no SMALLINT UNSIGNED NN`; `debit_open_item_id NN REFERENCES party_open_items(id)`; `credit_open_item_id NN REFERENCES party_open_items(id)`; `amount M NN`; `reverses_allocation_id BIGINT UNSIGNED N REFERENCES settlement_allocations(id)`.

Constraints/indexes: `UNIQUE(document_id,line_no)`; `UNIQUE(reverses_allocation_id)`; indexes `(debit_open_item_id,id)` and `(credit_open_item_id,id)`; `CHECK(amount>0)`; `CHECK(debit_open_item_id<>credit_open_item_id)`; `CHECK(reverses_allocation_id IS NULL OR reverses_allocation_id<>id)`.

Invariants: allocate only posted opposite-side items matching batch party/account and employee dimension. Lock all affected items in ascending ID order; sum effective allocations and reject any over-allocation. A reversal records the exact original amount and pair, refers only to an unreversed ordinary allocation, and belongs to a reversal batch. Reversal-of-reversal is not allowed: reapplication is a new ordinary allocation. Effective applications are original application facts without a posted reversal. A journal reversal first unwinds affected settlements atomically before creating opposite journal/open-item facts.

### F11 `payments`

Document header. Columns: `document_id`; `party_id NN REFERENCES parties(id)`; `party_role_id BIGINT UNSIGNED N REFERENCES party_roles(id)`; `employee_id BIGINT UNSIGNED N REFERENCES employees(id)`; `bank_account_id NN REFERENCES bank_accounts(id)`; `settlement_account_id NN REFERENCES ledger_accounts(id)`; `method ENUM('cash','bank_transfer','cheque','other') NN`; `gross_amount M NN`; `bank_amount M NN`; `reference VARCHAR(100) N`; `reference_date DATE N`; `description VARCHAR(500) N`.

Constraints/indexes: index `(party_id,document_id)`; index `(bank_account_id,reference)`; `CHECK(gross_amount>0 AND bank_amount>0 AND bank_amount<=gross_amount)`.

Invariants: gross = bank amount + withholding deductions. Post Dr party payable (or approved employee advance receivable), Cr bank, Cr withholding payable. An advance remains an open item until allocated; no fake invoice needed. Method must fit bank/cash account. Employee and role match party. Each posting creates open items and optionally a separately numbered settlement batch in the same transaction. Bank fees are expense documents or explicit balanced journal lines, never hidden in allocation differences.

### F12 `receipts`

Document header. Columns: `document_id`; `party_id NN REFERENCES parties(id)`; `party_role_id BIGINT UNSIGNED N REFERENCES party_roles(id)`; `employee_id BIGINT UNSIGNED N REFERENCES employees(id)`; `bank_account_id NN REFERENCES bank_accounts(id)`; `settlement_account_id NN REFERENCES ledger_accounts(id)`; `method ENUM('cash','bank_transfer','cheque','other') NN`; `gross_amount M NN`; `bank_amount M NN`; `reference VARCHAR(100) N`; `reference_date DATE N`; `description VARCHAR(500) N`.

Constraints/indexes: index `(party_id,document_id)`; index `(bank_account_id,reference)`; `CHECK(gross_amount>0 AND bank_amount>0 AND bank_amount<=gross_amount)`.

Invariants: gross = bank amount + customer withholding. Post Dr bank, Dr recoverable withholding where applicable, Cr party receivable. Customer deposits/on-account receipts produce credit open items. Supplier refunds can debit bank and credit supplier payable; employee advance recovery credits employee receivable. Account selection is restricted to approved transaction purposes, not an arbitrary user-selected GL account.

### F13 `payment_tax_lines`

**Line → payments.** Additional columns: `tax_rate_id NN REFERENCES tax_rates(id)`; `tax_label VARCHAR(100) NN`; `rate_percent P NN`; `taxable_amount M NN`; `tax_amount M NN`; `ledger_account_id NN REFERENCES ledger_accounts(id)`; `certificate_reference VARCHAR(100) N`.

Constraints/indexes: `UNIQUE(document_id,tax_rate_id)`; `CHECK(rate_percent>=0 AND rate_percent<=100)`; `CHECK(taxable_amount>0 AND tax_amount>0)`.

Invariants: rate and rounded amount snapshot the approved withholding basis. Selected account is the configured withholding liability. These are deductions from gross settlement, not extra invoice tax charges. No hardcoded Pakistan tax rate or deduction obligation is assumed.

### F14 `receipt_tax_lines`

**Line → receipts.** Additional columns: `tax_rate_id NN REFERENCES tax_rates(id)`; `tax_label VARCHAR(100) NN`; `rate_percent P NN`; `taxable_amount M NN`; `tax_amount M NN`; `ledger_account_id NN REFERENCES ledger_accounts(id)`; `certificate_reference VARCHAR(100) N`.

Constraints/indexes: `UNIQUE(document_id,tax_rate_id)`; `CHECK(rate_percent>=0 AND rate_percent<=100)`; `CHECK(taxable_amount>0 AND tax_amount>0)`.

Invariants: approved recoverable withholding account; deductions sum to receipt gross minus cash received. Certificate metadata is descriptive evidence, not a claim of statutory filing support.

## Bank reconciliation

### F15 `bank_statements`

Columns: `id`; `bank_account_id NN REFERENCES bank_accounts(id)`; `statement_reference VARCHAR(100) NN`; `starts_on DATE NN`; `ends_on DATE NN`; `opening_balance M NN`; `closing_balance M NN`; `import_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin N`; `imported_by NN REFERENCES users(id)`; `confirmed_at DATETIME(6) N`.

Constraints/indexes: `UNIQUE(bank_account_id,statement_reference)`; `UNIQUE(bank_account_id,import_sha256)`; index `(bank_account_id,starts_on,ends_on)`; `CHECK(ends_on>=starts_on)`.

Invariants: confirmed imported evidence is immutable; opening + signed line sums = closing. A correction is a new statement version/reference, never editing previously reconciled evidence. Signed balances permit bank overdrafts. No arbitrary unique date range that would prevent corrected statements.

### F16 `bank_statement_lines`

Columns: `id`; `bank_statement_id NN REFERENCES bank_statements(id)`; `line_no INT UNSIGNED NN`; `transaction_on DATE NN`; `value_on DATE N`; `reference VARCHAR(150) N`; `description VARCHAR(500) NN`; `amount M NN`; `external_transaction_key VARCHAR(150) N`.

Constraints/indexes: `UNIQUE(bank_statement_id,line_no)`; `UNIQUE(bank_statement_id,external_transaction_key)`; index `(transaction_on,reference)`; `CHECK(amount<>0)`.

Invariants: signed positive cash inflow, negative outflow. References are not universally unique across banks. Transaction duplication checks across overlapping statement imports use bank + reference/date/amount evidence under review; an unreliable bank reference is not treated as an idempotency guarantee.

### F17 `bank_reconciliations`

Document header. Columns: `document_id`; `bank_account_id NN REFERENCES bank_accounts(id)`; `as_of DATE NN`; `statement_closing_balance M NN`; `book_closing_balance M NN`; `unpresented_outflow M NN DEFAULT 0`; `uncredited_inflow M NN DEFAULT 0`; `other_reconciling_amount M NN DEFAULT 0`; `explanation VARCHAR(1000) N`.

Constraints/indexes: index `(bank_account_id,as_of)`; `CHECK(unpresented_outflow>=0 AND uncredited_inflow>=0)`.

Invariants: snapshots are reconciled reports, not a mutable cash ledger. Same-date corrections use a reversal/new document rather than a uniqueness constraint on date. `book_closing_balance = statement_closing_balance - unpresented_outflow + uncredited_inflow + other_reconciling_amount`. Nonzero other amount requires itemized evidence in attachments and an approved reason; bank fees/errors affecting books require a posted financial document before reconciliation approval.

### F18 `bank_reconciliation_matches`

**Line → bank_reconciliations.** Additional columns: `bank_statement_line_id NN REFERENCES bank_statement_lines(id)`; `journal_line_id NN REFERENCES journal_lines(id)`; `matched_amount M NN`; `reverses_match_id BIGINT UNSIGNED N REFERENCES bank_reconciliation_matches(id)`.

Constraints/indexes: `UNIQUE(reverses_match_id)`; index `(bank_statement_line_id,id)`; index `(journal_line_id,id)`; `CHECK(matched_amount>0)`; `CHECK(reverses_match_id IS NULL OR reverses_match_id<>id)`.

Invariants: signed bank flow agrees with debit/credit on the bank GL line; both belong to the reconciliation bank. Many-to-many split/combined matching is allowed. Lock statement and GL lines, reject cumulative matching above either absolute amount. Reversal refers to an ordinary unreversed exact pair/amount; a reversed root document remains in history and contributes only its negative match effect.

## Purchasing

### F19 `purchase_orders`

Document header. Columns: `document_id`; `supplier_party_role_id NN REFERENCES supplier_profiles(party_role_id)`; `ship_to_warehouse_id NN REFERENCES warehouses(id)`; `payment_term_id BIGINT UNSIGNED N REFERENCES payment_terms(id)`; `expected_on DATE N`; `supplier_reference VARCHAR(100) N`; `notes TEXT N`.

Constraints/indexes: index `(supplier_party_role_id,expected_on)`.

Invariants: approved/posted order is an immutable commercial commitment, with no stock or journal posting. Remaining ordered quantity is derived from accepted receipt lines net physical returns and explicit policy; returns do not automatically authorize replacement receipt unless a documented replacement order exists. Amendments require replacement order or cancellation of unfulfilled balance via a documented status event; fulfilled portions remain.

### F20 `purchase_order_lines`

**Line → purchase_orders.** Additional columns: `item_id NN REFERENCES items(id)`; `description VARCHAR(255) NN`; `unit_id NN REFERENCES units(id)`; `quantity Q NN`; `base_quantity Q NN`; `conversion_factor R NN`; `unit_price R NN`; `discount_amount M NN DEFAULT 0`; `tax_rate_id BIGINT UNSIGNED N REFERENCES tax_rates(id)`; `expected_on DATE N`.

Constraints/indexes: index `(item_id,document_id)`; `CHECK(quantity>0 AND base_quantity>0 AND conversion_factor>0 AND unit_price>=0 AND discount_amount>=0)`; discount cannot exceed rounded gross.

Invariants: item/unit conversion and expected tax are quotations; actual invoice taxes use explicit snapshot children. Base quantity equals approved rounded conversion; fabric meters remain meters unless an explicit approved item conversion exists. PO quantity and price tolerance are configurable and require audited approval when exceeded.

### F21 `goods_receipts`

Document header. Columns: `document_id`; `supplier_party_role_id NN REFERENCES supplier_profiles(party_role_id)`; `warehouse_id NN REFERENCES warehouses(id)`; `supplier_challan_number VARCHAR(100) N`; `supplier_challan_date DATE N`; `received_at DATETIME(6) NN`; `notes TEXT N`.

Constraints/indexes: index `(supplier_party_role_id,supplier_challan_number)`; index `(warehouse_id,received_at)`.

Invariants: every line enters company-owned stock at the chosen warehouse; supplier consignment inventory is not implied. Multiple POs from one supplier may feed a receipt. Receipt debits inventory and credits GRNI at approved provisional cost; supplier invoice does not duplicate stock.

### F22 `goods_receipt_lines`

**Line → goods_receipts.** Additional columns: `purchase_order_line_id BIGINT UNSIGNED N REFERENCES purchase_order_lines(id)`; `item_id NN REFERENCES items(id)`; `stock_position_id NN REFERENCES stock_positions(id)`; `unit_id NN REFERENCES units(id)`; `received_quantity Q NN`; `accepted_quantity Q NN`; `rejected_quantity Q NN DEFAULT 0`; `conversion_factor R NN`; `accepted_base_quantity Q NN`; `provisional_unit_cost R NN`; `provisional_value M NN`; `stock_movement_id BIGINT UNSIGNED N REFERENCES stock_movements(id)`; `inspection_notes VARCHAR(500) N`.

Constraints/indexes: `UNIQUE(stock_movement_id)`; index `(purchase_order_line_id,document_id)`; `CHECK(received_quantity>0 AND accepted_quantity>0 AND rejected_quantity>=0 AND received_quantity=accepted_quantity+rejected_quantity)`; `CHECK(conversion_factor>0 AND accepted_base_quantity>0 AND provisional_unit_cost>=0 AND provisional_value>=0)`.

Invariants: one receipt line is exactly one lot/optional roll/location position and one positive owned-stock movement. Split lines for multiple rolls/lots. Item and warehouse match the position; accepted base quantity matches movement; rejected goods not accepted into ownership have no stock/value entry. An entirely rejected delivery is recorded by inspection evidence rather than a zero-stock receipt. PO-less receipt requires an approved reason in document audit evidence. At posting the movement FK becomes nonnull and provisional value equals layer receipt valuation; line quantity cannot be invoiced twice.

### F23 `purchase_invoices`

Document header. Columns: `document_id`; `payee_party_role_id NN REFERENCES party_roles(id)`; `supplier_invoice_number VARCHAR(100) NN`; `supplier_invoice_date DATE NN`; `due_on DATE NN`; `payment_term_id BIGINT UNSIGNED N REFERENCES payment_terms(id)`; `net_amount M NN`; `tax_amount M NN DEFAULT 0`; `rounding_amount M NN DEFAULT 0`; `gross_amount M NN`; `notes TEXT N`.

Constraints/indexes: `UNIQUE(payee_party_role_id,supplier_invoice_number)`; index `(payee_party_role_id,due_on)`; `CHECK(net_amount>=0 AND tax_amount>=0 AND gross_amount>0 AND gross_amount=net_amount+tax_amount+rounding_amount)`; `CHECK(ABS(rounding_amount)<=1)`.

Invariants: role must be active supplier or vendor when drafted; supplier and vendor use the same payable engine. Duplicate supplier invoice check is also performed by **party identity**, normalized reference and invoice kind, even if the party holds both roles; concurrent posting locks party. Invoice date can differ from accounting document date. Due date snapshots terms; changing master terms cannot change it. Role deactivation after an original transaction must not block an authorized reversal.

### F24 `purchase_invoice_lines`

**Line → purchase_invoices.** Additional columns: `line_kind ENUM('stock','processing','expense') NN`; `item_id BIGINT UNSIGNED N REFERENCES items(id)`; `processing_job_step_id BIGINT UNSIGNED N REFERENCES processing_job_steps(id)`; `expense_account_id BIGINT UNSIGNED N REFERENCES ledger_accounts(id)`; `cost_centre_id BIGINT UNSIGNED N REFERENCES cost_centres(id)`; `description VARCHAR(255) NN`; `unit_id NN REFERENCES units(id)`; `quantity Q NN`; `base_quantity Q NN`; `conversion_factor R NN`; `unit_price R NN`; `discount_amount M NN DEFAULT 0`; `net_amount M NN`; `tax_amount M NN DEFAULT 0`; `gross_amount M NN`.

Constraints/indexes: `CHECK(quantity>0 AND base_quantity>0 AND conversion_factor>0 AND unit_price>=0 AND discount_amount>=0 AND net_amount>=0 AND tax_amount>=0 AND gross_amount=net_amount+tax_amount)`; `CHECK((line_kind='stock' AND item_id IS NOT NULL AND processing_job_step_id IS NULL AND expense_account_id IS NULL) OR (line_kind='processing' AND item_id IS NULL AND processing_job_step_id IS NOT NULL AND expense_account_id IS NULL) OR (line_kind='expense' AND item_id IS NULL AND processing_job_step_id IS NULL AND expense_account_id IS NOT NULL))`.

Invariants: net = rounded quantity × unit price minus discount; tax is child sum; line gross excludes header rounding. Stock quantities use item base unit; processing quantities use the step's snapshotted service unit, usually meters for dyeing; expense uses a configured service unit. Expense account cannot be inventory or party control. All stock/processing value must match receipt/acceptance before posting; prepayments use payments/open items rather than invented receipts.

### F25 `purchase_invoice_receipt_matches`

Columns: `id`; `purchase_invoice_line_id NN REFERENCES purchase_invoice_lines(id)`; `goods_receipt_line_id NN REFERENCES goods_receipt_lines(id)`; `matched_base_quantity Q NN`; `net_amount M NN`; `provisional_value_relieved M NN`.

Constraints/indexes: `UNIQUE(purchase_invoice_line_id,goods_receipt_line_id)`; `CHECK(matched_base_quantity>0 AND net_amount>=0 AND provisional_value_relieved>=0)`.

Invariants: invoice line kind stock, same party and item, posted nonreversed receipt. Lock receipt line; matched quantity less commercial credits cannot exceed accepted owned receipt quantity eligible for billing. All invoice base quantity and net amount must be covered exactly. GRNI relieved uses original provisional valuation proportional to quantity with final-residual rounding; invoice difference plus nonrecoverable tax propagates through surviving/consumed FIFO layers as a cost adjustment. Receipt/stock movement links give a concrete physical source.

### F26 `purchase_invoice_processing_matches`

Columns: `id`; `purchase_invoice_line_id NN REFERENCES purchase_invoice_lines(id)`; `processing_receipt_line_id NN REFERENCES processing_receipt_lines(id)`; `billed_service_quantity Q NN`; `net_amount M NN`; `accrued_value_relieved M NN`.

Constraints/indexes: `UNIQUE(purchase_invoice_line_id,processing_receipt_line_id)`; `CHECK(billed_service_quantity>0 AND net_amount>=0 AND accrued_value_relieved>=0)`.

Invariants: invoice kind processing; step/vendor/service unit match accepted receipt, which exposes `accepted_service_quantity`. Lock receipt and invoice line; cumulative billed quantity net applicable credit notes cannot exceed accepted service quantity. Match sums equal invoice quantities/net. Acceptance already accrues the frozen approved job rate: invoice clears processing accrual and capitalizes **only the difference**, through `processing_charge_allocations` and inventory cost-adjustment genealogy. Reject an unapproved invoice-rate difference rather than silently replacing a historical job rate.

### F27 `purchase_invoice_line_taxes`

Columns: `id`; `purchase_invoice_line_id NN REFERENCES purchase_invoice_lines(id)`; `tax_rate_id NN REFERENCES tax_rates(id)`; `sequence SMALLINT UNSIGNED NN`; `tax_label VARCHAR(100) NN`; `rate_percent P NN`; `taxable_amount M NN`; `tax_amount M NN`; `treatment ENUM('recoverable','nonrecoverable') NN`; `ledger_account_id NN REFERENCES ledger_accounts(id)`.

Constraints/indexes: `UNIQUE(purchase_invoice_line_id,sequence)`; `UNIQUE(purchase_invoice_line_id,tax_rate_id)`; `CHECK(rate_percent>=0 AND rate_percent<=100 AND taxable_amount>=0 AND tax_amount>=0)`.

Invariants: tax base/rate/formula provenance and sequence snapshot approved tax configuration. Recoverable tax posts to approved input-tax control; nonrecoverable tax joins item/service cost or expense. Withholding is recorded at its configured realization event; current design realizes it at payment through `payment_tax_lines`, avoiding duplicate deductions.

### F28 `purchase_returns`

Document header. Columns: `document_id`; `supplier_party_role_id NN REFERENCES supplier_profiles(party_role_id)`; `warehouse_id NN REFERENCES warehouses(id)`; `return_reference VARCHAR(100) N`; `reason VARCHAR(500) NN`.

Constraints/indexes: index `(supplier_party_role_id,document_id)`.

Invariants: this is the physical stock return. It does not itself reduce supplier AP; a linked credit note does. Its posting credits inventory and debits supplier-return clearing at actual layer cost. Uninvoiced returns can directly reduce GRNI only when receipt matching proves this and an explicit rule selects that route.

### F29 `purchase_return_lines`

**Line → purchase_returns.** Additional columns: `goods_receipt_line_id NN REFERENCES goods_receipt_lines(id)`; `stock_position_id NN REFERENCES stock_positions(id)`; `base_quantity Q NN`; `stock_movement_id BIGINT UNSIGNED N REFERENCES stock_movements(id)`; `reason VARCHAR(500) NN`.

Constraints/indexes: `UNIQUE(stock_movement_id)`; index `(goods_receipt_line_id,document_id)`; `CHECK(base_quantity>0)`.

Invariants: company-owned, available stock only; position item and lot genealogy trace to original receipt and same supplier. Net cumulative returns cannot exceed received quantity; processed/changed items cannot be represented as the untouched original item. Movement is exactly one negative position movement, filled atomically on posting. Current FIFO cost is derived through layer allocations, not copied from supplier selling price.

### F30 `purchase_credit_notes`

Document header. Columns: `document_id`; `purchase_invoice_id NN REFERENCES purchase_invoices(document_id)`; `supplier_credit_number VARCHAR(100) NN`; `credit_kind ENUM('physical_return','price_adjustment','service_adjustment') NN`; `reason VARCHAR(500) NN`; `net_amount M NN`; `tax_amount M NN DEFAULT 0`; `rounding_amount M NN DEFAULT 0`; `gross_amount M NN`.

Constraints/indexes: `UNIQUE(purchase_invoice_id,supplier_credit_number)`; `CHECK(net_amount>=0 AND tax_amount>=0 AND gross_amount>0 AND gross_amount=net_amount+tax_amount+rounding_amount)`; `CHECK(ABS(rounding_amount)<=1)`.

Invariants: payee derived from original invoice. Duplicate credit reference checked by party identity under lock, even across invoices; reference may repeat an invoice number because document kinds differ. Creates debit AP open item; allocation to original invoice or refund is explicit. Price/service credit changes cost through lineage or expense, never edits receipt/jobs. Credit quantity/amount/tax must stay within the original commercial exposure, with explicit variance approval for value-only adjustments.

### F31 `purchase_credit_note_lines`

**Line → purchase_credit_notes.** Additional columns: `purchase_invoice_line_id NN REFERENCES purchase_invoice_lines(id)`; `purchase_return_line_id BIGINT UNSIGNED N REFERENCES purchase_return_lines(id)`; `purchase_invoice_receipt_match_id BIGINT UNSIGNED N REFERENCES purchase_invoice_receipt_matches(id)`; `purchase_invoice_processing_match_id BIGINT UNSIGNED N REFERENCES purchase_invoice_processing_matches(id)`; `credited_quantity Q NN DEFAULT 0`; `net_amount M NN`; `tax_amount M NN DEFAULT 0`; `gross_amount M NN`; `description VARCHAR(255) NN`.

Constraints/indexes: `CHECK(credited_quantity>=0 AND net_amount>=0 AND tax_amount>=0 AND gross_amount=net_amount+tax_amount AND gross_amount>0)`; `CHECK(purchase_invoice_receipt_match_id IS NULL OR purchase_invoice_processing_match_id IS NULL)`.

Invariants: original line belongs to header invoice; any match belongs to original line. Physical-return credit requires return-line and stock receipt match, same original receipt, positive base credited quantity bounded by physical return and invoiced quantity. Processing credit requires processing match; quantity is the service unit. Value-only discount has quantity zero and does not reopen billed quantity. Partial credit caps and tax rounding use locked original lines/matches. Credit reversal restores the commercial capacity only through an explicit opposite document.

### F32 `purchase_credit_note_line_taxes`

Columns: `id`; `purchase_credit_note_line_id NN REFERENCES purchase_credit_note_lines(id)`; `purchase_invoice_line_tax_id NN REFERENCES purchase_invoice_line_taxes(id)`; `taxable_amount M NN`; `tax_amount M NN`.

Constraints/indexes: `UNIQUE(purchase_credit_note_line_id,purchase_invoice_line_tax_id)`; `CHECK(taxable_amount>=0 AND tax_amount>=0)`.

Invariants: rate/treatment/account derived from immutable original tax fact. Original tax belongs to credited invoice line; cumulative net credit cannot exceed original eligible tax. The reversal must preserve original rounding rather than recompute today's tax rate.

## Sales

### F33 `sales_orders`

Document header. Columns: `document_id`; `customer_party_role_id NN REFERENCES customer_profiles(party_role_id)`; `payment_term_id BIGINT UNSIGNED N REFERENCES payment_terms(id)`; `customer_reference VARCHAR(100) N`; `required_on DATE N`; `ship_to_address_id BIGINT UNSIGNED N REFERENCES party_addresses(id)`; `ship_to_snapshot TEXT NN`; `notes TEXT N`.

Constraints/indexes: index `(customer_party_role_id,required_on)`.

Invariants: no stock or journal posting. Credit limit approval uses current posted receivables plus approved unfulfilled commitments, serialized on customer role; it never relies on a stale UI balance. Address snapshot survives master edits. Any reservations belong to Inventory and must reference this order's actual lines.

### F34 `sales_order_lines`

**Line → sales_orders.** Additional columns: `item_id NN REFERENCES items(id)`; `description VARCHAR(255) NN`; `unit_id NN REFERENCES units(id)`; `quantity Q NN`; `base_quantity Q NN`; `conversion_factor R NN`; `unit_price R NN`; `discount_amount M NN DEFAULT 0`; `tax_rate_id BIGINT UNSIGNED N REFERENCES tax_rates(id)`; `required_on DATE N`.

Constraints/indexes: index `(item_id,document_id)`; `CHECK(quantity>0 AND base_quantity>0 AND conversion_factor>0 AND unit_price>=0 AND discount_amount>=0)`.

Invariants: sellable finished item/variant required by ordinary wholesale rules. Suit base-unit quantities are integral, carton units use snapshotted exact conversions; no fractional suit hidden in rounding. Discount <= rounded line gross. Price/tax quotations do not overwrite invoice facts.

### F35 `sales_dispatches`

Document header. Columns: `document_id`; `customer_party_role_id NN REFERENCES customer_profiles(party_role_id)`; `warehouse_id NN REFERENCES warehouses(id)`; `dispatched_at DATETIME(6) NN`; `carrier VARCHAR(150) N`; `tracking_reference VARCHAR(100) N`; `ship_to_snapshot TEXT NN`; `notes TEXT N`.

Constraints/indexes: index `(customer_party_role_id,dispatched_at)`; index `(warehouse_id,dispatched_at)`.

Invariants: accepted dispatch is the proposed ownership/control-transfer event. It credits inventory and debits COGS exactly once at FIFO actual cost, and records net revenue against a party-specific unbilled receivable at the sales-order price snapshot. Output tax timing is assessed and posted from the invoice tax snapshot. This is an accountant-approval-sensitive policy; an alternative timing requires aligned revenue/COGS and a reviewed deferred/unbilled accounting design.

### F36 `sales_dispatch_lines`

**Line → sales_dispatches.** Additional columns: `sales_order_line_id NN REFERENCES sales_order_lines(id)`; `stock_position_id NN REFERENCES stock_positions(id)`; `base_quantity Q NN`; `net_revenue_amount M NN`; `revenue_account_id NN REFERENCES ledger_accounts(id)`; `stock_movement_id BIGINT UNSIGNED N REFERENCES stock_movements(id)`; `packing_package_id BIGINT UNSIGNED N REFERENCES packages(id)`; `description VARCHAR(255) NN`.

Constraints/indexes: `UNIQUE(stock_movement_id)`; index `(sales_order_line_id,document_id)`; index `(packing_package_id)`; `CHECK(base_quantity>0 AND net_revenue_amount>=0)`. Package, position and order item must agree under locked posting validation.

Invariants: order customer/item match dispatch/position. Each line issues exactly one stock position; multiple rolls/lots or packs require split lines. Order dispatch caps exclude reversals and use order locks. Posted movement equals negative base quantity. Available stock/reservations are locked; no negative balances. Revenue is the deterministically rounded order-line net price allocated to shipped base quantity; dispatch freezes that amount and revenue account. Package provenance is supplied through the physical packing allocation model and must not duplicate a second mutable stock balance.

### F37 `sales_invoices`

Document header. Columns: `document_id`; `customer_party_role_id NN REFERENCES customer_profiles(party_role_id)`; `due_on DATE NN`; `payment_term_id BIGINT UNSIGNED N REFERENCES payment_terms(id)`; `billing_address_snapshot TEXT NN`; `customer_reference VARCHAR(100) N`; `net_amount M NN`; `tax_amount M NN DEFAULT 0`; `rounding_amount M NN DEFAULT 0`; `gross_amount M NN`; `notes TEXT N`.

Constraints/indexes: index `(customer_party_role_id,due_on)`; `CHECK(net_amount>=0 AND tax_amount>=0 AND gross_amount>0 AND gross_amount=net_amount+tax_amount+rounding_amount)`; `CHECK(ABS(rounding_amount)<=1)`.

Invariants: posts Dr billed AR, Cr party-specific unbilled AR for matched dispatch value, and Cr output tax (plus explicit rounding); it does not recognize revenue a second time. No inventory movement here. The document number is the commercial invoice number. Customer role resolves identity from parties; no separate customer name/address master. Amounts and due date remain immutable.

### F38 `sales_invoice_lines`

**Line → sales_invoices.** Additional columns: `sales_order_line_id NN REFERENCES sales_order_lines(id)`; `item_id NN REFERENCES items(id)`; `description VARCHAR(255) NN`; `unit_id NN REFERENCES units(id)`; `quantity Q NN`; `base_quantity Q NN`; `conversion_factor R NN`; `unit_price R NN`; `discount_amount M NN DEFAULT 0`; `net_amount M NN`; `tax_amount M NN DEFAULT 0`; `gross_amount M NN`; `cost_centre_id BIGINT UNSIGNED N REFERENCES cost_centres(id)`.

Constraints/indexes: index `(item_id,document_id)`; `CHECK(quantity>0 AND base_quantity>0 AND conversion_factor>0 AND unit_price>=0 AND discount_amount>=0 AND net_amount>=0 AND tax_amount>=0 AND gross_amount=net_amount+tax_amount)`.

Invariants: customer/item/order consistency; rounded quantity × price less discount = net. All dispatched matches use base unit; multiple dispatches can feed one invoice line. Customer price discounts and approved free goods are represented explicitly; a positive total invoice is required. Output tax children sum exactly to line tax. Revenue account and net recognition were frozen at dispatch; this invoice only reclassifies the unbilled balance.

### F39 `sales_invoice_dispatch_matches`

Columns: `id`; `sales_invoice_line_id NN REFERENCES sales_invoice_lines(id)`; `sales_dispatch_line_id NN REFERENCES sales_dispatch_lines(id)`; `matched_base_quantity Q NN`; `net_amount M NN`.

Constraints/indexes: `UNIQUE(sales_invoice_line_id,sales_dispatch_line_id)`; `CHECK(matched_base_quantity>0 AND net_amount>=0)`.

Invariants: same customer/order item. Lock dispatch and invoice lines to prevent duplicate billing. Invoice posting requires full quantity/net match to posted dispatches and converts dispatch unbilled receivable into billed AR while posting applicable output tax. This design requires dispatch before invoice posting so matches never need mutation. Invoice net revenue must equal matched dispatch revenue; price changes require an approved adjustment/credit document.

### F40 `sales_invoice_line_taxes`

Columns: `id`; `sales_invoice_line_id NN REFERENCES sales_invoice_lines(id)`; `tax_rate_id NN REFERENCES tax_rates(id)`; `sequence SMALLINT UNSIGNED NN`; `tax_label VARCHAR(100) NN`; `rate_percent P NN`; `taxable_amount M NN`; `tax_amount M NN`; `ledger_account_id NN REFERENCES ledger_accounts(id)`.

Constraints/indexes: `UNIQUE(sales_invoice_line_id,sequence)`; `UNIQUE(sales_invoice_line_id,tax_rate_id)`; `CHECK(rate_percent>=0 AND rate_percent<=100 AND taxable_amount>=0 AND tax_amount>=0)`.

Invariants: configured output-tax account and original tax basis/rate snapshot. Withholding by customer is a receipt deduction and receivable-tax fact, not negative output tax.

### F41 `sales_returns`

Document header. Columns: `document_id`; `customer_party_role_id NN REFERENCES customer_profiles(party_role_id)`; `warehouse_id NN REFERENCES warehouses(id)`; `received_at DATETIME(6) NN`; `reason VARCHAR(500) NN`.

Constraints/indexes: index `(customer_party_role_id,received_at)`.

Invariants: physical return debits inventory and credits COGS using the original dispatched layer cost, adjusted for later attributable cost corrections; damaged goods are received to quarantine and written down through approved valuation documents. It does not itself reverse revenue/AR.

### F42 `sales_return_lines`

**Line → sales_returns.** Additional columns: `sales_dispatch_line_id NN REFERENCES sales_dispatch_lines(id)`; `stock_position_id NN REFERENCES stock_positions(id)`; `base_quantity Q NN`; `condition_code ENUM('saleable','quarantine','damaged') NN`; `stock_movement_id BIGINT UNSIGNED N REFERENCES stock_movements(id)`; `reason VARCHAR(500) NN`.

Constraints/indexes: `UNIQUE(stock_movement_id)`; index `(sales_dispatch_line_id,document_id)`; `CHECK(base_quantity>0)`.

Invariants: original customer/item/lot provenance matches; cumulative net physical returns <= dispatched quantity; position targets an appropriate internal location. One positive movement per line; links to original consumption through inventory lineage restore traceable cost. Original invoice is not required for a physical return of dispatched but unbilled goods.

### F43 `sales_credit_notes`

Document header. Columns: `document_id`; `sales_invoice_id NN REFERENCES sales_invoices(document_id)`; `credit_kind ENUM('physical_return','price_adjustment') NN`; `reason VARCHAR(500) NN`; `net_amount M NN`; `tax_amount M NN DEFAULT 0`; `rounding_amount M NN DEFAULT 0`; `gross_amount M NN`.

Constraints/indexes: `CHECK(net_amount>=0 AND tax_amount>=0 AND gross_amount>0 AND gross_amount=net_amount+tax_amount+rounding_amount)`; `CHECK(ABS(rounding_amount)<=1)`.

Invariants: customer derives from original invoice. Posts Dr revenue reduction and relevant output-tax reversal, Cr AR; no duplicate inventory receipt. Credit creates an open item for allocation/refund. A refund uses `payments` with the approved customer AR account and role.

### F44 `sales_credit_note_lines`

**Line → sales_credit_notes.** Additional columns: `sales_invoice_line_id NN REFERENCES sales_invoice_lines(id)`; `sales_invoice_dispatch_match_id NN REFERENCES sales_invoice_dispatch_matches(id)`; `sales_return_line_id BIGINT UNSIGNED N REFERENCES sales_return_lines(id)`; `credited_base_quantity Q NN DEFAULT 0`; `net_amount M NN`; `tax_amount M NN DEFAULT 0`; `gross_amount M NN`; `description VARCHAR(255) NN`.

Constraints/indexes: `CHECK(credited_base_quantity>=0 AND net_amount>=0 AND tax_amount>=0 AND gross_amount=net_amount+tax_amount AND gross_amount>0)`.

Invariants: original invoice line/match must agree with header. Physical return credit requires positive quantity and return provenance matching the original dispatch; aggregate credit quantities bounded by returned and invoiced quantities under locks. Price adjustment has quantity zero and needs no physical return. Amount/tax caps consider prior effective credits; no automatic reopening of original order fulfillment.

### F45 `sales_credit_note_line_taxes`

Columns: `id`; `sales_credit_note_line_id NN REFERENCES sales_credit_note_lines(id)`; `sales_invoice_line_tax_id NN REFERENCES sales_invoice_line_taxes(id)`; `taxable_amount M NN`; `tax_amount M NN`.

Constraints/indexes: `UNIQUE(sales_credit_note_line_id,sales_invoice_line_tax_id)`; `CHECK(taxable_amount>=0 AND tax_amount>=0)`.

Invariants: original tax belongs to original invoice line; cumulative credits cannot exceed original tax exposure. Rate and account derive from immutable original tax rather than current configuration.

## Expenses

### F46 `expense_claims`

Document header. Columns: `document_id`; `employee_id NN REFERENCES employees(id)`; `claimed_on DATE NN`; `description VARCHAR(500) NN`; `total_amount M NN`.

Constraints/indexes: index `(employee_id,claimed_on)`; `CHECK(total_amount>0)`.

Invariants: employee reimbursement of incidental company expenses, posts Dr approved expense/input tax and Cr employee payable. A supplier invoice is recorded in purchase invoices instead, preventing duplicate AP. Payment is a separate document allocated to the employee open item.

### F47 `expense_claim_lines`

**Line → expense_claims.** Additional columns: `spent_on DATE NN`; `expense_account_id NN REFERENCES ledger_accounts(id)`; `cost_centre_id BIGINT UNSIGNED N REFERENCES cost_centres(id)`; `supplier_party_id BIGINT UNSIGNED N REFERENCES parties(id)`; `receipt_reference VARCHAR(100) N`; `description VARCHAR(500) NN`; `net_amount M NN`; `tax_rate_id BIGINT UNSIGNED N REFERENCES tax_rates(id)`; `tax_rate_percent P NN DEFAULT 0`; `tax_amount M NN DEFAULT 0`; `tax_account_id BIGINT UNSIGNED N REFERENCES ledger_accounts(id)`; `gross_amount M NN`.

Constraints/indexes: `CHECK(net_amount>=0 AND tax_amount>=0 AND gross_amount>0 AND gross_amount=net_amount+tax_amount)`; `CHECK(tax_rate_percent>=0 AND tax_rate_percent<=100)`; `CHECK((tax_rate_id IS NULL AND tax_amount=0 AND tax_rate_percent=0 AND tax_account_id IS NULL) OR (tax_rate_id IS NOT NULL AND tax_account_id IS NOT NULL))`.

Invariants: one recoverable tax treatment per incidental claim line; split a receipt into lines when multiple approved tax bases are required. Nonrecoverable tax belongs in net expense. Documentary receipt reference/evidence reviewed for duplication against supplier invoices; no statutory input-tax entitlement is assumed.

## HR and payroll

### F48 `employees`

Columns: `id`; `party_id NN REFERENCES parties(id)`; `employee_code VARCHAR(30) NN`; `joined_on DATE NN`; `left_on DATE N`; `national_identifier_ciphertext TEXT N`; `national_identifier_hash BINARY(32) N`; `default_cost_centre_id BIGINT UNSIGNED N REFERENCES cost_centres(id)`; `is_active BOOLEAN NN DEFAULT 1`.

Constraints/indexes: `UNIQUE(party_id)`; `UNIQUE(employee_code)`; `UNIQUE(national_identifier_hash)`; index `(is_active,joined_on)`; `CHECK(left_on IS NULL OR left_on>=joined_on)`; boolean check.

Invariants: employee name/contact identity reuses `parties`; no fourth supplier/vendor/customer role is created. Employees are their own optional profile, can hold existing commercial roles, and retain a distinct employee dimension in payroll. Sensitive identifier encrypted with application-managed keys; hash is keyed normalization fingerprint with controlled key rotation, not a plaintext CNIC. Bank destination reuses `party_bank_accounts`. Payroll access and retention require a separate policy.

### F49 `employment_terms`

Columns: `id`; `employee_id NN REFERENCES employees(id)`; `effective_from DATE NN`; `effective_to DATE N`; `job_title VARCHAR(150) NN`; `employment_kind ENUM('permanent','fixed_term','daily_wage') NN`; `pay_basis ENUM('monthly','daily','hourly') NN`; `base_rate R NN`; `standard_month_days Q NN`; `standard_day_hours Q NN`; `cost_centre_id NN REFERENCES cost_centres(id)`.

Constraints/indexes: `UNIQUE(employee_id,effective_from)`; index `(employee_id,effective_from,effective_to)`; `CHECK(effective_to IS NULL OR effective_to>effective_from)`; `CHECK(base_rate>=0 AND standard_month_days>0 AND standard_day_hours>0)`.

Invariants: half-open `[effective_from,effective_to)` intervals; NULL end means infinity; no overlap under employee lock. Salary proration basis is explicit approved policy, not presumed local law. Used terms freeze; changes add a new interval and close only a future-effective unused interval through audited configuration changes.

### F50 `salary_components`

Columns: `id`; `code VARCHAR(30) NN`; `name VARCHAR(150) NN`; `component_kind ENUM('earning','deduction','employer_cost') NN`; `calculation_kind ENUM('fixed','quantity_rate','percent_base') NN`; `ledger_account_id NN REFERENCES ledger_accounts(id)`; `is_taxable BOOLEAN NN DEFAULT 0`; `is_active BOOLEAN NN DEFAULT 1`.

Constraints/indexes: `UNIQUE(code)`; boolean checks.

Invariants: fixed named calculation modes only, no executable free-text formula. Deduction account can be a statutory liability or employee advance control account, with employee dimension where required. Employer cost is expense plus configured liability and never reduces net employee pay. Taxability is configurable metadata; actual payroll tax calculation rules need professional approval.

### F51 `employee_salary_components`

Columns: `id`; `employee_id NN REFERENCES employees(id)`; `salary_component_id NN REFERENCES salary_components(id)`; `effective_from DATE NN`; `effective_to DATE N`; `amount M NN DEFAULT 0`; `rate R NN DEFAULT 0`; `percent_of_base P NN DEFAULT 0`; `default_quantity Q NN DEFAULT 1`.

Constraints/indexes: `UNIQUE(employee_id,salary_component_id,effective_from)`; index `(employee_id,salary_component_id,effective_from,effective_to)`; `CHECK(effective_to IS NULL OR effective_to>effective_from)`; `CHECK(amount>=0 AND rate>=0 AND percent_of_base>=0 AND percent_of_base<=100 AND default_quantity>=0)`.

Invariants: no overlapping same-employee/component periods under employee lock. Calculation-kind selects exactly one input family; inapplicable amount/rate/percentage inputs are zero. Basic pay is represented by an explicit component referencing the active employment rate, never both implicit and explicit duplicate earnings.

### F52 `attendance_entries`

Columns: `id`; `employee_id NN REFERENCES employees(id)`; `work_date DATE NN`; `scheduled_minutes SMALLINT UNSIGNED NN`; `worked_minutes SMALLINT UNSIGNED NN DEFAULT 0`; `overtime_minutes SMALLINT UNSIGNED NN DEFAULT 0`; `paid_leave_minutes SMALLINT UNSIGNED NN DEFAULT 0`; `unpaid_absence_minutes SMALLINT UNSIGNED NN DEFAULT 0`; `approved_by BIGINT UNSIGNED N REFERENCES users(id)`; `approved_at DATETIME(6) N`; `source_reference VARCHAR(100) N`; `notes VARCHAR(500) N`.

Constraints/indexes: `UNIQUE(employee_id,work_date)`; index `(work_date,approved_at)`; `CHECK(scheduled_minutes<=1440 AND worked_minutes<=1440 AND overtime_minutes<=1440 AND paid_leave_minutes<=1440 AND unpaid_absence_minutes<=1440)`; `CHECK(worked_minutes+paid_leave_minutes+unpaid_absence_minutes=scheduled_minutes)`; approved timestamp/by both null or both nonnull.

Invariants: worked_minutes means regular paid work; overtime is additional and regular+overtime <=1440. This is an approved daily aggregate, not raw clock punches or shift scheduling. Once consumed by posted regular payroll it freezes; corrections go into payroll adjustment lines with evidence rather than rewriting paid attendance.

### F53 `payroll_runs`

Document header. Columns: `document_id`; `period_start DATE NN`; `period_end DATE NN`; `pay_on DATE NN`; `run_kind ENUM('regular','adjustment') NN`; `adjusts_payroll_run_id BIGINT UNSIGNED N REFERENCES payroll_runs(document_id)`; `description VARCHAR(500) N`.

Constraints/indexes: index `(period_start,period_end,run_kind)`; `CHECK(period_end>=period_start)`; `CHECK((run_kind='regular' AND adjusts_payroll_run_id IS NULL) OR (run_kind='adjustment' AND adjusts_payroll_run_id IS NOT NULL))`.

Invariants: company lock plus employee-range locks prevent overlapping effective regular payroll for the same employee; multiple disjoint employee groups can run in the same month. Adjustment references an existing posted regular run and uses signed component deltas. Period/accounting document date is explicit; pay date does not retroactively move expense between closed periods.

### F54 `payroll_run_employees`

Columns: `id`; `payroll_run_id NN REFERENCES payroll_runs(document_id)`; `employee_id NN REFERENCES employees(id)`; `employment_term_id NN REFERENCES employment_terms(id)`; `cost_centre_id NN REFERENCES cost_centres(id)`; `payable_account_id NN REFERENCES ledger_accounts(id)`; `earning_amount M NN`; `deduction_amount M NN`; `employer_cost_amount M NN DEFAULT 0`; `net_pay M NN`; `party_bank_account_id BIGINT UNSIGNED N REFERENCES party_bank_accounts(id)`.

Constraints/indexes: `UNIQUE(payroll_run_id,employee_id)`; index `(employee_id,payroll_run_id)`; `CHECK(net_pay=earning_amount-deduction_amount)`.

Invariants: totals are signed snapshots for adjustment support; regular runs require nonnegative totals/net and full line reconciliation. Employee employment terms and bank account match employee party; payroll component lines snapshot multiple terms if salary changes inside the month. Net payable creates a party-control open item with employee dimension. A negative adjustment creates recoverable employee balance rather than a negative cash payment. Employer cost does not enter net pay.

### F55 `payroll_run_lines`

Columns: `id`; `payroll_run_employee_id NN REFERENCES payroll_run_employees(id)`; `line_no SMALLINT UNSIGNED NN`; `salary_component_id NN REFERENCES salary_components(id)`; `employment_term_id BIGINT UNSIGNED N REFERENCES employment_terms(id)`; `employee_salary_component_id BIGINT UNSIGNED N REFERENCES employee_salary_components(id)`; `attendance_entry_id BIGINT UNSIGNED N REFERENCES attendance_entries(id)`; `component_kind_snapshot ENUM('earning','deduction','employer_cost') NN`; `description VARCHAR(255) NN`; `quantity Q NN`; `rate R NN`; `percent_snapshot P NN DEFAULT 0`; `amount M NN`; `expense_account_id BIGINT UNSIGNED N REFERENCES ledger_accounts(id)`; `counter_account_id NN REFERENCES ledger_accounts(id)`; `cost_centre_id NN REFERENCES cost_centres(id)`.

Constraints/indexes: `UNIQUE(payroll_run_employee_id,line_no)`; index `(salary_component_id,payroll_run_employee_id)`; `CHECK(quantity>=0 AND rate>=0 AND percent_snapshot>=0 AND percent_snapshot<=100 AND amount<>0)`.

Invariants: ordinary regular-run amounts positive; adjustment signs explicitly explained and refer to prior run. At least one source (employment term, recurring employee component or attendance) required unless an authorized one-off adjustment with audit evidence. For earnings/employer cost, expense account required; for ordinary deduction it is NULL. Counter-account is payroll payable, statutory liability or employee advance account as appropriate. Line amounts and debits/credits reconcile to employee totals and balanced journal. A deduction recovering an advance posts Cr employee receivable and creates the opposite open item for explicit allocation; it never silently edits an advance.

## Transaction and review contracts

### Ownership and transaction boundaries

- Purchasing owns orders, goods-receipt commercial facts, supplier invoices/credits and invoice matching; Inventory owns physical quantity, FIFO layers and valuation; Accounting owns journal facts and settlement.
- Sales owns customer commitments, dispatch/commercial return facts and invoices/credits. Its posting action orchestrates Inventory and Accounting in one database transaction; it cannot mutate their ledgers directly through generic CRUD.
- Payroll owns approved attendance, pay calculation and payslip snapshots; Accounting owns payroll liabilities and cash settlement. Employees' shared party identities stay in Parties.
- An aggregate is one typed document header with its lines, taxes and matches. Posted documents and child snapshots freeze together. Inventory/journal/open-item append operations and posting status are one atomic transaction.

### Posting matrix (proposed accounting policy, subject to accountant approval)

| Event | Quantity / custody | Financial effect |
|---|---|---|
| Purchase order | None | None |
| Goods receipt | Increase owned internal stock | Dr inventory; Cr GRNI at provisional receipt cost |
| Matched stock purchase invoice | None | Dr GRNI, Dr/Cr inventory or COGS variance via lineage, Dr recoverable input tax; Cr party AP |
| Contractor custody dispatch | Internal to contractor owned location | No expense/revenue; any inventory/WIP reclassification nets to zero total owned value |
| Accepted processing receipt | Transforms/returns owned stock | Add approved service cost to receiving WIP/item; Cr processing accrual |
| Matched processing invoice | None | Dr processing accrual, Dr/Cr lineage cost variance, Dr recoverable tax; Cr party AP |
| Supplier physical return | Decrease actual returned stock | Dr supplier-return clearing (or proven uninvoiced GRNI); Cr inventory |
| Supplier credit | None | Dr party AP; Cr return clearing / cost adjustment / expense and input-tax reversal |
| Sales dispatch | Decrease finished stock at ownership transfer | Dr party unbilled receivable; Cr revenue at frozen order price; Dr COGS; Cr finished inventory at traced actual cost |
| Sales invoice after dispatch | None | Dr billed party AR; Cr non-control unbilled receivable for matched dispatch value; Cr configured output tax and explicit rounding |
| Customer physical return | Increase saleable/quarantine stock | Dr inventory; Cr COGS at traceable original cost, with separate impairment if needed |
| Customer credit | None | Dr revenue reduction and output-tax reversal; Cr party AR |
| Employee expense claim | None | Dr approved expense/input tax; Cr employee payable |
| Payroll run | None | Dr payroll/employer expenses; Cr employee payable and deduction/employer liabilities; Cr employee advance receivable where recovered |
| Payment | None | Dr party payable/advance receivable; Cr bank and applicable withholding liability |
| Receipt | None | Dr bank and approved withholding receivable; Cr party receivable/payable refund |
| Settlement batch | None | Allocation facts only; no repeated journal |

### Concurrency and accounting accuracy

1. Lock period/year, root document/idempotency key, party/role or employee, commercial source lines, stock positions/layers and open items in a documented stable order. Use finite deadlock retry of the whole transaction; retry key returns the original committed result.
2. Never rely on a `CHECK` for sums, role equality, date range overlap, account trees or invoice caps. Same-row checks are DB enforced; posting services compute locked cross-row facts. Future tests must race posting, settlement, credit, return and period-close transactions.
3. Journal totals must balance to four decimal PKR places after explicit rounding. A rounding GL account captures approved rounding; no hidden penny offsets in stock quantities or payment allocations.
4. Posting failure must roll back document status, sequence allocation as designed, inventory quantities/valuation, journals, open items and allocation effects together. External notifications happen after commit via a later outbox design if needed.
5. Reversal is a new document with exact opposite ledger effects, explicit original reference and an open accounting period. Never delete/overwrite posted history. Block reversal while unreversed dependent receipts/invoices/credits/allocations would lose their source; unwind dependencies deliberately.
6. Trial balance derives only from posted journal lines. AP/AR aging derives from journal control lines and effective allocations; stock GL agrees with inventory valuation at the same cutoff. Root state `reversed` does not exclude original facts from totals: original plus opposite reversal must both be included.
7. Primary account balances, party balances, margin and stock values are reporting projections, never editable authority. Each materialized projection needs reconciliation and rebuild from facts.
8. Large tables use `(foreign_key,id)` or `(account,document)` access paths; joins reach document accounting dates through indexed document fields. Do not partition FK-rich InnoDB tables prematurely; archive only with a separately approved retention design.

### Review checklist

- [x] Shared party identity and supplier/vendor/customer profiles preserved.
- [x] Goods receipt, invoice and payment facts separated; GRNI prevents double capitalization.
- [x] Processing acceptance/rate accrual separated from invoice variance.
- [x] Dispatch/physical return quantity separated from invoice/credit revenue.
- [x] Partial quantities, prices, tax reversals and split matches have explicit sources and caps.
- [x] AP/AR/payroll liabilities derive from the journal; allocations never duplicate financial posting.
- [x] Multi-role parties cannot silently cross-net AR/AP; employee identities avoid a new independent party master.
- [x] Defaults, exact DECIMAL types, nullable columns, FKs, UQs and indexes specified.
- [x] Range overlaps, cross-row balances and concurrency explicitly assigned to locked posting actions.
- [x] Period-close and reversal rules preserve immutable history.
- [x] No rates, statutory thresholds or Pakistani legal compliance claims invented.
- [ ] Business/accountant approval of ownership transfer, dispatch-before-invoice, GRNI/accrual, tax timing and payroll policies.
- [ ] Implementation and automated integrity/concurrency tests in a later authorized phase.
