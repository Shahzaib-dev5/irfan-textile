# Old unpaid balance when adding a party

Authorized follow-up to Phase 12, implemented 2026-11-16.

## Operator flow

Open **Parties > Add party**, enter the shared identity and select its business
roles. Users with `accounting.finance.opening` also see **Old unpaid balance
(optional)**. The default, **No old unpaid balance**, creates the party normally.

For an existing debt, choose **We will receive money from this party** (Customer)
or **We will pay money to this party** (Supplier or Vendor). Enter the amount still
unpaid, original invoice or verified schedule reference, opening date in an open
accounting period, and original due date. With Supplier and Vendor both selected,
choose the role responsible for this obligation. A single eligible role is selected
automatically. Save party confirms the financial opening in the same transaction.

This section accepts one old invoice or one verified outstanding schedule total.
For separate invoice aging and settlement, enter further invoices individually in
**Finance > Old unpaid balances**. Do not enter an invoice or opening amount twice.
The Sales quick-customer dialog and legacy role-specific forms retain their existing
flow; use **Parties > Add party** for this combined creation workflow.

Then use **Finance > Cash receipt voucher** to receive customer money, or **Cash
payable voucher** to pay the supplier/vendor. Select the opening item to apply a
full or partial amount. The normal party ledger and Unpaid bills report show the
same entry. Credit limit remains a separate customer setting.

## Accounting and integrity

| Direction | Party control line | Balancing line |
| --- | --- | --- |
| Receive from Customer | Debit configured `customer_receivable` | Credit configured `opening_equity` |
| Pay Supplier / Vendor | Credit configured `supplier_payable` | Debit configured `opening_equity` |

Posting purposes resolve account IDs; no account codes or IDs are hard-coded.
Both controls and opening equity must be active posting accounts with the correct
classification. The existing `FinanceService::confirm` creates a numbered posted
`finance_opening` document, balanced journal, immutable party open item and audit
history. No stock, current sales revenue, procurement receipt or bill is recreated.

`CreatePartyRequest` validates positive four-decimal amounts, evidence, dates,
role/direction compatibility and opening permission. The controller checks each
selected role's existing management permission. The company-row lock covers replay,
party/profile/contact/address creation and opening confirmation. Any failure rolls
back the whole operation, including audit rows and document numbering.

The form retains a UUID request key after validation errors. A party audit event
links the opening document to the shared identity and a hash of the creation
payload. Identical requests by the same authorized actor return the existing
creation without writing again; changed payloads or reused foreign keys are
rejected. Replays require the original voucher still to be posted. This does not
deduplicate different invoices or different request keys by invoice number.

Party editing does not expose an opening input and rejects posted opening fields.
Changing a posted amount requires the existing authorized Finance reversal and a
corrected opening. No editable party balance is introduced. Wholesale keeps its
existing customer permissions and receives no Finance opening permission.

## Implementation and deployment

Changed/new implementation files:

- `app/Modules/Parties/Http/Requests/CreatePartyRequest.php`
- `app/Modules/Parties/Services/PartyOpeningBalance.php`
- `app/Modules/Parties/Http/Controllers/PartyManagementController.php`
- `app/Modules/Parties/Resources/views/management/party-form.blade.php`
- `resources/views/components/erp/party-opening-balance.blade.php`
- `tests/Feature/PartyOpeningBalanceTest.php`
- `tests/Frontend/party-opening-browser.mjs`

The existing GET `/parties/create`, POST `/parties` and PUT `/parties/{party}`
routes are reused. No route, permission, package, table, column or migration is
added. Records use existing parties/profiles, documents, journals, open items and
audit events. Existing Finance configuration and an open accounting calendar are
prerequisites. Deploy the source and refresh compiled views if cached; no seeder,
data reset, migration or asset rebuild is required for this follow-up.

Focused regression passed **28 tests / 257 assertions**. Browser checks and visual
review passed for the synthetic desktop/mobile form. More evidence is in
[TESTING.md](TESTING.md). Exact MySQL 8.4 and live
operator/accountant acceptance remain separate from local test results.

Suggested commit: `feat(parties): post optional old unpaid balance on creation`.
