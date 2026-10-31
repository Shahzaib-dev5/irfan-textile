# Deployment

## Physical Suit Stock follow-up (2026-10-28)

The authorized [Physical Suit Stock workflow](PHYSICAL_SUIT_STOCK.md) simplifies ready-suit
entry, adds processed-fabric inventory and dated customer deliveries/debit-credit
history. Existing stock/FIFO/journals remain authoritative; no migration or duplicate
balances. Production and packing remain under Advanced Manufacturing. The guide
records files, routes, ledger effects, deployment and limits; see TESTING.md for
verification results.

Local Fabric flow activation (2026-10-27): migration batch 11 and scoped
ProcessingFabricSetupSeeder completed after a verified 293,632-byte backup;
116 original table fingerprints matched and seven Processing pages returned 200.
See PROJECT_STATE.md and PROCESSING_FABRIC_FLOW.md for the operator setup and limits.


## Processing follow-up ? 2026-10-27

The authorized [Fabric Out / Fabric In workflow](PROCESSING_FABRIC_FLOW.md) adds automatic
processed output identities, laat/component evidence, per-suit embroidery contracts
and atomic direct vendor handovers. Daily entry no longer requires manual routes
or jobs; historical and Production-linked workflows remain available. One additive
flow-history table and one service configuration preserve existing stock, journals
and permissions. Physical base-unit quantities remain authoritative; component
counts do not automatically create finished suits. Earlier Processing workflow
requirements below are historical where superseded by that guide.


## Phase 12 local activation completed (2026-10-26)

Back up the local application DB to private storage; verify dump completion/hash.
Run `php artisan migrate --force`, then
`php artisan db:seed --class=FinanceSetupSeeder --force`. Build Vite assets and clear
configuration/route/view caches. Verify Finance GET routes and that old operational
rows/documents and unrelated role grants are unchanged. New operational tables must
remain empty. Configure actual bank/cash accounts through Finance; no balances are
seeded. Never run migrate:fresh against the application DB. Target MySQL 8.4 still
needs separate verification; the local engine is MariaDB 10.4.32. The local
`irfan_textile` activation completed as batch 10 after a private 264,744-byte SQL
backup. It created the eleven finance tables, added only configuration and eight
existing-admin accounting grants, and verified 16 Finance pages plus preservation of
old operational rows/document history/unrelated role grants. New operational tables
remain empty. Evidence: `storage/app/private/backups/phase12-activation-state.json`.
Actual activation status is in [PROJECT_STATE.md](PROJECT_STATE.md).


## Phase 11 local activation completed (2026-10-26)

Additive Sales migration ran as batch 9 after a fresh private 236,429-byte backup
with checked completion/SHA-256. There are 105 application tables plus Laravel
migration tracking. Scoped setup added five typed posting accounts, five document
types, five new admin grants and a Wholesale role with six Sales-only grants.
No user was created or assigned; use existing User Management for the real operator.
Nine actual local GET pages passed, and old operational row fingerprints/document
HEX payload/status hashes/unrelated-role grants matched the baseline. Two grant
audit events added, no fabricated stock/balance/business document. Evidence:
storage/app/private/backups/phase11-activation-state.json. Local MariaDB 10.4.32;
MySQL 8.4 acceptance and restore rehearsal remain pending. Refresh the app and
open Wholesale sales > Overview / Start here. Stop after Phase 11.

## Phase 11 deployment (2026-10-26)
Use supported PHP and back up the configured database before the additive Sales
migration. Existing company, stock, customer/terms and calendar must be configured.

```powershell
php artisan config:clear
php artisan migrate --path=database/migrations/2026_10_26_000002_create_wholesale_sales_tables.php --force
php artisan db:seed --class=SalesSetupSeeder --force
npm run build
php artisan route:clear
php artisan view:clear
```

Scoped setup provisions five document types, five typed posting accounts, existing
admin grants and a Wholesale role with Sales-only grants excluding credit admin.
It does not create/assign a user or replace other role permissions. Assign the real
operator through existing User Management; passwords remain unchanged. Do not run
broad authorization reseeding to activate this phase. No fake operational stock,
customer balance, payment or tax. Actual local activation and backup evidence are
in [PROJECT_STATE.md](PROJECT_STATE.md); target MySQL 8.4 and commercial acceptance
remain separate. Older activation entries below refer to their named phases.

## Phase 10 locally activated (2026-10-26)

The seven-table migration ran successfully as batch 8 on local irfan_textile,
MariaDB 10.4.32. A fresh private 223,244-byte SQL backup with checked completion
marker/SHA-256 preceded activation; restore drill not performed. Scoped setup
created PACK/PK configuration and four existing-admin grants. Six actual admin
GET pages returned 200. Existing operational counts/document fingerprints and
other-role grants match the baseline; no operational record was fabricated.
Private evidence: storage/app/private/backups/phase10-activation-state.json.
Refresh the application and open Finished goods > Overview / Start here.
Exact MySQL 8.4 and company/accountant acceptance remain pending.

## Phase 10 deployment (2026-10-26)

Use supported PHP and the intended connection. Back up before applying the
additive seven-table migration. Rebuild Vite assets for packing.js and Catalog
PACK choices. Existing phases/company/open financial periods must be configured.

```powershell
php artisan config:clear
php artisan migrate --path=database/migrations/2026_10_26_000001_create_finished_goods_packing_tables.php --force
php artisan db:seed --class=FinishedGoodsSetupSeeder --force
php artisan route:clear
php artisan view:clear
```

The scoped setup requires an existing active super-admin, creates PACK/PK config
and only Finished Goods permissions, preserves other grants and audits newly
attached permissions. No user, profile, stock, cost source or business document is
seeded. Fresh-install DatabaseSeeder includes the configuration through its normal
workflow. Existing Inventory permissions additionally govern inspection/disposal.

Local activation is performed only after private backup and passing checks; the
actual result is recorded in PROJECT_STATE. Source/PDF security and existing GD
branding limitations are unchanged; no Composer/npm dependency is added. Local
MariaDB tests are not exact MySQL 8.4 acceptance. Company/operator/accountant
acceptance must cover real assortments, recovery allocations and inspection/disposal.

## Local activation completed (2026-10-26)

The user's explicit follow-up authorized application DB changes. A private SQL
backup of local irfan_textile was completed before applying the Phase 9 migration
as batch 7 and running ProductionSetupSeeder. Seven admin pages passed read-only
HTTP checks; existing operational counts/document hashes and other-role grants
were preserved. This is local MariaDB activation, not MySQL 8.4 production acceptance.
See PROJECT_STATE.md for the actual result.

For an existing installation with its company, earlier migrations and active
super-admin already configured, use a supported PHP CLI from the project root:

```powershell
php artisan config:clear
php artisan migrate --path=database/migrations/2026_10_24_000001_create_production_tables.php --force
php artisan db:seed --class=ProductionSetupSeeder --force
php artisan route:clear
php artisan view:clear
```

Verify the intended connection and backup before migration. The scoped setup
seeder extends only super-admin Production grants, preserves all other roles,
and audits newly attached permissions. It does not perform demo/business seeding
or create users/roles. A broader IdentityAuthorizationSeeder rerun is unnecessary
for this upgrade. The normal DatabaseSeeder remains the initial-install workflow.

## Phase 9 deployment (2026-10-26)

Deploy PHP/Blade/config source and Vite assets together. On the intended database,
with a verified backup and supported PHP, apply the additive Production migration
using the normal deployment procedure, then run the configuration/authorization
seeders in dependency order. DatabaseSeeder includes ProductionConfigurationSeeder;
IdentityAuthorizationSeeder updates permission definitions and the existing
super-admin role. Review operator role grants deliberately; Wholesale must not
receive internal Production access. Initial implementation did not apply the
application migration; the explicitly approved local activation is recorded above.

The migration adds nine approved tables and one nullable FK to processing_jobs;
existing rows remain NULL. Source account codes 5130/5200/5210/5220 and posting
purposes use firstOrCreate, so inspect any pre-existing company mappings. No stock,
production order, cost source or payable is seeded. Existing phases and financial
year/period setup must already be operational. Build Vite assets and rebuild normal
route/view caches. Production requires existing bcmath/DOM/mbstring support;
PDF branding retains the prior JPEG/text fallback when GD is unavailable.

First verify these migrations/checks on MySQL 8.4 LTS: local test evidence is from
MariaDB 10.4.32. Run operator/accountant acceptance using a real approved BOM,
partial/final report and PDF. Packing, sales, payments and expense/payroll entry
screens are not part of Phase 9. See [PRODUCTION.md](PRODUCTION.md).

## Simple admin confirmation and PDFs (2026-10-22)

Run composer install from the updated lock (Dompdf 3.1.6 and four transitives), deploy rebuilt Vite assets and clear/rebuild ordinary route/view caches. PHP DOM/mbstring remain required; enable GD for PNG/WebP company logos (JPEG/text branding otherwise). Ensure storage/framework/cache is writable for private renderer/font cache. Remote PDF resources/PHP/JavaScript are disabled. No migration, seeding, .env or role/account change is required for this follow-up.
See [SIMPLE_ADMIN_FLOW.md](SIMPLE_ADMIN_FLOW.md).

## Guided Textile master follow-up (2026-10-22)

This Catalog follow-up changes PHP/Blade/Alpine source and adds two GET routes.
Deploy the source and rebuilt Vite assets together; clear/rebuild ordinary route
and view caches. No migration, configuration seeder, permission change or business
data import is required for the UX update. Existing setup/authorization remains
required. No application .env, credentials or data was changed by this work.
See [TEXTILE_MASTER_UX.md](TEXTILE_MASTER_UX.md). Target MySQL 8.4 validation and
live operator acceptance remain pending.

## Guided Purchasing follow-up (2026-10-22)

The Purchasing workspace adds routes, Blade views and Alpine assets only. Deploy source/build assets and clear/rebuild ordinary route/view caches. It requires no new migration, package, permission seed or credential change beyond existing Phase 7/8 installation requirements.
See [PURCHASING_UX.md](PURCHASING_UX.md).


## Phase 8 guided workspace (2026-10-22)

The guided Processing workspace adds routes and Vite/Blade assets only. Build assets with npm run build and clear/rebuild normal route/view caches after deployment. No migration, seed, new permissions or credential change is required for this UX follow-up itself; an installation still needs the previously documented Phase 8 prerequisites. See PROCESSING_UX.md.
See [PROCESSING_UX.md](PROCESSING_UX.md).


> Historical Phase 7 implementation report (2026-10-21): purchase orders/approval, partial GRNs,
> original lots/rolls, supplier returns, exact-cost stock bill matching and AP
> source records are implemented. See [PURCHASING.md](PURCHASING.md) for the
> actual schema, operating rules and limits. Processing/production/sales,
> payments and tax remain future scope; MySQL 8.4 validation is outstanding.

## Phase 5 runtime and initial setup

The application targets Laravel 13 on a supported PHP 8.3+ runtime, MySQL 8.4
LTS with InnoDB, ext-bcmath for exact conversions, Node/npm for asset builds, private application storage, session
authentication and compiled Vite assets. Local Phase 3-5 checks used PHP 8.3.35;
the available database was MariaDB 10.4.32. Validate the migration SQL against
the actual MySQL 8.4 target before production.

Configure `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL`, a generated
`APP_KEY`, UTC application timezone, least-privilege MySQL credentials and
`DB_CONNECTION=mysql`. Configure a real mail transport for password resets and
durable private storage for company logos, including backup/restore. Build
frontend assets with `npm ci && npm run build`. Serve only the Laravel `public/`
directory. Use TLS, restrict filesystem access, and keep `.env` and private
storage outside web access. Configure session/cache stores appropriate to the
deployment; the app defaults to database sessions, file cache and synchronous
queue processing.

Provision a new application database and review its target connection before
running `php artisan migrate --force`. Run `php artisan db:seed --force` for the
PKR master, stable role/permission catalog, party configuration and catalog
reference units/categories, then run the interactive
`php artisan erp:admin:create` from a trusted terminal. It refuses if any user
already exists and must not be exposed as an HTTP operation. Configure the
company profile, mail, private logo storage and financial calendar through the
authorized UI. Phase 3-5 migrations create setup and party/catalog tables; Phase 6 adds inventory
and its minimum document/FIFO/journal prerequisites. The Phase 3 migration intentionally refuses automatic
rollback. Never run `migrate:fresh`, `db:wipe` or reset against production. Back
up and rehearse each schema change.

## Before production

- Verify MySQL 8.4 version, strict SQL mode, InnoDB, utf8mb4, all setup-table
  CHECK constraints, migration ordering and backup restore.
- Use separate least-privilege app and migration credentials, encrypted database
  transport, secret storage and key rotation procedures.
- Set log, health-check, session-cleanup, private attachment and audit retention
  policies; test backup restoration and the maintenance/recovery procedure.
- Configure the real company, users and least-privilege roles. Financial-year
  dates and the proposed July start must be confirmed with the accountant before
  posting workflows are added. Phase 6 introduces evidenced opening stock, inventory posting accounts and
  document sequences. Confirm these before live posting. Do not seed fabricated stock or balances.
- Obtain business/accountant decisions for the open items in
  `DATABASE_DECISIONS.md`; tax/payroll values require professional review.

See [MIGRATION_PLAN](MIGRATION_PLAN.md) for dependency order and expand/backfill/
constrain policy. A code rollback does not guarantee that a data/schema rollback
is safe.

## Phase 6 installation and operational acceptance

Back up, inspect the intended DB connection, then run normal additive
`php artisan migrate` and `php artisan db:seed`. Configuration creates document
types, permissions and minimum inventory control/opening-equity/variance account
mappings, not stock or passwords. Existing super-admin gains the new permissions;
assign other roles deliberately. Install an open company financial calendar and
confirm account mappings before posting supported openings through the UI.

Target MySQL 8.4 still needs a disposable clean install, full suite and upgrade
rehearsal. Local tests used MariaDB 10.4.32 and never reset application data.
Keep the company mutex across all inventory/calendar/Catalog paths; assess its
single-writer throughput before increasing load. No reservation-expiry scheduler
is included: authorized explicit release is required. Use least-privilege grants
for append-only histories where possible. Reconcile stock/FIFO projections and
stock value/control journals, and accept interactive forms on target browsers.

## Phase 7 installation

Back up and inspect the intended connection, then run normal `php artisan migrate`
and `php artisan db:seed` with PHP 8.3+. The additive ten-table migration brings
the application to 68 tables. No stock, credentials or sample orders are seeded.
Existing super-admin receives six purchasing permissions; assign preparer,
approver, receipt, return and bill posting permissions deliberately for other
roles. Confirm GRNI, payable and return-clearing mappings with the accountant.

Basic bills are exact-cost stock matches, with no tax/price variance/settlement
or posted-bill reversal UI. Accept these boundaries before live entry. Use the
approved-order print page and perform interactive multi-line/browser checks.
Validate exact MySQL 8.4 DDL and the full suite in a disposable schema before
production. No application database migration/reset was run during this phase.

## Phase 8 installation and acceptance

Back up and verify the intended connection. Use compatible PHP from the project
root for normal php artisan migrate and php artisan db:seed; build assets and
clear caches. The ten-table additive migration brings the executable schema to
78 application tables and expands the existing invoice CHECK/adds its deferred
processing-step FK. Do not reset application data. The new configuration seeder
adds document types, permissions and account mappings only, including permissions
for existing super-admin. No application DB migration or stock seeding was run
as part of development validation.

Confirm processing_accrual and abnormal_processing_loss mappings, open periods,
active vendor capabilities with covering PKR contracts, and correctly linked
contractor locations. Keep capabilities/vendors active until custody is settled.
Rehearse multi-vendor partial receipts, count-based embroidery billing, custody
reconciliation and exact-rate payable matching on the intended connection.
Obtain operational acceptance of the total-loss, parallel-vendor and posted
correction boundaries in PROCESSING.md before live use. Run the full suite on
disposable MySQL 8.4; local validation uses MariaDB 10.4.32. Interactive responsive
forms and browser printing still require deployment acceptance.
