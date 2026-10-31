# Textile Manufacturing ERP

## Guided Finance follow-up (2026-10-29)

Payments, receipts and advance adjustments now use one party-first guided screen:
choose role, review unpaid items/available credit, then record money or apply an
existing advance. Matching controls and capped application amounts are suggested;
the original multi-allocation form remains under Advanced. No migration required.
See [Finance guided UX](docs/FINANCE_GUIDED_UX.md) and [testing](docs/TESTING.md).

## Physical Suit Stock follow-up (2026-10-28)

The authorized [Physical Suit Stock workflow](docs/PHYSICAL_SUIT_STOCK.md) simplifies ready-suit
entry, adds processed-fabric inventory and dated customer deliveries/debit-credit
history. Existing stock/FIFO/journals remain authoritative; no migration or duplicate
balances. Production and packing remain under Advanced Manufacturing. The guide
records files, routes, ledger effects, deployment and limits; see TESTING.md for
verification results.

## Processing follow-up ? 2026-10-27

The authorized [Fabric Out / Fabric In workflow](docs/PROCESSING_FABRIC_FLOW.md) adds automatic
processed output identities, laat/component evidence, per-suit embroidery contracts
and atomic direct vendor handovers. Daily entry no longer requires manual routes
or jobs; historical and Production-linked workflows remain available. One additive
flow-history table and one service configuration preserve existing stock, journals
and permissions. Physical base-unit quantities remain authoritative; component
counts do not automatically create finished suits. Earlier Processing workflow
requirements below are historical where superseded by that guide.


## Phase 12 — Accounting and finance

Finance adds guided accounts, journals, cash/bank, payments, receipts, settlements,
controlled reversals, supplier return credits, bank reconciliation and financial
statements. Existing operational journals remain the source; no financial balance
is seeded. See [Accounting guide](docs/ACCOUNTING_FINANCE.md),
[project state](docs/PROJECT_STATE.md) and [test evidence](docs/TESTING.md).
Earlier phase-only exclusions below are historical.


Phase 11 adds Wholesale Sales: customer orders, credit-controlled backorders,
lot reservations, partial dispatch, matched invoices, physical returns and credits.
Open **Wholesale sales > Overview / Start here**. Actual stock and source journals
reuse the existing inventory/accounting services. See [the guide and completion
report](docs/SALES.md). Phase 11 supersedes older phase-only stop/scope notes below;
stop after Phase 11. Migration/setup are applied locally after backup. Full
regression 220 / 1,585 assertions and final Sales/login 44 / 219 passed (overlapping
runs). [Project state](docs/PROJECT_STATE.md) records readiness and target-DB limits.

Phase 10 adds Finished Goods packing, actual batch/per-suit costing, current
stock valuation and lot/job/batch trace. Open **Finished goods > Overview / Start
here**, then Packing specifications, Packing / recovery records, Batches & actual
costing and Finished stock & quality. See [the Phase 10 guide](docs/FINISHED_GOODS_COSTING.md).
Phase 10 migration and four existing-admin permissions are applied locally after
backup. Full regression: 198 tests / 1,433 assertions passed. Refresh the app to
open Finished goods. See [project state](docs/PROJECT_STATE.md) and
[testing](docs/TESTING.md) for checks and remaining target-DB acceptance.
Stop after Phase 10; older dated phase scope notes below are historical.

Local Phase 9 activation is now complete: Production migration and the existing
admin's scoped permissions are applied. Refresh the app and open **Production >
Overview / Start here**. See [deployment notes](docs/DEPLOYMENT.md) for the actual
local result and repeatable setup commands.

Production is implemented through Phase 9: versioned recipes (BOMs), batch plans,
actual consumption/output, partial production, physical WIP, loss/cost allocation,
linked configurable processing and batch/report PDFs. Open **Production > Start
here**, then Recipes, Production batches, Consumption & output, and Progress.
See [the production guide and completion report](docs/PRODUCTION.md).
This supersedes older Production-is-future statements below. Stop after Phase 9;
packing, wholesale transactions, payments and general accounting/payroll CRUD
remain outside the implemented scope.

Purchasing and Processing now offer **Confirm** directly on completed forms and
optional **Save for later**. Existing admins need no separate approval account.
All eight transaction types have **Download PDF** and clean printable documents.
See [the simple admin guide](docs/SIMPLE_ADMIN_FLOW.md). Backend permissions keep
separate Wholesale operators out of Purchasing/Processing unless explicitly granted.

Phase 8 adds configurable Processing routes, frozen job rates, contractor custody,
partial returns/loss costing and matched contractor bill payable sources.
See [the processing guide](docs/PROCESSING.md) for setup, workflow and limits.
Open **Processing > Overview / Start here** for the setup checklist and separate
jobs, dispatch, receipt, bill and report screens. See the
[guided Processing workspace](docs/PROCESSING_UX.md). The Phase 8 scope is retained.

Purchasing also provides a guided sidebar workspace: open **Purchasing >
Overview / Start here** for suppliers, orders, GRNs, returns, bills, payable
sources and prices/history. See [the Purchasing workspace guide](docs/PURCHASING_UX.md).

Textile master data now has separate sidebar screens. Open **Textile master data >
Overview / Start here**, then **Textile items > New textile item** for guided type,
code/category, measurement/roll and fabric details. Alternate units have their own
item screen. See [the Textile master guide](docs/TEXTILE_MASTER_UX.md).

Laravel 13 modular monolith foundation for a single-company ladies' unstitched
textile manufacturer and wholesaler in Pakistan. The application uses Blade,
Tailwind CSS, Alpine.js, Vite, Eloquent and Laravel session authentication. UI
language is English, functional currency is PKR, business timezone is
Asia/Karachi, and application timestamps use UTC.

## Current phase

Phases 1-3 establish the approved architecture, Laravel foundation, secure
authentication/authorization, company and financial-calendar setup. Phase 4
implements shared party identities with supplier, vendor and customer roles,
party contacts/addresses, vendor capabilities and effective-dated rates. Phase 5
adds textile items, categories, units/conversions, fabric specifications,
collections/designs/colors and finished product variants through Catalog screens.
Phase 6 adds warehouses/locations, grey lots/rolls, inventory documents, an
append-only quantity/value ledger, FIFO layers, reservations, transfers, counts,
reversals and balanced opening/variance journals. See
[the inventory operating guide](docs/WAREHOUSES_INVENTORY.md).
Phase 7 adds purchase orders/approval, partial GRNs, supplier returns and
exact-cost receipt-matched stock bills/AP sources, price history and printing.
See [Purchasing](docs/PURCHASING.md). Phase 8 implements configurable processing
jobs, custody, returns/loss and contractor bill sources. Phase 9 adds manufacturing as documented above. Sales, payments, tax and
general accounting workflows remain unimplemented.

## Requirements

- PHP 8.3 or newer within Laravel 13's supported range, with `bcmath`, `ctype`,
  `curl`, `fileinfo`, `mbstring`, `openssl`, `pdo_mysql`, `tokenizer`, `xml` and
  `zip` enabled.
- Composer 2.x.
- Node.js 20.19+ or 22.12+ and npm.
- MySQL 8.4 LTS with InnoDB and `utf8mb4`.

The repository was scaffolded and checked with Laravel 13.38.0 on PHP 8.3.35.
Phase 3-7 migrations and tests ran on local MariaDB 10.4.32 through PDO MySQL;
MySQL 8.4-specific behavior still needs validation on the target server.

## Local setup

1. Create a new database and least-privilege application user in MySQL 8.4. The
   example schema name is `irfan_textile`.
2. Install dependencies and prepare environment configuration:

   ```powershell
   composer install
   Copy-Item .env.example .env
   php artisan key:generate
   ```

3. Set `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` and `DB_PASSWORD` in
   `.env`. Keep `DB_CONNECTION=mysql` and `DB_CHARSET=utf8mb4`.
4. Apply foundation and Phase 3/4/5/6/7 migrations, seed permission, currency,
   party-role, unit, payment-term and processing-service configuration, create
   the first administrator interactively, and build assets:

   ```powershell
   php artisan migrate
   php artisan db:seed
   php artisan erp:admin:create
   npm install
   npm run build
   php artisan serve
   ```

5. Visit `/up` for Laravel's liveness endpoint, `/health` for application and
   database connectivity, `/login` for the session-authentication screen, and
   `/dashboard` for the protected dashboard shell.

`erp:admin:create` is a one-time interactive console command. It refuses to run
if any user exists and never accepts a password as a command-line argument. Use
the login page to sign in, then create additional users and assign least-privilege
roles. Configure mail delivery in `.env` for password-reset email; the default
local mailer writes messages to the application log. Do not use local credentials
in production.

## Development and quality checks

```powershell
composer run dev
composer run format
composer run format:check
php artisan test
npm run build
```

Feature tests use the dedicated `irfan_textile_phase3_test` database configured
in `phpunit.xml`, including migrations and test writes. Provision it separately;
never point the test suite at production or the application database.

## Architecture and project documents

- [Project state and phase completion reports](docs/PROJECT_STATE.md)
- [Textile master data and measurement rules](docs/TEXTILE_MASTER_DATA.md)
- [Approved database schema](docs/DATABASE_SCHEMA.md)
- [Database decisions and remaining policy gates](docs/DATABASE_DECISIONS.md)
- [Domain and cross-module ERDs](docs/ERD.md)
- [Business rules](docs/BUSINESS_RULES.md)
- [Migration dependency plan](docs/MIGRATION_PLAN.md)
- [Module architecture](docs/ARCHITECTURE.md)
- [Product requirements](docs/PRD.md)
- [Development phases](docs/DEVELOPMENT_PHASES.md)
- [Testing](docs/TESTING.md)
- [Deployment](docs/DEPLOYMENT.md)
- [Changelog](docs/CHANGELOG.md)
- [Contributor instructions](AGENTS.md)
