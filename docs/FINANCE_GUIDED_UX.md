# Guided Finance entry — 2026-10-29

Authorized follow-up within Phase 12. No schema, posting rules, permissions or new
business phase. Existing FinanceService cash and settlement commands remain authoritative.

## Daily workflow

Open Payments, Receipts or Apply existing credit. Select the actual party and
business role. The matching control account is selected automatically when unique;
multiple controls require an explicit choice. Current unpaid items and available
advance/credit are shown separately for that exact role/account, not lifetime totals.
These current summaries are not historical as-of-date statements.

Choose one action on the same screen:

* Record actual money: choose cash/bank, enter the full amount and optionally choose
  an unpaid item. The applied amount defaults to the lesser of money and outstanding
  and remains editable. Excess stays on account. Cash/bank selection suggests cash
  or bank transfer; method remains editable for cheque/other. Customer refunds and
  supplier refunds select the opposite outstanding side correctly.
* Adjust existing advance/credit: choose its source and an unpaid bill/invoice.
  The lesser available amount is suggested. Confirm submits only an existing
  settlement allocation, without a cash/bank payload or a new cash journal.

For a supplier advance of 30,000 followed by a 100,000 bill, select the same role,
choose adjustment, select the advance and full bill, and confirm 30,000. The bill
then has 70,000 unpaid. Record the remaining actual payment separately when made.
The system does not assume an unrecorded payment or silently consume an advance.

Switching role/account/action clears selections. Existing backend locks, exact
DECIMAL validation, identity/control matching, date checks, idempotency, atomic
posting, reversals and Wholesale restrictions remain in force. Display suggestions
use exact four-decimal integer arithmetic; the backend rechecks current amounts.

## Deliverables and review

Changed FinanceController; added guided Blade screen and finance.js registered in
app.js. Existing routes `/accounting/finance/create?kind=payment`, `receipt` and
`settlement` now render guided entry. `advanced=1` preserves the original form,
including multiple allocations. No migration, table, seed or application-data write.
Production Vite assets must be rebuilt for deployment. Other financial reports,
account setup, calendar and operational source documents retain their workflows.

Checks: see TESTING.md for final results. Added UI-state tests and an HTTP test for
real bill/advance data, plus browser assertions for settlement/payment payload
isolation and desktop/mobile rendering. Existing Finance regression covers actual
settlement effects, reversals and backend permissions.

Known limits: adjustments select one advance and one bill at a time in the guided
screen; use Advanced for multiple allocations. Values may change after page load;
confirmation revalidates. No automatic allocation, historical summary, bank API,
tax or payroll was added. Exact production MySQL 8.4 acceptance remains pending.

Suggested commit: `feat(finance): guide party payments and advance adjustments`.
Ready for operator review after the recorded checks; stop within Phase 12.
