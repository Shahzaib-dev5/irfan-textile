# Entity relationship diagrams

## Processing fabric flow addition (2026-10-27)

[Simple fabric flows](PROCESSING_FABRIC_FLOW.md) add `processing_fabric_flows`
with a unique request UUID, canonical input hash, JSON evidence, draft/posted status,
creator FK and nullable unique document/onward-document FKs. CHECKs require posted
flows to have a document and onward links to belong to inbound flows. Existing
Processing, stock and journal schemas and old payload hashes are unchanged.
`ProcessingFabricSetupSeeder` adds the per-suit embroidery service without changing
existing piece-rate contracts. No component count is an independent stock balance.


## Phase 12 implemented finance relationships (2026-10-26)

```mermaid
erDiagram
    ledger_accounts ||--o{ ledger_accounts : parent_group
    ledger_accounts ||--o| bank_accounts : cash_bank_control
    documents ||--o| journal_entries : single_source_journal
    journal_entries ||--|{ journal_lines : balanced_lines
    ledger_accounts ||--o{ journal_lines : account
    parties ||--o{ party_roles : shared_identity
    party_roles ||--o{ journal_lines : commercial_context
    journal_lines ||--o| party_open_items : immutable_amount
    documents ||--o| payments : cash_out
    documents ||--o| receipts : cash_in
    bank_accounts ||--o{ payments : pays
    bank_accounts ||--o{ receipts : receives
    documents ||--o| settlement_batches : allocation_source
    settlement_batches ||--|{ settlement_allocations : applications
    party_open_items ||--o{ settlement_allocations : debit_item
    party_open_items ||--o{ settlement_allocations : credit_item
    settlement_allocations ||--o| settlement_allocations : exact_reversal
    bank_accounts ||--o{ bank_statements : evidence
    bank_statements ||--|{ bank_statement_lines : signed_transactions
    documents ||--o| bank_reconciliations : snapshot
    bank_reconciliations ||--|{ bank_reconciliation_matches : matches
    bank_statement_lines ||--o{ bank_reconciliation_matches : evidence_line
    journal_lines ||--o{ bank_reconciliation_matches : book_line
    documents ||--o| purchase_credit_notes : supplier_credit
    purchase_invoices ||--o{ purchase_credit_notes : original_invoice
    purchase_credit_notes ||--|{ purchase_credit_note_lines : credit_lines
    purchase_return_lines ||--o{ purchase_credit_note_lines : actual_return
    purchase_invoice_receipt_matches ||--o{ purchase_credit_note_lines : provenance
```

Reports are projections, not separate editable ledgers. Existing source modules
retain document identity. See [ACCOUNTING_FINANCE.md](ACCOUNTING_FINANCE.md).

## Phase 11 implemented Wholesale relationships (2026-10-26)

```mermaid
erDiagram
    parties ||--o{ party_roles : identity
    party_roles ||--o| customer_profiles : customer
    customer_profiles ||--o{ sales_orders : places
    documents ||--o| sales_orders : header
    sales_orders ||--|{ sales_order_lines : contains
    sales_order_lines ||--o{ stock_reservations : reserves
    stock_positions ||--o{ stock_reservations : holds
    sales_order_lines ||--o{ sales_dispatch_lines : fulfills
    sales_dispatches ||--|{ sales_dispatch_lines : contains
    stock_positions ||--o{ sales_dispatch_lines : issues
    stock_movements ||--o| sales_dispatch_lines : evidence
    sales_dispatch_lines ||--o{ sales_invoice_dispatch_matches : bills
    sales_invoices ||--|{ sales_invoice_lines : contains
    sales_invoice_lines ||--|{ sales_invoice_dispatch_matches : matches
    sales_returns ||--|{ sales_return_lines : contains
    sales_dispatch_lines ||--o{ sales_return_lines : returns
    stock_positions ||--o{ sales_return_lines : receives
    sales_invoices ||--o{ sales_credit_notes : credits
    sales_credit_notes ||--|{ sales_credit_note_lines : contains
    sales_invoice_dispatch_matches ||--o{ sales_credit_note_lines : caps
    sales_return_lines |o--o{ sales_credit_note_lines : physicalEvidence
    documents ||--o| journal_entries : sourceSidecar
    journal_entries ||--|{ journal_lines : balances
    journal_lines ||--o| party_open_items : controlLine
```

The nullable reservation FK also enforces its order document through a composite
parent FK. All source headers share documents; stock/value genealogy uses existing
Inventory allocations/cost links. No duplicated customer, stock or AR master. Tax
children and settlements remain future. Detailed slice: [SALES.md](SALES.md).

## Implemented Phase 10 packing relationships (2026-10-26)

These are actual migration FKs. Package/content rows represent physical provenance,
not a second stock total. See [FINISHED_GOODS_COSTING.md](FINISHED_GOODS_COSTING.md).

```mermaid
erDiagram
    ITEMS ||--o{ PRODUCT_PACK_SPECS : packed_SKU
    PRODUCT_PACK_SPECS ||--|{ PRODUCT_PACK_SPEC_LINES : contents
    ITEMS ||--o{ PRODUCT_PACK_SPEC_LINES : component
    DOCUMENTS ||--o| PACKING_ORDERS : conversion
    PRODUCT_PACK_SPECS ||--o{ PACKING_ORDERS : frozen_version
    PACKING_ORDERS ||--|{ PACKING_MATERIAL_LINES : actual_input
    PACKING_ORDERS ||--|{ PACKING_OUTPUT_LINES : actual_receipt
    PRODUCT_PACK_SPEC_LINES o|--o{ PACKING_MATERIAL_LINES : requirement
    STOCK_POSITIONS ||--o{ PACKING_MATERIAL_LINES : source
    STOCK_POSITIONS ||--o{ PACKING_OUTPUT_LINES : target
    STOCK_MOVEMENTS o|--o| PACKING_MATERIAL_LINES : posted_issue
    STOCK_MOVEMENTS o|--o| PACKING_OUTPUT_LINES : posted_receipt
    PACKING_OUTPUT_LINES ||--o{ PACKAGES : represents
    PACKAGES ||--|{ PACKAGE_CONTENTS : provenance
    PACKING_MATERIAL_LINES ||--o{ PACKAGE_CONTENTS : actual_consumption
```

Packing receives PACK lots; full recovery receives component lots without new
containers. Existing cost links connect both to Production/Processing/source lots.
Historical/current costs remain derived from posted entries, not new cost masters.

## Implemented Phase 9 relationships (2026-10-26)

These edges are actual FKs in the additive Production migration. The detailed
columns remain in [database/INVENTORY_PRODUCTION.md](database/INVENTORY_PRODUCTION.md).
Older statements that all Production is future scope are superseded.

```mermaid
erDiagram
    PRODUCT_VARIANTS ||--o{ BILLS_OF_MATERIALS : defines
    BILLS_OF_MATERIALS ||--|{ BILL_OF_MATERIAL_LINES : recipe
    ITEMS ||--o{ BILL_OF_MATERIAL_LINES : component
    DOCUMENTS ||--o| MANUFACTURING_BATCHES : typed_plan
    BILLS_OF_MATERIALS ||--o{ MANUFACTURING_BATCHES : freezes_version
    MANUFACTURING_BATCHES ||--|{ MANUFACTURING_BATCH_MATERIALS : frozen_requirements
    BILL_OF_MATERIAL_LINES ||--o{ MANUFACTURING_BATCH_MATERIALS : source
    MANUFACTURING_BATCHES o|--o{ PROCESSING_JOBS : optional_batch
    DOCUMENTS ||--o| PRODUCTION_REPORTS : typed_conversion
    MANUFACTURING_BATCHES ||--o{ PRODUCTION_REPORTS : reports
    PRODUCTION_REPORTS ||--o{ PRODUCTION_MATERIAL_LINES : actual_input
    MANUFACTURING_BATCH_MATERIALS o|--o{ PRODUCTION_MATERIAL_LINES : planned_component
    STOCK_POSITIONS ||--o{ PRODUCTION_MATERIAL_LINES : source
    PRODUCTION_REPORTS ||--o{ PRODUCTION_OUTPUT_LINES : actual_output
    STOCK_POSITIONS ||--o{ PRODUCTION_OUTPUT_LINES : destination
    PRODUCTION_MATERIAL_LINES ||--o{ PRODUCTION_WASTAGE_LINES : loss_subset
    PRODUCTION_REPORTS ||--o{ PRODUCTION_WASTAGE_LINES : records
    PRODUCTION_OUTPUT_LINES ||--o{ PRODUCTION_COST_ALLOCATIONS : absorbs
    PRODUCTION_REPORTS ||--o{ PRODUCTION_COST_ALLOCATIONS : records
    JOURNAL_LINES ||--o{ PRODUCTION_COST_ALLOCATIONS : actual_source
```

A completion-only final report legitimately has zero lines; ordinary conversions
require inputs and outputs. Stock movement/FIFO/value/cost-link relationships reuse
the existing ledger. Posted report effects are not a second inventory ledger.

> Phase 8 implementation (2026-10-21): configurable processing routes/jobs,
> contractor dispatch/custody, partial accepted/rejected returns, loss costing,
> roll lineage and exact-rate contractor bill/AP sources are implemented.
> See [PROCESSING.md](PROCESSING.md) for actual behavior and explicit boundaries.
> Production/sales/payments/tax remain future scope; MySQL 8.4 validation is pending.

Logical relationship views for the approved Phase 1 architecture. They omit some
columns and cardinalities for readability; the detailed table specifications are
authoritative. An ERD edge means a direct FK unless described as a service
invariant in [DATABASE_SCHEMA](DATABASE_SCHEMA.md).

## Implemented Phase 5 textile catalog

Phase 5 implements the catalog relationships shown below. Collections are
labels on designs.season, not a new FK target. The existing shared `units` table
is reused. The warehouse/core inventory and minimum journal diagrams are implemented in Phase 6; the Purchasing and Processing slices are implemented in Phases 7-8; other operational-domain diagrams remain approved design only.

```mermaid
erDiagram
    ITEM_CATEGORIES o|--o{ ITEM_CATEGORIES : parent
    ITEM_CATEGORIES ||--o{ ITEMS : classifies
    UNITS ||--o{ ITEMS : base_unit
    ITEMS ||--o{ ITEM_UNIT_CONVERSIONS : alternatives
    UNITS ||--o{ ITEM_UNIT_CONVERSIONS : alternate_unit
    ITEMS ||--o| FABRIC_SPECIFICATIONS : fabric_definition
    ITEMS ||--o| PRODUCT_VARIANTS : finished_definition
    DESIGNS ||--o{ PRODUCT_VARIANTS : design
    COLORS ||--o{ PRODUCT_VARIANTS : color
    DESIGNS {
        bigint id PK
        varchar code UK
        varchar season "collection label"
    }
    ITEMS {
        bigint id PK
        varchar sku UK
        bigint category_id FK
        bigint base_unit_id FK
        varchar item_type
        boolean is_stocked
        boolean track_rolls
    }
    ITEM_UNIT_CONVERSIONS {
        bigint id PK
        bigint item_id FK
        bigint unit_id FK
        decimal factor_to_base
    }
    PRODUCT_VARIANTS {
        bigint id PK
        bigint item_id FK,UK
        bigint design_id FK
        bigint color_id FK
        varchar configuration_code
        tinyint piece_count
    }
```

The composite variant business key is (design_id,color_id,configuration_code).
An ordinary FK cannot prove that item_id is a finished whole-suit item; the
CatalogService enforces that invariant transactionally. No quantity is stored
on these masters.

## Cross-module workflow

```mermaid
flowchart LR
    Core[Core: documents and numbering]
    Identity[Identity]
    Parties[Shared Parties and Roles]
    Catalog[Catalog and Units]
    Warehouses[Warehouses and Locations]
    Inventory[Inventory and Valuation]
    Purchasing[Purchasing]
    Processing[Configurable Processing]
    Production[Production]
    Finished[Finished Goods and Packing]
    Sales[Sales]
    Accounting[Accounting]
    HR[HR and Payroll]
    Reporting[Reporting]
    Identity --> Core
    Parties --> Purchasing
    Parties --> Processing
    Parties --> Sales
    Catalog --> Purchasing
    Catalog --> Inventory
    Catalog --> Processing
    Catalog --> Production
    Catalog --> Finished
    Catalog --> Sales
    Warehouses --> Inventory
    Purchasing --> Inventory
    Inventory --> Processing
    Processing --> Inventory
    Inventory --> Production
    Production --> Inventory
    Inventory --> Finished
    Finished --> Sales
    Sales --> Inventory
    Core --> Purchasing
    Core --> Processing
    Core --> Production
    Core --> Sales
    Core --> Accounting
    Accounting --> Purchasing
    Accounting --> Processing
    Accounting --> Production
    Accounting --> Sales
    HR --> Accounting
    Purchasing --> Reporting
    Processing --> Reporting
    Production --> Reporting
    Sales --> Reporting
    Accounting --> Reporting
```

## Implemented Phase 3 setup and authorization relationships

The currency table is a PKR-only extension requested for Phase 3. Company is a
singleton, so settings have no repeated company ID. The optional `audit_events`
document FK is deferred until the document root is implemented.

```mermaid
erDiagram
    CURRENCIES ||--o{ COMPANIES : functional_currency
    USERS ||--o{ COMPANY_SETTINGS : updates
    USERS ||--o{ USER_ROLES : assigned_to
    ACCESS_ROLES ||--o{ USER_ROLES : grants
    USERS ||--o{ USER_ROLES : assigned_by
    ACCESS_ROLES ||--o{ ROLE_PERMISSIONS : includes
    PERMISSIONS ||--o{ ROLE_PERMISSIONS : granted
    USERS ||--o{ FINANCIAL_YEARS : closes
    FINANCIAL_YEARS ||--o{ ACCOUNTING_PERIODS : contains
    USERS ||--o{ ACCOUNTING_PERIODS : closes
    USERS o|--o{ AUDIT_EVENTS : acts
```

## Company identity, shared parties and catalog

The shared party identity and profiles below are implemented by Phase 4. A
profile is keyed by its `party_roles` row; vendor capabilities/rates sit beneath
the vendor role. The service validates that a role type agrees with its profile.

```mermaid
erDiagram
    PAYMENT_TERMS ||--o{ SUPPLIER_PROFILES : terms
    PAYMENT_TERMS ||--o{ VENDOR_PROFILES : terms
    PAYMENT_TERMS ||--o{ CUSTOMER_PROFILES : terms
    PARTIES ||--o{ PARTY_ADDRESSES : addresses
    PARTIES ||--o{ PARTY_CONTACTS : contacts
    PARTIES ||--o{ PARTY_ROLES : holds
    PARTY_ROLE_TYPES ||--o{ PARTY_ROLES : classifies
    PARTY_ROLES ||--o| SUPPLIER_PROFILES : supplier_profile
    PARTY_ROLES ||--o| VENDOR_PROFILES : vendor_profile
    PARTY_ROLES ||--o| CUSTOMER_PROFILES : customer_profile
    VENDOR_PROFILES ||--o{ VENDOR_SERVICES : capabilities
    PROCESSING_SERVICES ||--o{ VENDOR_SERVICES : offered_as
    UNITS ||--o{ PROCESSING_SERVICES : default_billing_unit
    VENDOR_SERVICES ||--o{ VENDOR_SERVICE_RATES : rate_history
    UNITS ||--o{ VENDOR_SERVICE_RATES : rate_unit
    USERS ||--o{ VENDOR_SERVICE_RATES : approves
    CURRENCIES ||--o{ VENDOR_SERVICE_RATES : currency
```

```mermaid
erDiagram
    PARTIES {
        bigint id PK
        varchar code UK
        varchar legal_name
        varchar party_kind
        boolean is_active
    }
    PARTY_ROLE_TYPES {
        bigint id PK
        varchar code UK
        varchar name
        boolean is_active
    }
    PARTY_ROLES {
        bigint id PK
        bigint party_id FK
        bigint party_role_type_id FK
        boolean is_active
    }
    SUPPLIER_PROFILES {
        bigint party_role_id PK, FK
        bigint payment_term_id FK
    }
    VENDOR_PROFILES {
        bigint party_role_id PK, FK
        bigint payment_term_id FK
    }
    CUSTOMER_PROFILES {
        bigint party_role_id PK, FK
        bigint payment_term_id FK
        decimal credit_limit
        boolean credit_hold
    }
    VENDOR_SERVICES {
        bigint id PK
        bigint vendor_party_role_id FK
        bigint processing_service_id FK
        smallint lead_time_days
    }
    VENDOR_SERVICE_RATES {
        bigint id PK
        bigint vendor_service_id FK
        date effective_from
        date effective_to
        bigint billing_unit_id FK
        decimal unit_rate
        bigint approved_by FK
    }
    PARTIES ||--o{ PARTY_ROLES : has
    PARTY_ROLE_TYPES ||--o{ PARTY_ROLES : classifies
    PARTY_ROLES ||--o| SUPPLIER_PROFILES : supplier
    PARTY_ROLES ||--o| VENDOR_PROFILES : vendor
    PARTY_ROLES ||--o| CUSTOMER_PROFILES : customer
    VENDOR_PROFILES ||--o{ VENDOR_SERVICES : offers
    VENDOR_SERVICES ||--o{ VENDOR_SERVICE_RATES : prices
```

```mermaid
erDiagram
    users ||--o{ user_roles : receives
    access_roles ||--o{ user_roles : grants
    access_roles ||--o{ role_permissions : contains
    permissions ||--o{ role_permissions : assigned
    parties ||--o{ party_addresses : has
    parties ||--o{ party_contacts : has
    parties ||--o{ party_bank_accounts : owns
    parties ||--o{ party_roles : may_have
    party_role_types ||--o{ party_roles : classifies
    party_roles ||--o| supplier_profiles : supplier_profile
    party_roles ||--o| vendor_profiles : vendor_profile
    party_roles ||--o| customer_profiles : customer_profile
    vendor_profiles ||--o{ vendor_services : offers
    processing_services ||--o{ vendor_services : capability
    vendor_services ||--o{ vendor_service_rates : historic_rates
    units ||--o{ items : base_unit
    item_categories ||--o{ items : classifies
    items ||--o{ item_unit_conversions : converts
    units ||--o{ item_unit_conversions : alternate_unit
    items ||--o| fabric_specifications : fabric_details
    items ||--o| product_variants : finished_variant
    designs ||--o{ product_variants : design
    colors ||--o{ product_variants : color
    documents ||--o{ document_status_events : lifecycle
    document_types ||--o{ documents : identifies
    financial_years ||--o{ document_sequences : numbers
    document_types ||--o{ document_sequences : sequence
```

Profiles refer to role rows, not independent party records. One party can have
supplier, vendor and customer roles at once. Employee identity separately reuses
`parties` without requiring a commercial role.

## Warehouses, custody, inventory and valuation

```mermaid
erDiagram
    warehouses ||--|{ warehouse_locations : contains
    vendor_profiles o|--o{ warehouse_locations : contractor_custody
    items ||--o{ stock_lots : identifies
    documents ||--o{ stock_lots : originates
    stock_lots ||--o{ fabric_rolls : may_split_into
    fabric_rolls ||--o{ fabric_roll_lineage : parent_or_child
    stock_lots ||--o{ stock_positions : held_as
    warehouse_locations ||--o{ stock_positions : location
    fabric_rolls o|--o{ stock_positions : optional_roll
    stock_positions ||--|| stock_balances : cached_balance
    stock_positions ||--o{ stock_movements : quantity_ledger
    documents ||--o{ stock_movements : posts
    stock_movements ||--o| stock_cost_layers : opens_layer
    stock_cost_layers ||--o{ inventory_layer_allocations : quantity_cost
    stock_movements ||--o{ inventory_layer_allocations : consumes_or_receives
    stock_cost_layers ||--o{ inventory_value_entries : value_ledger
    documents ||--o{ inventory_value_entries : posts_value
    inventory_layer_allocations ||--o{ inventory_cost_links : distributes_cost
    stock_cost_layers o|--o{ inventory_cost_links : surviving_cost
    ledger_accounts o|--o{ inventory_cost_links : expensed_cost
    stock_positions ||--o{ stock_reservations : reserves
    documents ||--o{ stock_reservations : commitment
```

`stock_balances` and remaining layer quantities are projections; signed movement,
allocation and value rows carry the history. A contractor location changes custody
while the company remains the owner.

## Configurable processing and contractor custody

```mermaid
erDiagram
    processing_route_versions ||--|{ processing_route_steps : ordered_steps
    processing_services ||--o{ processing_route_steps : configured_service
    items ||--o{ processing_route_steps : expected_input_output
    processing_route_versions ||--o{ processing_jobs : selected_by
    documents ||--o| processing_jobs : typed_header
    processing_jobs ||--|{ processing_job_steps : planned_execution
    processing_route_steps o|--o{ processing_job_steps : source_step
    vendor_services ||--o{ processing_job_steps : selected_capability
    vendor_service_rates o|--o{ processing_job_steps : historical_rate_source
    processing_jobs ||--o{ processing_dispatches : dispatches
    documents ||--o| processing_dispatches : typed_header
    processing_dispatches ||--|{ processing_dispatch_lines : challan_lines
    processing_job_steps ||--o{ processing_dispatch_lines : fulfills_step
    stock_positions ||--o{ processing_dispatch_lines : source_stock
    processing_dispatches ||--o{ processing_receipts : returns
    documents ||--o| processing_receipts : typed_header
    processing_receipts ||--|{ processing_receipt_lines : inspected_output
    processing_dispatch_lines ||--o{ processing_receipt_lines : closes_custody
    processing_job_steps ||--o{ processing_receipt_lines : accepts_service
    stock_positions ||--o{ processing_receipt_lines : source_or_output
    processing_receipt_lines ||--o{ purchase_invoice_processing_matches : billed_service
    purchase_invoice_processing_matches ||--o{ processing_charge_allocations : late_cost
```

Route steps are configuration, not a fixed sequence in application code. The
job snapshots accepted vendor, effective service rate and units; physical challan
and acceptance quantities remain independently traceable.

## Production, finished suits and packing

```mermaid
erDiagram
    bills_of_materials ||--|{ bill_of_material_lines : versioned_components
    product_variants ||--o{ bills_of_materials : plans
    items ||--o{ bill_of_material_lines : component
    bills_of_materials ||--o{ manufacturing_batches : selected_version
    documents ||--o| manufacturing_batches : typed_header
    manufacturing_batches ||--o{ manufacturing_batch_materials : planned_inputs
    stock_positions ||--o{ manufacturing_batch_materials : reserved_stock
    manufacturing_batches ||--o{ production_reports : actual_events
    documents ||--o| production_reports : typed_header
    production_reports ||--o{ production_material_lines : actual_consumption
    stock_positions ||--o{ production_material_lines : issued_layer
    production_reports ||--o{ production_output_lines : accepted_outputs
    stock_positions ||--o{ production_output_lines : output_lot
    production_reports ||--o{ production_wastage_lines : measured_loss
    ledger_accounts o|--o{ production_wastage_lines : abnormal_loss
    production_reports ||--o{ production_cost_allocations : actual_conversion_cost
    journal_lines o|--o{ production_cost_allocations : cost_source
    product_variants ||--o{ product_pack_specs : packing_version
    product_pack_specs ||--|{ product_pack_spec_lines : materials
    items ||--o{ product_pack_spec_lines : packaging_item
    product_pack_specs ||--o{ packing_orders : selected_by
    documents ||--o| packing_orders : typed_header
    packing_orders ||--|{ packing_material_lines : consumes
    packing_orders ||--|{ packing_output_lines : emits
    packing_output_lines ||--o{ packages : identifies_container
    packages ||--o{ package_contents : provenance
    packing_material_lines ||--o{ package_contents : input_source
```

Material lines record actual input; output lines record good suit/package output.
The same inventory quantity and cost ledgers track components and finished goods.
Packages are container/provenance records and cannot duplicate stock quantity.

## Accounting and commercial relationships

Purchasing, Sales, journals, open-item settlements, bank reconciliation and
payroll have separate detailed ERDs in
[database/FINANCE_ERD](database/FINANCE_ERD.md). Their central path is:

```mermaid
erDiagram
    financial_years ||--|{ accounting_periods : contains
    accounting_periods ||--o{ documents : dates
    documents ||--o| journal_entries : financial_effect
    journal_entries ||--|{ journal_lines : balances
    ledger_accounts ||--o{ journal_lines : classifies
    parties o|--o{ journal_lines : subledger_dimension
    journal_lines ||--o| party_open_items : creates_control_item
    settlement_batches ||--|{ settlement_allocations : groups
    party_open_items ||--o{ settlement_allocations : allocates
    parties ||--o{ payments : pays
    parties ||--o{ receipts : pays
    purchase_order_lines ||--o{ goods_receipt_lines : fulfilled_receipts
    goods_receipt_lines ||--o{ purchase_invoice_receipt_matches : billed_receipt
    sales_dispatch_lines ||--o{ sales_invoice_dispatch_matches : billed_dispatch
    sales_orders ||--o{ sales_dispatches : fulfills
    parties ||--o| employees : employee_identity
    payroll_runs ||--|{ payroll_run_employees : payslips
    payroll_run_employees ||--|{ payroll_run_lines : snapshots
```

The full 55-table finance inventory and all diagram details are in the linked
finance ERD and specification. Cross-row balancing and party consistency remain
transaction invariants, not edges inferable from a simplified diagram.

## Phase 6 implemented dependency slice

```mermaid
erDiagram
    documents ||--o| inventory_adjustments : extends
    inventory_adjustments ||--|{ inventory_adjustment_lines : contains
    documents ||--o| inventory_transfers : extends
    inventory_transfers ||--|{ inventory_transfer_lines : contains
    documents ||--o{ stock_movements : posts
    documents ||--o| journal_entries : balances
    journal_entries ||--|{ journal_lines : contains
    warehouses ||--o{ warehouse_locations : groups
    vendor_profiles o|--o{ warehouse_locations : custody
    items ||--o{ stock_lots : identifies
    stock_lots ||--o{ fabric_rolls : tracks
    stock_lots ||--o{ stock_positions : locates
    warehouse_locations ||--o{ stock_positions : holds
    fabric_rolls o|--o{ stock_positions : identifies
    stock_positions ||--o{ stock_movements : history
    stock_positions ||--o{ stock_reservations : commitments
    stock_positions ||--o| stock_balances : projects
    stock_positions ||--o{ stock_cost_layers : values
    stock_movements ||--o{ inventory_layer_allocations : allocates
    stock_cost_layers ||--o{ inventory_layer_allocations : consumes
    stock_cost_layers ||--o{ inventory_value_entries : history
    inventory_layer_allocations ||--o{ inventory_cost_links : traces
```

Reservations commit draft transfers in Phase 6. Employee dimensions are NULL-only
until a later employee FK migration; payroll/party open items are not implemented.

## Phase 7 implemented purchasing relationships

```mermaid
erDiagram
    supplier_profiles ||--o{ purchase_orders : commits
    documents ||--o| purchase_orders : extends
    purchase_orders ||--|{ purchase_order_lines : contains
    supplier_profiles ||--o{ goods_receipts : delivers
    documents ||--o| goods_receipts : extends
    goods_receipts ||--|{ goods_receipt_lines : accepts
    purchase_order_lines o|--o{ goods_receipt_lines : fulfils
    stock_positions ||--o{ goods_receipt_lines : receives
    stock_movements o|--o| goods_receipt_lines : posts
    party_roles ||--o{ purchase_invoices : bills
    documents ||--o| purchase_invoices : extends
    purchase_invoices ||--|{ purchase_invoice_lines : contains
    purchase_invoice_lines ||--o{ purchase_invoice_receipt_matches : matches
    goods_receipt_lines ||--o{ purchase_invoice_receipt_matches : billed
    supplier_profiles ||--o{ purchase_returns : receives
    documents ||--o| purchase_returns : extends
    purchase_returns ||--|{ purchase_return_lines : contains
    goods_receipt_lines ||--o{ purchase_return_lines : caps
    journal_lines ||--o| party_open_items : originates
```

Future tax/processing dimension FKs are not shown: values are constrained NULL
until those prerequisite tables are authorized. Supplier identity still belongs
to parties; profiles are role-specific extensions, not duplicated masters.

## Implemented Phase 8 processing slice

```mermaid
erDiagram
    processing_route_versions ||--|{ processing_route_steps : defines
    processing_services ||--o{ processing_route_steps : configures
    processing_route_versions ||--o{ processing_jobs : freezes
    documents ||--o| processing_jobs : approves
    processing_jobs ||--|{ processing_job_steps : snapshots
    processing_route_steps ||--o{ processing_job_steps : instantiates
    vendor_services ||--o{ processing_job_steps : assigns
    vendor_service_rates ||--o{ processing_job_steps : sources
    vendor_profiles ||--o{ processing_dispatches : receives
    processing_jobs ||--o{ processing_dispatches : issues
    processing_dispatches ||--|{ processing_dispatch_lines : contains
    processing_job_steps ||--o{ processing_dispatch_lines : executes
    stock_cost_layers ||--o{ processing_dispatch_lines : selects
    stock_movements ||--o| processing_dispatch_lines : opensCustody
    processing_jobs ||--o{ processing_receipts : reconciles
    vendor_profiles ||--o{ processing_receipts : returns
    processing_receipts ||--|{ processing_receipt_lines : measures
    processing_dispatch_lines ||--o{ processing_receipt_lines : closes
    stock_positions ||--o{ processing_receipt_lines : returnsTo
    fabric_rolls ||--o{ fabric_roll_lineage : parent
    fabric_rolls ||--o{ fabric_roll_lineage : child
    purchase_invoice_lines ||--o{ purchase_invoice_processing_matches : matches
    processing_receipt_lines ||--o{ purchase_invoice_processing_matches : bills
```

Each dispatch line's contractor movement opens one cost layer. Receipt source
allocations consume that exact layer (service invariant), preserving job custody
when several jobs share a stock position. Document/FIFO/journal relationships
shown in earlier diagrams are reused. processing_charge_allocations remains a
future late-cost integration table, not part of this executable slice.
