# Local ERP fresh start (2026-11-19)

The user authorized clearing the local dummy business data. Before the reset, a full SQL backup was saved at `storage/app/private/before-fresh-start-20261119.sql` (453034 bytes). The `irfan_textile` database was reset with `erp:reset-business` after an exact database name, non-production, backup, actor permission, schema, and foreign-key check.

The reset cleared all transaction, stock, accounting journal, party, textile item, vendor custody, audit, and login-session records. Document numbering starts fresh. It retained one company profile, both existing admin logins and access configuration, eight default item categories, eight processing services, units, currencies, financial year/period configuration, posting accounts, Main Warehouse with its hidden stock location, and Main Cashbox. The extra warehouse and HBL bank account were removed. No schema migration was made.

Verification: companies 1, users 2, parties 0, items 0, documents 0, stock movements 0, journal lines 0, audit events 0, warehouses 1 (`MAIN`), bank accounts 1 (`MAIN-CASH`). PHP lint of the reset command passed. All users must sign in again because sessions were cleared.

To preview a future reset, run `php artisan erp:reset-business --database=irfan_textile`. Execution additionally requires `--backup=<existing-full-backup> --actor=<authorized-user-id> --execute`. Never rerun it for this completed request. Keep the private backup for recovery until the user decides it is no longer needed.
