# Commercial and finance relationship diagrams

> Phase 8 now implements contractor processing bill matches and shared payable
> source journals. See [PROCESSING.md](../PROCESSING.md) for the executable
> subset and limits; the remaining finance diagrams describe approved design.

These Mermaid diagrams are logical relationship views. Exact FK nullability, uniqueness and types are in [COMMERCIAL_FINANCE.md](COMMERCIAL_FINANCE.md).

## Purchasing and payables

```mermaid
erDiagram
    parties ||--o{ party_roles : holds
    party_roles ||--o| supplier_profiles : supplier
    supplier_profiles ||--o{ purchase_orders : receives
    purchase_orders ||--|{ purchase_order_lines : contains
    supplier_profiles ||--o{ goods_receipts : supplies
    goods_receipts ||--|{ goods_receipt_lines : contains
    purchase_order_lines o|--o{ goods_receipt_lines : fulfilled_by
    stock_positions ||--o{ goods_receipt_lines : receives_at
    stock_movements o|--o| goods_receipt_lines : posts
    party_roles ||--o{ purchase_invoices : billed_by
    purchase_invoices ||--|{ purchase_invoice_lines : contains
    purchase_invoice_lines ||--o{ purchase_invoice_receipt_matches : stock_match
    goods_receipt_lines ||--o{ purchase_invoice_receipt_matches : billed
    purchase_invoice_lines ||--o{ purchase_invoice_processing_matches : service_match
    processing_receipt_lines ||--o{ purchase_invoice_processing_matches : accepted_service
    purchase_invoice_lines ||--o{ purchase_invoice_line_taxes : taxed
    purchase_returns ||--|{ purchase_return_lines : contains
    goods_receipt_lines ||--o{ purchase_return_lines : original_receipt
    purchase_invoices ||--o{ purchase_credit_notes : credited
    purchase_credit_notes ||--|{ purchase_credit_note_lines : contains
    purchase_invoice_lines ||--o{ purchase_credit_note_lines : original_line
    purchase_return_lines o|--o{ purchase_credit_note_lines : physical_evidence
    purchase_credit_note_lines ||--o{ purchase_credit_note_line_taxes : tax_reversal
    purchase_invoice_line_taxes ||--o{ purchase_credit_note_line_taxes : original_tax
```

## Sales and receivables

```mermaid
erDiagram
    party_roles ||--o| customer_profiles : customer
    customer_profiles ||--o{ sales_orders : places
    sales_orders ||--|{ sales_order_lines : contains
    sales_order_lines ||--o{ sales_dispatch_lines : fulfilled_by
    sales_dispatches ||--|{ sales_dispatch_lines : contains
    stock_positions ||--o{ sales_dispatch_lines : ships_from
    stock_movements o|--o| sales_dispatch_lines : posts
    customer_profiles ||--o{ sales_invoices : billed
    sales_invoices ||--|{ sales_invoice_lines : contains
    sales_order_lines ||--o{ sales_invoice_lines : priced
    sales_invoice_lines ||--|{ sales_invoice_dispatch_matches : matched
    sales_dispatch_lines ||--o{ sales_invoice_dispatch_matches : billed
    sales_invoice_lines ||--o{ sales_invoice_line_taxes : taxed
    sales_returns ||--|{ sales_return_lines : contains
    sales_dispatch_lines ||--o{ sales_return_lines : returned
    sales_invoices ||--o{ sales_credit_notes : credited
    sales_credit_notes ||--|{ sales_credit_note_lines : contains
    sales_invoice_lines ||--o{ sales_credit_note_lines : original_line
    sales_return_lines o|--o{ sales_credit_note_lines : physical_evidence
    sales_credit_note_lines ||--o{ sales_credit_note_line_taxes : tax_reversal
    sales_invoice_line_taxes ||--o{ sales_credit_note_line_taxes : original_tax
```

## Journal, settlement and banking

```mermaid
erDiagram
    financial_years ||--|{ accounting_periods : contains
    accounting_periods ||--o{ documents : dates
    documents ||--o| journal_entries : financial_effect
    journal_entries ||--|{ journal_lines : balances
    ledger_accounts ||--o{ journal_lines : classifies
    parties o|--o{ journal_lines : subledger_party
    cost_centres o|--o{ journal_lines : dimension
    journal_lines ||--o| party_open_items : control_fact
    settlement_batches ||--|{ settlement_allocations : records
    party_open_items ||--o{ settlement_allocations : debit_item
    party_open_items ||--o{ settlement_allocations : credit_item
    parties ||--o{ payments : paid
    parties ||--o{ receipts : received
    payments ||--o{ payment_tax_lines : deducts
    receipts ||--o{ receipt_tax_lines : withheld
    bank_accounts ||--o{ payments : cash_out
    bank_accounts ||--o{ receipts : cash_in
    ledger_accounts ||--o| bank_accounts : identifies
    bank_accounts ||--o{ bank_statements : evidence
    bank_statements ||--|{ bank_statement_lines : contains
    bank_accounts ||--o{ bank_reconciliations : reconciled
    bank_reconciliations ||--o{ bank_reconciliation_matches : matches
    bank_statement_lines ||--o{ bank_reconciliation_matches : bank_side
    journal_lines ||--o{ bank_reconciliation_matches : book_side
```

## Payroll and expenses

```mermaid
erDiagram
    parties ||--o| employees : employee_identity
    employees ||--o{ employment_terms : historical_contract
    employees ||--o{ employee_salary_components : recurring_pay
    salary_components ||--o{ employee_salary_components : configured
    employees ||--o{ attendance_entries : daily_approved
    payroll_runs ||--|{ payroll_run_employees : payslips
    employees ||--o{ payroll_run_employees : paid
    payroll_run_employees ||--|{ payroll_run_lines : calculated
    salary_components ||--o{ payroll_run_lines : frozen_kind
    employment_terms o|--o{ payroll_run_lines : frozen_rate
    attendance_entries o|--o{ payroll_run_lines : evidence
    employee_salary_components o|--o{ payroll_run_lines : configured_source
    employees o|--o{ journal_lines : employee_dimension
    employees ||--o{ expense_claims : claims
    expense_claims ||--|{ expense_claim_lines : reimbursable
    ledger_accounts ||--o{ expense_claim_lines : expense
    cost_centres ||--o{ payroll_run_lines : cost_assignment
```
