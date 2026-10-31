# Textile ERP working instructions

User additionally authorized the Physical Suit Stock simplification and customer
ledger follow-up. Reuse Inventory openings for evidenced initial stock and atomic
Production conversions for new suits; no independent editable stock or party
balance. Keep advanced manufacturing accessible. See [docs/PHYSICAL_SUIT_STOCK.md](docs/PHYSICAL_SUIT_STOCK.md).

## Authority and current phase

Follow the user's approved master instructions and subsequent explicit phase
requests. The current request authorizes **Phase 12: Accounting and Finance** after
Phases 1-11. Read docs/PROJECT_STATE.md and docs/ACCOUNTING_FINANCE.md. Reuse existing
source journals and immutable party open items; implement accounts, bank/cash,
payments/receipts, settlements, reversals and statements through safe additive
migrations. Preserve direct Confirm and the separate Wholesale role. No independent
editable party balances, duplicated revenue/cost postings or stock replay.
Stop after Phase 12. Tax, payroll and statutory filing remain outside scope.

The user subsequently authorized guided UX follow-ups for Processing, Purchasing and Textile master data.
Organize existing workflows into sidebar submenus and separate screens, with
source/prerequisite guidance and next actions. Preserve Purchasing draft editing,
approval, partial receipts, returns and exact-cost bill matching. These follow-ups
add no business phase or schema authorization. See docs/PROCESSING_UX.md and
docs/PURCHASING_UX.md and docs/TEXTILE_MASTER_UX.md. Catalog guidance must preserve
all seven classifications, unit compatibility, protected identities and the
separation between definitions and inventory quantities.

The user additionally authorized direct admin Confirm for Purchasing/Processing,
optional Save for later and actual transaction PDF downloads. Preserve backend
permissions for the separate Wholesale operator; no extra approver is needed for
the existing fully authorized admin. Keep atomic posting, idempotency and audit
history. See docs/SIMPLE_ADMIN_FLOW.md. Phase 11 subsequently authorizes Wholesale sales as documented above.

## Product and stack

Single-company ladies' unstitched textile manufacturing and wholesale ERP in
Pakistan. Proposed stack: Laravel 13.x, PHP within Laravel 13's supported range,
MySQL 8.4 LTS, Blade, Tailwind CSS, Alpine.js, Vite, Eloquent and Laravel session
authentication. English UI, Inter typography, PKR. Verify exact platform
requirements when implementation is authorized. Use a modular monolith with
Laravel-native conventions, not microservices or a separate frontend.

Modules: Core, Identity, Parties, Catalog, Purchasing, Inventory, Warehouses,
Processing, Production, FinishedGoods, Sales, Accounting, HRPayroll, Reporting.

## Approved business structure

- One `parties` identity master; `party_role_types` and `party_roles` support
  supplier, vendor and customer simultaneously.
- `supplier_profiles`, `vendor_profiles`, `customer_profiles` attach to roles;
  never create duplicate independent party identity masters.
- Vendor capabilities use `processing_services`, `vendor_services` and
  historical `vendor_service_rates`.
- Supplier screens belong to Purchasing, vendor screens to Processing, customer
  screens to Sales. They share one party master and use role-scoped routes and
  permissions.
- Processing sequence is configurable; no fixed Dan/dyeing/embroidery pipeline.
  Dyeing generally uses meters and PKR per meter.
- Preserve stock-ledger history, contractor custody and ownership, multiple
  warehouses, historical rates, double-entry accounting and reversal postings.
- Authentication roles and permissions are a separate authorization system;
  never conflate them with `party_roles`.

## Implementation rules

Inspect the repository first. Preserve completed functionality. Use thin
controllers, Form Requests, services/actions, Eloquent relationships, policies,
transactions, locking, idempotency and reusable Blade components. MySQL is
authoritative; use DECIMAL, proper PK/FK/UQ/CHECK constraints, immutable posted
transactions and safe deterministic migrations. Never drop production data or
run destructive resets without explicit authorization. Explain migration impact
before changing approved database decisions.

Use real screens, services and tests in implementation phases. Seeders provide
configuration/demo data only. Never claim functionality exists without its code
and tests. Run relevant checks, report actual results and distinguish a design
review from a test of an implemented database. Avoid unnecessary packages.

## Documentation and completion

Keep README and all `docs/` architecture, business rules, PRD, project overview,
development phases, state, changelog, testing and deployment documents consistent
with actual code and approved decisions. Phase 1 database specifications are
approved architecture, not wholesale implemented schema. Phases 5-6 implement Catalog and the approved warehouse/stock/document/FIFO
slice with minimum balanced inventory journals, reusing Phase 4 units. Collections map to designs.season; consumables to
trim items and categories. Never implement future operational tables without
the user's phase request.

Every phase report must state: features/deliverables, files changed, tables and
migrations, routes/screens, checks and results, known limits, documentation,
suggested Git commit message and readiness for approval. Stop there.
