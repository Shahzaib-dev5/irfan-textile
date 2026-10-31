# Core, Identity, Parties and Catalog table specifications

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

Status: approved Phase 1 architecture. Phase 2-6 implemented slices are indexed
in DATABASE_SCHEMA.md; the rest remains design-only. Phase 5 catalog details and
validation boundaries are in ../TEXTILE_MASTER_DATA.md.
Read the common SQL conventions in [DATABASE_SCHEMA](../DATABASE_SCHEMA.md).
Every field below is additive to its declared base. `E` means entity base,
`M` means entity base plus `is_active`, `L` means append-only entity base.
`NN` means NOT NULL, `N` means NULL; `—` means no SQL default.
Every FK uses matching signedness and an explicit index; see the common FK policy.

## Company and authentication

### `companies`

Singleton legal entity. No implicit tenant filtering or repeated company IDs.
Base: timestamps only; **no auto-increment entity base**.

| Column | MySQL type | Null | Default | Keys / meaning |
|---|---|---|---|---|
| id | TINYINT UNSIGNED | NN | 1 | PK; CHECK id = 1 |
| legal_name | VARCHAR(200) | NN | — | Registered name |
| trading_name | VARCHAR(200) | N | NULL | Trading name |
| ntn | VARCHAR(30) | N | NULL | Tax identifier, not numerical arithmetic |
| sales_tax_registration | VARCHAR(40) | N | NULL | Registration identifier |
| address_text | TEXT | N | NULL | Legal address |
| phone | VARCHAR(40) | N | NULL | Contact |
| email | VARCHAR(254) | N | NULL | Contact |
| functional_currency | CHAR(3) | NN | 'PKR' | CHECK = 'PKR' |
| timezone | VARCHAR(64) | NN | 'Asia/Karachi' | Validated IANA zone |
| fiscal_year_start_month | TINYINT UNSIGNED | NN | 7 | CHECK BETWEEN 1 AND 12; proposed, configurable |

PK is also the mutex for fiscal/calendar changes. No other indexes needed.

### `company_settings`

Base: E. Nonfinancial presentation/operational settings only; never substitute
JSON for stock, tax, rate or journal relationships.

| Column | MySQL type | Null | Default | Keys / meaning |
|---|---|---|---|---|
| setting_key | VARCHAR(100) | NN | — | UQ |
| value_json | JSON | NN | — | Validated against known key definition |
| updated_by | BIGINT UNSIGNED | NN | — | FK users.id |

No credentials/secrets in this table. No additional indexes beyond UQ/FK.

### `users`

Base: M. Local session authentication; employees are optional linked identities.

| Column | MySQL type | Null | Default | Keys / meaning |
|---|---|---|---|---|
| name | VARCHAR(150) | NN | — | Display name |
| email | VARCHAR(254) | NN | — | UQ; canonicalized lowercase before insert |
| email_verified_at | DATETIME(6) | N | NULL | UTC |
| password | VARCHAR(255) | NN | — | Password hash only |
| remember_token | VARCHAR(100) | N | NULL | Framework token |
| last_login_at | DATETIME(6) | N | NULL | UTC |

Index: `(is_active, name)`. Deactivate users; retain references in audit/history.

### `access_roles`

Base: M.

| Column | MySQL type | Null | Default | Keys / meaning |
|---|---|---|---|---|
| code | VARCHAR(50) | NN | — | UQ stable permission role code |
| name | VARCHAR(100) | NN | — | User-visible name |
| description | VARCHAR(500) | N | NULL | Purpose |

These are authorization roles, distinct from business `party_roles`.

### `permissions`

Base: E.

| Column | MySQL type | Null | Default | Keys / meaning |
|---|---|---|---|---|
| code | VARCHAR(100) | NN | — | UQ; e.g. processing.jobs.post |
| module_code | VARCHAR(40) | NN | — | Index |
| description | VARCHAR(255) | NN | — | Permission meaning |

### `user_roles`

Base: E.

| Column | MySQL type | Null | Default | Keys / meaning |
|---|---|---|---|---|
| user_id | BIGINT UNSIGNED | NN | — | FK users.id |
| access_role_id | BIGINT UNSIGNED | NN | — | FK access_roles.id |
| assigned_by | BIGINT UNSIGNED | NN | — | FK users.id |

UQ `(user_id, access_role_id)`. Role changes are audited.

### `role_permissions`

Base: E.

| Column | MySQL type | Null | Default | Keys / meaning |
|---|---|---|---|---|
| access_role_id | BIGINT UNSIGNED | NN | — | FK access_roles.id |
| permission_id | BIGINT UNSIGNED | NN | — | FK permissions.id |

UQ `(access_role_id, permission_id)`. Mutations require identity administration.

### `sessions`

Framework infrastructure; **no common base**.

| Column | MySQL type | Null | Default | Keys / meaning |
|---|---|---|---|---|
| id | VARCHAR(255) | NN | — | PK; cryptographically generated ID |
| user_id | BIGINT UNSIGNED | N | NULL | FK users.id; authenticated sessions |
| ip_address | VARCHAR(45) | N | NULL | IPv4/IPv6 |
| user_agent | TEXT | N | NULL | Client metadata |
| payload | LONGTEXT | NN | — | Framework session payload |
| last_activity | INT UNSIGNED | NN | — | Unix epoch seconds; index |

Expired sessions may be purged; this is not business history.

### `password_reset_tokens`

Framework infrastructure; **no common base**.

| Column | MySQL type | Null | Default | Keys / meaning |
|---|---|---|---|---|
| email | VARCHAR(254) | NN | — | PK; same normalization/collation as users.email |
| token | VARCHAR(255) | NN | — | Hash |
| created_at | DATETIME(6) | N | NULL | Framework populated |

Email is not a business FK; unknown-address reset requests must not reveal users.
Index `(created_at)` for expiry cleanup.

## Shared party identity and commercial capabilities

### `payment_terms`

Base: M.

| Column | MySQL type | Null | Default | Keys / meaning |
|---|---|---|---|---|
| code | VARCHAR(30) | NN | — | UQ |
| name | VARCHAR(100) | NN | — | Display |
| due_days | SMALLINT UNSIGNED | NN | 0 | Calendar days from invoice |

Due dates are snapshotted on posted invoices. Early-payment discounts and
installment schedules are not implied by this simple terms master.

### `parties`

Base: M. The sole external/legal-person identity master.

| Column | MySQL type | Null | Default | Keys / meaning |
|---|---|---|---|---|
| code | VARCHAR(40) | NN | — | UQ |
| legal_name | VARCHAR(200) | NN | — | Index |
| trading_name | VARCHAR(200) | N | NULL | Display |
| party_kind | VARCHAR(20) | NN | 'organization' | CHECK IN ('organization','individual') |
| ntn | VARCHAR(30) | N | NULL | Nullable UQ |
| sales_tax_registration | VARCHAR(40) | N | NULL | Nullable UQ |
| cnic | VARCHAR(25) | N | NULL | Nullable UQ; restricted access |
| email | VARCHAR(254) | N | NULL | Shared contact allowed; not unique |
| phone | VARCHAR(40) | N | NULL | Shared contact allowed; not unique |
| notes | TEXT | N | NULL | Nonsecret operational notes |

Blank identifiers normalize to NULL. Tax registrations are assumed unique to one
legal entity in this single-company database; branches are addresses, not new
parties. Matching names/phones do not automatically merge parties. Employee
records may reuse `parties` without inventing a supplier/vendor/customer role.

### `party_addresses`

Base: M.

| Column | MySQL type | Null | Default | Keys / meaning |
|---|---|---|---|---|
| party_id | BIGINT UNSIGNED | NN | — | FK parties.id |
| label | VARCHAR(60) | NN | — | UQ with party_id |
| address_type | VARCHAR(20) | NN | 'business' | CHECK business/billing/shipping |
| address_line_1 | VARCHAR(255) | NN | — | Address |
| address_line_2 | VARCHAR(255) | N | NULL | Address |
| city | VARCHAR(100) | NN | — | City |
| province | VARCHAR(100) | N | NULL | Province |
| postal_code | VARCHAR(20) | N | NULL | String |
| country_code | CHAR(2) | NN | 'PK' | ISO alpha-2 validated by service |

UQ `(party_id,label)`; index `(party_id,address_type,is_active)`.
Invoices/dispatches snapshot the selected address, so later edits do not rewrite
history. No ambiguous multiple-default flag.

### `party_contacts`

Base: M.

| Column | MySQL type | Null | Default | Keys / meaning |
|---|---|---|---|---|
| party_id | BIGINT UNSIGNED | NN | — | FK parties.id |
| name | VARCHAR(150) | NN | — | Contact |
| designation | VARCHAR(100) | N | NULL | Contact role |
| email | VARCHAR(254) | N | NULL | Contact |
| phone | VARCHAR(40) | N | NULL | Contact |

Index `(party_id,name)`. No artificial person-name uniqueness.

### `party_bank_accounts`

Base: M.

| Column | MySQL type | Null | Default | Keys / meaning |
|---|---|---|---|---|
| party_id | BIGINT UNSIGNED | NN | — | FK parties.id |
| bank_name | VARCHAR(150) | NN | — | Display |
| account_title | VARCHAR(200) | NN | — | Beneficiary |
| account_number | VARCHAR(64) | NN | — | String, restricted access |
| iban | VARCHAR(34) | N | NULL | Canonical uppercase, optional |
| branch_code | VARCHAR(30) | N | NULL | Display |

UQ `(party_id,bank_name,account_number)`; index `(party_id,is_active)`.
Do not treat these as company cash/bank ledger accounts.

### `party_role_types`

Base: M.

| Column | MySQL type | Null | Default | Keys / meaning |
|---|---|---|---|---|
| code | VARCHAR(20) | NN | — | UQ; CHECK IN ('supplier','vendor','customer') |
| name | VARCHAR(80) | NN | — | Display |

Seed by stable code, never assume numerical IDs. Approved roles only.

### `party_roles`

Base: M.

| Column | MySQL type | Null | Default | Keys / meaning |
|---|---|---|---|---|
| party_id | BIGINT UNSIGNED | NN | — | FK parties.id |
| party_role_type_id | BIGINT UNSIGNED | NN | — | FK party_role_types.id |

UQ `(party_id,party_role_type_id)`. Add UQ `(id,party_id)` for optional compound
references that need both identity and role. A party can hold all three roles.
Role creation/deactivation and profile creation are one transaction locking the
party row. The service verifies the profile's role code; an ordinary FK alone
cannot enforce that a role is specifically a vendor.

### `supplier_profiles`

Base: timestamps only; **no id**.

| Column | MySQL type | Null | Default | Keys / meaning |
|---|---|---|---|---|
| party_role_id | BIGINT UNSIGNED | NN | — | PK/FK party_roles.id |
| payment_term_id | BIGINT UNSIGNED | NN | — | FK payment_terms.id |
| purchase_notes | VARCHAR(1000) | N | NULL | Commercial instructions |

Exactly one profile for each supplier role, enforced by role creation service
plus PK. Supplier administration belongs to Purchasing.

### `vendor_profiles`

Base: timestamps only; **no id**.

| Column | MySQL type | Null | Default | Keys / meaning |
|---|---|---|---|---|
| party_role_id | BIGINT UNSIGNED | NN | — | PK/FK party_roles.id |
| payment_term_id | BIGINT UNSIGNED | NN | — | FK payment_terms.id |
| contractor_notes | VARCHAR(1000) | N | NULL | Handling instructions |

Role code must be vendor. Vendor administration belongs to Processing.

### `customer_profiles`

Base: timestamps only; **no id**.

| Column | MySQL type | Null | Default | Keys / meaning |
|---|---|---|---|---|
| party_role_id | BIGINT UNSIGNED | NN | — | PK/FK party_roles.id |
| payment_term_id | BIGINT UNSIGNED | NN | — | FK payment_terms.id |
| credit_limit | DECIMAL(20,4) | NN | 0 | CHECK >= 0; zero means no credit |
| credit_hold | BOOLEAN | NN | 0 | CHECK IN (0,1) |
| sales_notes | VARCHAR(1000) | N | NULL | Commercial instructions |

Role code must be customer. Customer administration belongs to Sales.
Credit exposure must include outstanding AR and approved unbilled dispatches,
net of allocated advances; lock customer profile for credit decisions.

### `processing_services`

Base: M. Dyeing, printing, embroidery, washing, finishing, etc. are data.

| Column | MySQL type | Null | Default | Keys / meaning |
|---|---|---|---|---|
| code | VARCHAR(40) | NN | — | UQ |
| name | VARCHAR(100) | NN | — | Display |
| billing_unit_id | BIGINT UNSIGNED | NN | — | FK units.id |
| description | VARCHAR(500) | N | NULL | Capability definition |

There is no fixed global step order. Dyeing normally has meter billing unit.
Freeze billing_unit_id once used; a change of commercial measurement basis needs
a new service code and explicitly selected future job steps.

### `vendor_services`

Base: M.

| Column | MySQL type | Null | Default | Keys / meaning |
|---|---|---|---|---|
| vendor_party_role_id | BIGINT UNSIGNED | NN | — | FK vendor_profiles.party_role_id |
| processing_service_id | BIGINT UNSIGNED | NN | — | FK processing_services.id |
| lead_time_days | SMALLINT UNSIGNED | N | NULL | Planning hint |

UQ `(vendor_party_role_id,processing_service_id)`. The row is the mutex for its
effective rate intervals. Deactivation blocks new jobs, not existing returns.

### `vendor_service_rates`

Base: E. Published contract history; referenced monetary fields cannot be rewritten.

| Column | MySQL type | Null | Default | Keys / meaning |
|---|---|---|---|---|
| vendor_service_id | BIGINT UNSIGNED | NN | — | FK vendor_services.id |
| effective_from | DATE | NN | — | Inclusive |
| effective_to | DATE | N | NULL | Exclusive; NULL infinity |
| billing_unit_id | BIGINT UNSIGNED | NN | — | FK units.id |
| unit_rate | DECIMAL(20,8) | NN | — | CHECK >= 0; zero requires reason |
| currency_code | CHAR(3) | NN | 'PKR' | CHECK = 'PKR' |
| contract_reference | VARCHAR(100) | N | NULL | Agreement reference |
| rate_reason | VARCHAR(500) | NN | — | Approval/change basis |
| approved_by | BIGINT UNSIGNED | NN | — | FK users.id |

UQ `(vendor_service_id,effective_from)`; index
`(vendor_service_id,effective_from,effective_to)`.
CHECK `effective_to IS NULL OR effective_to > effective_from`.
Lock `vendor_services` before checking for any overlap, including open-ended
intervals. The billing unit must match the service's defined unit. New commercial
basis is a new service capability, not simultaneous overlapping rates. Contract
variation by fabric/design is explicitly out of this rate key; an approved
job-specific quoted rate may override with recorded reason and authorizer.
Jobs snapshot rate, unit and source rate ID; later master changes never reprice
posted work.
To introduce a successor after an open-ended interval, the authorized rate
maintenance action may close only effective_to prospectively under the same
vendor_services lock. Audit old/new bounds, preserve start/unit/rate, reject any
boundary that invalidates an existing job's rate-selection date, and insert the
successor in that transaction. A retroactive correction is a documented
job/cost adjustment, not silent contract-history replacement.

## Units, fabric, materials, designs and finished variants

### `units`

Base: M.

| Column | MySQL type | Null | Default | Keys / meaning |
|---|---|---|---|---|
| code | VARCHAR(20) | NN | — | UQ; M, KG, PCS, SUIT, etc. |
| name | VARCHAR(60) | NN | — | Display |
| dimension | VARCHAR(20) | NN | — | CHECK length/mass/count/area/service |
| decimal_places | TINYINT UNSIGNED | NN | 6 | CHECK <= 6 |

Count unit precision can be zero. Suit is a count of a complete defined product,
not a universal meter conversion. Multipiece fabric consumption is BOM-specific.

### `item_categories`

Base: M.

| Column | MySQL type | Null | Default | Keys / meaning |
|---|---|---|---|---|
| parent_id | BIGINT UNSIGNED | N | NULL | FK item_categories.id |
| code | VARCHAR(40) | NN | — | UQ |
| name | VARCHAR(100) | NN | — | Display |

Service rejects cycles under category-tree mutex (company row). No CHECK using
auto-increment id. Index `(parent_id,is_active)`.

### `items`

Base: M. One SKU master for stock and nonstock commercial/service lines.

| Column | MySQL type | Null | Default | Keys / meaning |
|---|---|---|---|---|
| category_id | BIGINT UNSIGNED | NN | — | FK item_categories.id |
| sku | VARCHAR(60) | NN | — | UQ |
| name | VARCHAR(200) | NN | — | Display |
| item_type | VARCHAR(30) | NN | — | CHECK grey_fabric/processed_fabric/trim/packaging/finished/scrap/service |
| base_unit_id | BIGINT UNSIGNED | NN | — | FK units.id |
| is_stocked | BOOLEAN | NN | 1 | CHECK IN (0,1) |
| track_rolls | BOOLEAN | NN | 0 | CHECK IN (0,1) |
| description | TEXT | N | NULL | Description |

Index `(category_id,item_type,is_active)`. CHECK
`item_type <> 'service' OR (is_stocked = 0 AND track_rolls = 0)`.
Base unit and roll-tracking policy become immutable once stock exists. Item type
and attributes must identify the stock state; changed processed identity becomes
new item/lot, preserving input-output traceability.

### `item_unit_conversions`

Base: E.

| Column | MySQL type | Null | Default | Keys / meaning |
|---|---|---|---|---|
| item_id | BIGINT UNSIGNED | NN | — | FK items.id |
| unit_id | BIGINT UNSIGNED | NN | — | FK units.id; alternative input unit |
| factor_to_base | DECIMAL(20,10) | NN | — | CHECK > 0 |

UQ `(item_id,unit_id)`. Canonical quantity = entered quantity × snapshot factor.
Base unit factor is implicitly 1. No conversions between incompatible dimensions,
or between fabric meters and complete suits. Roll is an identity/handling unit
with measured length, not a constant conversion.

### `fabric_specifications`

Base: timestamps only; **no id**.

| Column | MySQL type | Null | Default | Keys / meaning |
|---|---|---|---|---|
| item_id | BIGINT UNSIGNED | NN | — | PK/FK items.id |
| width_mm | DECIMAL(12,3) | N | NULL | CHECK NULL OR > 0 |
| gsm | DECIMAL(12,3) | N | NULL | CHECK NULL OR > 0 |
| composition | VARCHAR(200) | NN | — | Fiber composition specification |
| weave | VARCHAR(100) | N | NULL | Weave specification |

One row for a fabric SKU. Trim and packaging SKUs use item descriptions and
categories; no speculative EAV attributes or duplicate material master.

### `designs`

Base: M.

| Column | MySQL type | Null | Default | Keys / meaning |
|---|---|---|---|---|
| code | VARCHAR(50) | NN | — | UQ |
| name | VARCHAR(150) | NN | — | Design name |
| season | VARCHAR(60) | N | NULL | Collection descriptor |
| description | TEXT | N | NULL | Design notes |

Index `(season,is_active)`.

### `colors`

Base: M.

| Column | MySQL type | Null | Default | Keys / meaning |
|---|---|---|---|---|
| code | VARCHAR(40) | NN | — | UQ |
| name | VARCHAR(100) | NN | — | Display |
| hex_code | CHAR(7) | N | NULL | Optional presentation swatch, validated hex |

Shade/dye batch differences belong to stock lots even when color is the same.

### `product_variants`

Base: M.

| Column | MySQL type | Null | Default | Keys / meaning |
|---|---|---|---|---|
| item_id | BIGINT UNSIGNED | NN | — | FK items.id; UQ |
| design_id | BIGINT UNSIGNED | NN | — | FK designs.id |
| color_id | BIGINT UNSIGNED | NN | — | FK colors.id |
| configuration_code | VARCHAR(40) | NN | — | e.g. THREE_PIECE |
| piece_count | TINYINT UNSIGNED | NN | — | CHECK > 0 |
| description | VARCHAR(500) | N | NULL | Product configuration |

UQ `(design_id,color_id,configuration_code)`. The item must be a finished
stock item measured in suits; count precision zero. Shirt, trouser and dupatta
requirements are BOM lines with component labels, not hardcoded columns.

## Tax and posting configuration

### `tax_codes`

Base: M.

| Column | MySQL type | Null | Default | Keys / meaning |
|---|---|---|---|---|
| code | VARCHAR(30) | NN | — | UQ |
| name | VARCHAR(100) | NN | — | Display |
| tax_kind | VARCHAR(20) | NN | — | CHECK sales_tax/withholding/other |
| recoverable | BOOLEAN | NN | 0 | CHECK IN (0,1) |
| ledger_account_id | BIGINT UNSIGNED | NN | — | FK ledger_accounts.id |

Jurisdiction, registration eligibility and legal applicability are configured
only after local professional review. No legal rates are seeded by this design.

### `tax_rates`

Base: E.

| Column | MySQL type | Null | Default | Keys / meaning |
|---|---|---|---|---|
| tax_code_id | BIGINT UNSIGNED | NN | — | FK tax_codes.id |
| effective_from | DATE | NN | — | Inclusive |
| effective_to | DATE | N | NULL | Exclusive |
| rate_percent | DECIMAL(9,6) | NN | — | CHECK BETWEEN 0 AND 100 |
| approved_by | BIGINT UNSIGNED | NN | — | FK users.id |
| legal_reference | VARCHAR(255) | N | NULL | Supporting reference |

UQ `(tax_code_id,effective_from)`, index `(tax_code_id,effective_from,effective_to)`.
CHECK end NULL or end > start. Serialize overlaps on `tax_codes` parent.
Posted transaction taxes snapshot calculation basis, rate, amount and account.
An open-ended rate may be prospectively closed with an audit event under that
lock, preserving its rate and all existing transaction date attributions.

### `posting_accounts`

Base: E.

| Column | MySQL type | Null | Default | Keys / meaning |
|---|---|---|---|---|
| purpose_code | VARCHAR(60) | NN | — | UQ |
| ledger_account_id | BIGINT UNSIGNED | NN | — | FK ledger_accounts.id |
| description | VARCHAR(255) | NN | — | Configuration purpose |

Required purposes: raw_inventory, processing_inventory, production_wip,
finished_inventory, packaging_inventory, scrap_inventory, goods_in_transit,
grni, processing_accrual, ap_control, ar_control, unbilled_receivable, revenue, payroll_payable,
payroll_expense, cogs, abnormal_waste, inventory_variance, cost_variance,
inventory_write_down, rounding, opening_equity, retained_earnings.
Tax accounts are on tax codes; bank control accounts are on bank_accounts.
Service verifies account type, allowed control role, active/postable state.
Posted journals retain actual accounts; mapping edits affect only future posts.
`unbilled_receivable` must be an asset account with `control_type='none'`; party
dimensions remain on dispatch/invoice journal lines and matching is through
`sales_invoice_dispatch_matches`. This avoids creating a separately settled AR
open item before a commercial invoice exists.

## Document lifecycle, numbering and audit

### `document_types`

Base: M.

| Column | MySQL type | Null | Default | Keys / meaning |
|---|---|---|---|---|
| code | VARCHAR(40) | NN | — | UQ; stable registered subtype |
| name | VARCHAR(100) | NN | — | Display |
| module_code | VARCHAR(40) | NN | — | Owning module |
| prefix | VARCHAR(12) | NN | — | UQ; immutable once used |
| numbering_width | TINYINT UNSIGNED | NN | 6 | CHECK BETWEEN 4 AND 12 |
| requires_open_period | BOOLEAN | NN | 1 | CHECK IN (0,1) |

Kinds are registered in the future application, not arbitrary user-defined SQL
subtypes. Prefix codes cannot change posted document numbers. Processing service
configuration remains independent of document kind registration.

### `document_sequences`

Base: E.

| Column | MySQL type | Null | Default | Keys / meaning |
|---|---|---|---|---|
| document_type_id | BIGINT UNSIGNED | NN | — | FK document_types.id |
| financial_year_id | BIGINT UNSIGNED | NN | — | FK financial_years.id |
| next_value | BIGINT UNSIGNED | NN | 1 | CHECK > 0 |

UQ `(document_type_id,financial_year_id)`. Lock row FOR UPDATE before allocating
number; precreate in financial-year setup. Increment and posting commit together.
Never use MAX(number)+1. Never recycle posted/reversed numbers.

### `documents`

Base: E. Universal document identity, state and provenance; typed headers are
one-to-one PK/FK extensions. Financial journal is an optional sidecar for each
posting document, including a manual journal document.

| Column | MySQL type | Null | Default | Keys / meaning |
|---|---|---|---|---|
| document_type_id | BIGINT UNSIGNED | NN | — | FK document_types.id |
| number | VARCHAR(64) | N | NULL | UQ; assigned at posting only |
| document_date | DATE | NN | — | Local business date |
| financial_year_id | BIGINT UNSIGNED | NN | — | FK financial_years.id |
| accounting_period_id | BIGINT UNSIGNED | NN | — | Composite FK with year to accounting_periods(id,financial_year_id) |
| status | VARCHAR(12) | NN | 'draft' | CHECK draft/posted/reversed/cancelled |
| idempotency_key | CHAR(36) | NN | — | UQ; create-request UUID |
| posting_key | CHAR(36) | N | NULL | UQ; successful post-request UUID |
| payload_hash | BINARY(32) | N | NULL | Canonical posting payload SHA-256 |
| lock_version | INT UNSIGNED | NN | 0 | Optimistic draft edit version |
| created_by | BIGINT UNSIGNED | NN | — | FK users.id |
| posted_by | BIGINT UNSIGNED | N | NULL | FK users.id |
| posted_at | DATETIME(6) | N | NULL | UTC |
| reversal_of_document_id | BIGINT UNSIGNED | N | NULL | FK documents.id; nullable UQ |
| reversed_by | BIGINT UNSIGNED | N | NULL | FK users.id |
| reversed_at | DATETIME(6) | N | NULL | UTC |
| reason | VARCHAR(1000) | N | NULL | Required for cancellation/reversal/adjustment |
| external_reference | VARCHAR(100) | N | NULL | Source reference |

Indexes `(document_type_id,document_date,id)`, `(status,document_date,id)`,
`(accounting_period_id,financial_year_id)` plus all remaining FK indexes.
UQ `(id,document_type_id)` is unnecessary; subtype-kind consistency is a posting
service invariant, not claimed to be guaranteed by the header FK.

CHECK:

- Draft/cancelled have number, posting_key, payload_hash, posted_by and posted_at
  all NULL; posted/reversed have all five NOT NULL.
- Status reversed has reversed_by/reversed_at NOT NULL; every other status has
  both NULL.
- Cancelled/reversed or reversal_of_document_id non-NULL requires reason NOT
  NULL and nonblank (CHAR_LENGTH(TRIM(reason)) > 0).

Self-reference and reversal chains are rejected by service (cannot CHECK the
auto-increment PK). Only one complete compensation per original document; partial
physical returns and credit notes are separate linked transactions, not full
document reversal. Reversal records keep their own typed header, positive
business input quantities, and explicitly invert the recorded posting effects.
They are not ordinary invoices with negative quantities.

### `document_status_events`

Base: L.

| Column | MySQL type | Null | Default | Keys / meaning |
|---|---|---|---|---|
| document_id | BIGINT UNSIGNED | NN | — | FK documents.id |
| event_no | INT UNSIGNED | NN | — | Monotonic per document |
| from_status | VARCHAR(12) | N | NULL | NULL on creation |
| to_status | VARCHAR(12) | NN | — | CHECK draft/posted/reversed/cancelled |
| actor_id | BIGINT UNSIGNED | NN | — | FK users.id |
| reason | VARCHAR(1000) | N | NULL | Transition justification |
| request_key | CHAR(36) | NN | — | Correlation, not globally unique |

UQ `(document_id,event_no)`; index `(actor_id,created_at)`. from_status CHECK
NULL or permitted lifecycle status. Append in same transaction as transition.

### `audit_events`

Base: L.

| Column | MySQL type | Null | Default | Keys / meaning |
|---|---|---|---|---|
| actor_id | BIGINT UNSIGNED | N | NULL | FK users.id; NULL system actor |
| document_id | BIGINT UNSIGNED | N | NULL | FK documents.id if applicable |
| entity_table | VARCHAR(64) | NN | — | Allowlisted audit subject label |
| entity_key | VARCHAR(100) | NN | — | Serialized key, deliberately not operational FK |
| action | VARCHAR(40) | NN | — | create/change/post/reverse/access/etc. |
| before_json | JSON | N | NULL | Redacted change snapshot |
| after_json | JSON | N | NULL | Redacted change snapshot |
| request_key | CHAR(36) | NN | — | Correlation |
| ip_address | VARCHAR(45) | N | NULL | Optional origin |

Indexes `(entity_table,entity_key,created_at)`, `(document_id,created_at)`,
`(actor_id,created_at)`, `(request_key)`. Audit subject labels are the only
deliberate polymorphic references: they do not drive postings, stock or finances.
No password/token/session payload/bank secret snapshots. Audit is append-only;
retention and authorized export are operational policy.

### `document_attachments`

Base: L.

| Column | MySQL type | Null | Default | Keys / meaning |
|---|---|---|---|---|
| document_id | BIGINT UNSIGNED | NN | — | FK documents.id |
| uploaded_by | BIGINT UNSIGNED | NN | — | FK users.id |
| storage_disk | VARCHAR(40) | NN | — | Private storage |
| storage_path | VARCHAR(500) | NN | — | Opaque immutable path |
| original_name | VARCHAR(255) | NN | — | Sanitized filename |
| mime_type | VARCHAR(120) | NN | — | Validated type |
| size_bytes | BIGINT UNSIGNED | NN | — | Byte length |
| sha256 | BINARY(32) | NN | — | Integrity hash |

UQ `(storage_disk,storage_path)`; index `(document_id,created_at)`.
Replacing evidence creates a new attachment; posted evidence is never silently
overwritten. Objects remain private and access-controlled.
