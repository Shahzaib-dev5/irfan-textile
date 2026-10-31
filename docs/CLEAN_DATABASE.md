# Separate clean local database — 2026-10-30

At the user's request, created `irfan_textile_clean` from the existing local
`irfan_textile` database. The source database and application connection were
preserved. This is a local data preparation operation within the existing Phase 12
schema, not a new business phase or migration.

All 118 table definitions were copied, including keys and constraints. Data in 44
master/setup tables was preserved exactly: company/settings, users/permissions,
financial calendar, currencies, party identities/profiles/contacts/rates, textile
catalog, warehouses/locations, chart of accounts/posting configuration, bank account
definitions, processing routes, BOMs, packing specifications and migration history.

The other 74 tables are empty in the new database: transaction documents and lines,
stock lots/rolls/positions/balances/FIFO/history, production and packing activity,
purchase and sales activity, journals/open items/payments/receipts/settlements,
bank statement and reconciliation activity, audit/status history, document counters,
sessions and password reset tokens. No opening stock or financial balances were
invented. Document counters start fresh. Existing master definitions, including
processed item definitions, remain available. Login credentials are preserved.

Verification completed:

- All 44 copied tables matched source row fingerprints exactly.
- All 74 empty tables had zero rows.
- All 118 schemas matched, allowing fresh transaction auto-increment counters.
- All 295 foreign-key relationships passed orphan checks.
- All source table fingerprints remained unchanged after the operation.
- Laravel connected to the new database in an isolated verification process;
  all 13 migrations reported as already run. No migrations were executed.

Private artifacts under `storage/app/private/`:

- `backups/clean-source-20261030-120916.sql`: full source snapshot, 638,239 bytes;
  SHA-256 `d52a531060f879d8bc6a098ec436737e13a266baa5f3a0e63b3747ec81d75967`.
- `backups/clean-database-20261030-120916.json`: counts and fingerprint evidence.
- `inspect-clean-db.php`, `create-clean-db.php`, `verify-clean-db.php`: local
  operation scripts; creation refuses an existing target and issues no source writes.

The SQL snapshot is intended for import into an empty database; restore rehearsal
and interactive operator acceptance were not performed. Local engine remains
MariaDB, not the production MySQL 8.4 target.

The initial creation left `.env` unchanged. During the user's follow-up about old
entries still appearing, all 74 empty tables were rechecked and the app was found
connected to `irfan_textile`. The local `.env` was then switched to
`DB_DATABASE=irfan_textile_clean` and the configuration cache cleared. Restart the
server and log in again to use the clean database. Switching back
uses `DB_DATABASE=irfan_textile` and the same restart procedure. Avoid posting new
transactions until the intended database has been selected.

No routes/screens, application services or migrations changed. Documentation:
this guide plus the project-state entry. Suggested documentation commit:
`docs: record separate clean local database preparation`. Database preparation and
verification are complete; the follow-up also selected the clean database locally.
