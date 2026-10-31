# Phase 12 — Accounting and finance

## Processing follow-up ? 2026-10-27

The authorized [Fabric Out / Fabric In workflow](PROCESSING_FABRIC_FLOW.md) adds automatic
processed output identities, laat/component evidence, per-suit embroidery contracts
and atomic direct vendor handovers. Daily entry no longer requires manual routes
or jobs; historical and Production-linked workflows remain available. One additive
flow-history table and one service configuration preserve existing stock, journals
and permissions. Physical base-unit quantities remain authoritative; component
counts do not automatically create finished suits. Earlier Processing workflow
requirements below are historical where superseded by that guide.


Implementation date: 2026-10-26. Verification and local activation results are
recorded in PROJECT_STATE.md and TESTING.md. Earlier phase exclusions of payments
and finance are superseded by this authorized phase; tax and payroll remain deferred.

## Guided entry follow-up (2026-10-29)

Payments, receipts and Apply existing credit now open a party-first guided screen.
Select the role to see current unpaid items and available advance/credit; choose
actual money movement or adjustment on the same screen. The matching control is
selected when unique. Amount suggestions remain editable and backend-validated.
The original forms below remain available through Advanced, including multiple
allocations. See [FINANCE_GUIDED_UX.md](FINANCE_GUIDED_UX.md). No schema change.

## Operator workflow

Physical Suit Stock now provides simplified opening/actual conversion entry and
Sales client history shows customer-role AR debit/credit and net balance. Existing
Finance settlements remain authoritative. See [PHYSICAL_SUIT_STOCK.md](PHYSICAL_SUIT_STOCK.md).

1. Open **Accounting & finance → Accounts & cash / bank**. Existing inventory,
   supplier and sales accounts are reused. Add ordinary accounts or non-posting
   groups as needed. For each actual bank account or cash box, first create an
   asset account with `bank_cash` control, then configure its named cash/bank record.
   No money or opening balance is seeded.
2. Keep the transaction date within an open financial year and accounting period.
   The existing Financial calendar screens remain authoritative. Future posting
   dates are rejected using the company business date.
3. Supplier and contractor bills already create payables. Use **Payments**, choose
   the shared supplier/vendor role, actual bank/cash account, AP control, method,
   amount and evidence. Select an unpaid bill and amount to apply, or leave the
   payment on account. Confirm posts both the voucher and any allocation atomically.
4. Sales invoices already create receivables. Use **Receipts**, choose the customer,
   AR control and actual money received. A partial receipt leaves the remainder
   outstanding. Receipts reduce Wholesale credit exposure through existing journals.
   They do not recognize sales revenue a second time.
5. **Apply existing credit** matches an existing debit and credit item for one party
   and control account. This handles advances, commercial credits and payment
   allocation to additional bills. It has no additional GL effect.
6. **Supplier return credits** links the supplier's credit reference to the billed
   receipt and physical return. Exact returned carrying value clears supplier-return
   clearing and debits AP. Then apply the debit open item to the invoice or retain
   it for a supplier refund. Unbilled returns already relieve GRNI and cannot be
   credited through this workflow. A supplier refund uses Receipts with supplier AP;
   a customer refund uses Payments with customer AR.
7. **Journal voucher** records ordinary debit/credit corrections. It cannot post to
   party, inventory or cash/bank controls. **Cash / bank journal** handles evidenced
   bank fees, ordinary cash expenses and transfers between configured bank/cash
   accounts; it cannot bypass party or inventory subledgers.
8. **Opening balances** requires the original signed schedule reference. Enter
   balanced financial lines; party-control lines require the actual role and due
   date. Inventory opening belongs in Inventory and already posts its valuation.
   Financial opening adds transactions; it never replaces existing balances. Review
   the migration schedule to avoid importing amounts already represented in ERP.
9. **Bank reconciliation** records signed statement transactions and immutable
   evidence. Opening plus statement inflows/outflows must equal closing. Match
   each statement transaction to book lines (split/combined matches are allowed).
   Every statement line must be fully matched; timing differences must equal
   unmatched book flows. First statement opening must agree to pre-statement books;
   start earlier when opening outstanding transactions need matching. No unexplained
   balancing plug or bank-fee shortcut is permitted. Reverse the reconciliation
   before backdated bank corrections; then reconcile again.
10. Review trial balance, P&L, balance sheet, GL, party statements, open items with
    due dates/overdue days and source reconciliation. Choose as-of dates and accounts.
    Supplier/customer roles on one party remain distinct controls; no automatic
    cross-account offset. Voucher pages have A4 PDF downloads and print views;
    report print explicitly covers the visible paginated page.

## Posting and source integration

| Source | Recognition / effect |
| --- | --- |
| Goods receipt | Existing Dr inventory / Cr GRNI |
| Matched supplier bill | Existing Dr GRNI / Cr supplier AP; no second receipt |
| Processing receipt | Existing actual service accrual into inventory or loss |
| Matched contractor bill | Existing Dr processing accrual / Cr supplier AP |
| Production / packing | Existing actual stock value transfer; cost-neutral conversion creates no fake expense |
| Sales dispatch | Existing Dr COGS / Cr inventory and Dr unbilled AR / Cr revenue |
| Sales invoice | Existing Dr customer AR / Cr unbilled AR |
| Payment | Dr selected party control / Cr actual bank or cash |
| Receipt | Dr actual bank or cash / Cr selected party control |
| Settlement | Append-only allocations; no new GL journal |
| Supplier physical return credit | Dr original invoice AP / Cr actual supplier-return clearing |
| Financial reversal | Equal opposite journal, unwind affected allocations, close original/opposite open items |

One journal header uses the original `documents.id` as its PK. No duplicate
invoice/finance root is generated. Existing `SourceJournal` is reused for supplier
credits; FinanceService writes dimensioned manual/opening/cash/reversal lines into
the same journal schema. Positive single-side lines, at least two lines and exact
four-decimal equality are validated before insert. Header/line/open-item constraints
provide further DB protection. Posted lines and original open amounts are immutable.

All commands run in DB transactions with the existing company-row mutex, active
actor permissions, row locks, date/period validation, document numbering and audit
events. Matching items are locked in ID order. Stable UUID keys plus validated
payload hashes replay identical confirmations and reject changed-key reuse. Cash
and optional settlement commit together. Settlement caps account for repeated lines
inside the same request as well as existing allocations. Allocation backdating
before existing item settlement history is rejected.

Outstanding is original open amount less signed posted allocations. Reversal
allocation rows reference the exact original pair/value; reversal-of-reversal is
blocked. A reversed voucher retains its original journal and gets one opposite
journal. Operational stock/processing/sales documents cannot be reversed through
finance alone. Matched bank vouchers require bank reconciliation reversal first.
Reports include both `posted` and `reversed` originals together with reversal entries.
No editable party balance or remaining-amount field exists.

## Schema and migration impact

Two additive migrations, no rewrite of existing operational rows or journal history:

* `2026_10_26_000003_create_finance_settlement_tables.php`: `bank_accounts`,
  `payments`, `receipts`, `settlement_batches`, `settlement_allocations`,
  `bank_statements`, `bank_statement_lines`, `bank_reconciliations`,
  `bank_reconciliation_matches` (nine tables, approved F05/F09-F12/F15-F18).
* `2026_10_26_000004_create_purchase_financial_credits.php`: `purchase_credit_notes`
  and `purchase_credit_note_lines` (approved F30-F31).

Account groups use approved `ledger_accounts.parent_id` with `is_postable=false`;
there is no redundant group master. Financial calendar, cost centres, ledger
accounts, journals, open items and posting-purpose accounts already existed.
All new relationships restrict delete/update. Document headers use source document
PK/FKs; line numbers, bank GL account mapping, statement references, reversals and
supplier credit references have unique constraints. Composite party/role ownership
FKs protect payment/receipt identity. DECIMAL(20,4) money, DECIMAL(20,6) credit quantity.

Approved implementation choices / differences from the complete Phase 1 design:

* Payments/receipts have no employee column until HR/payroll is implemented; existing
  journal employee dimension remains NULL-only. No employee/payroll controls are exposed.
* Tax child tables remain absent. CHECKs require bank amount = gross; supplier
  credits have zero tax/rounding and actual physical return only. No implied tax rates.
* Statement reconciliation `other_reconciling_amount` is constrained to zero;
  itemized supported book entries and matches must explain differences.
* New ancillary timestamps follow Phase 11's nullable Laravel TIMESTAMP(6) convention.
  Statement confirmation uses DATETIME(6). No historical timestamp conversion.
* Cash/bank fee/transfer journals use the existing journal tables with a distinct
  Finance document type and configured bank-account validation, not an extra ledger.
* Supplier price/service credits requiring downstream cost redistribution remain
  deferred. Actual return credits have cumulative quantity/value caps and residual
  rounding. Full reversal restores the physical-return credit capacity while the
  original supplier reference remains reserved for audit traceability.

Migration `down()` refuses destructive removal of financial history. Take a private
SQL backup, migrate forward, run scoped FinanceSetupSeeder, then verify original-row
fingerprints and empty new operational tables. Do not use migrate:fresh on application DB.

## Authorization and screens

Six new permissions: finance view, post, configure, opening, reverse and reconcile
under `accounting.finance.*`. Existing calendar permissions are separate.
FinanceSetupSeeder attaches accounting permissions to the existing super-admin
without altering other roles/users. Wholesale receives no finance grants. Fresh
calendar-admin seeding explicitly restricts that role to calendar permissions.
FinancePolicy protects journal/source detail and PDF access; authenticated active
user/permission middleware protects every entry point, with service-layer checks.

Routes under `/accounting/finance`: overview, create, document confirm/detail/PDF/
print, chart, bank configuration, financial reports, bank reconciliation, statement
confirmation and supplier return credits. Sidebar has separate task links; the
existing calendar remains accessible. Confirm is one action; no extra approval
hop or mandatory draft stage is introduced for the single authorized admin.

## File inventory

* Accounting services: FinanceService, FinanceAccounts, FinanceReports,
  BankReconciliationService, SupplierCreditService, FinanceDocument.
* FinanceController, FinanceRequest, FinancePolicy, Accounting Routes/web.php.
* Accounting finance Blade views: nav, index, form, show, accounts, report, bank,
  supplier-credit; reusable ERP finance-navigation sidebar component.
* Models: BankAccount, Payment, Receipt, SettlementBatch, SettlementAllocation,
  BankStatement, BankStatementLine, BankReconciliation, BankReconciliationMatch,
  PurchaseCreditNote, PurchaseCreditNoteLine.
* FinanceConfigurationSeeder, FinanceSetupSeeder; DatabaseSeeder,
  IdentityAuthorizationSeeder and erp_permissions integration.
* Core AuditLogger allowlist; core layout and shared transaction PDF reversed-state
  presentation. Prior purchasing/processing/production/sales posting logic is reused.
* FinanceTest, FinanceConcurrencyTest, FinanceSalesIntegrationTest,
  FinanceFixtures and independent-worker integration; additional checks below.

## Review checklist and limits

- [x] Shared party identity; no fake financial balances or invented bank account.
- [x] Exact balanced posting and one journal per financially posting source.
- [x] Partial payments, advances, explicit allocation and controlled reversal.
- [x] Company/row locks, keys, period validation and backend authorization.
- [x] Existing source recognition reused; no duplicated stock/revenue/manufacturing cost.
- [x] Actual supplier-return clearing gap connected to matched financial credit.
- [x] Reports derive from posted journals; source/party/inventory reconciliation is read-only.
- [x] Full regression, browser/PDF evidence and safe local activation: see
  [PROJECT_STATE.md](PROJECT_STATE.md) and [TESTING.md](TESTING.md).
- [ ] Exact MySQL 8.4 validation and accountant/operator acceptance.

Local engine remains MariaDB 10.4.32, not the MySQL 8.4 production target.
No tax, payroll, statutory filings, automated financial-year closing, multicurrency,
bank API/CSV import or automatic payment execution. Statements are manually entered
evidence. These features do not send money. Finance-only reversing operational stock
sources is intentionally blocked. Report page printing is paginated; full report
export is not claimed. No draft editing for finance vouchers; confirm or cancel the
unsubmitted form. Account activation changes do not remove historical entries.

## Completion report

Features, files, tables and screens are listed above. The complete regression passed
236 tests / 1,683 assertions; the final Finance-focused run passed 18 tests / 100
assertions; Node passed 32 tests; and Vite built 69 modules. Both migrations ran as
local batch 10 after a private backup. Eleven new operational tables remain empty,
while prior operational/history fingerprints and unrelated role grants were
preserved. Sixteen Finance pages returned 200. See [PROJECT_STATE.md](PROJECT_STATE.md)
and [TESTING.md](TESTING.md) for the evidence and current MariaDB/MySQL limitation.
Documentation includes this operator guide plus architecture, schema/ERD, decisions,
migration, business rules, deployment, PRD, state and changelog updates.

Suggested commit: `feat(accounting): add auditable finance vouchers settlements and statements`.
Stop after Phase 12; do not begin HR/payroll or tax without a separate request.
