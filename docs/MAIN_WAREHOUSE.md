# Warehouses without internal locations (2026-11-16)

The user clarified the requirement: **multiple warehouses can be added; no internal
locations or racks should be created or selected by operators**. This supersedes
the earlier single-warehouse interpretation in this conversation. No new phase,
schema migration, data reset or backup restoration is part of this correction.

## Current behavior

- `/warehouses`: lists all warehouses, with Add warehouse and Edit actions.
- Existing create/edit/save routes accept unique code, name, address and status.
  Deactivation rejects warehouses with stock. Location-management requests are denied.
- Each new warehouse automatically receives one internal ledger location. Existing
  stock FKs and vendor custody records remain implementation details, not a rack UI.
- Receiving, physical suits, purchasing, processing, production, packing and sales
  returns select warehouse names only. A sole eligible warehouse is preselected;
  multiple eligible warehouses are offered without their internal location names.
- Warehouse transfers are available again through Inventory / Physical Suit Stock.
  **Confirm transfer** saves and posts in one atomic action; no separate draft/review
  step is required. Both inventory manage and post permissions are required. The
  existing stock/FIFO services remain authoritative, with the creation key reused
  for posting replay protection. Failed posting rolls back the draft and movements.
  Opening/adjustment screens retain their existing draft workflow.
- Stock reports show actual warehouse names and allow filtering by warehouse.
- Vendor custody is assigned automatically; it does not create another warehouse.
- Damaged/unchecked returns in any warehouse's default storage remain held and
  cannot be picked until properly released. No separate quarantine room is needed.

## Files and verification

Implementation touches WarehouseService, MainWarehouse (default storage/custody
helper), WarehouseController, warehouse views, shared warehouse selector/filter
components, transaction forms, navigation, warehouse display labels, Sales return
validation and Purchasing context selection. Existing stock/document services and
tables are reused. No new HTTP routes or database migrations.

Tests cover multiple warehouse creation, duplicate-code rejection, location API
rejection, one automatic storage record per warehouse, stock receipt and transfers,
deactivation with stock, held returns and multi-line browser selection. Current
results are in TESTING.md; prior single-warehouse results are historical only.

Suggested commit: `feat(warehouses): support multiple warehouses without location setup`.

Ready for operator review: 30 tests / 165 assertions, two-warehouse browser checks,
Vite build, Blade compilation and diff checks passed. No additional data changes
were made to the application database during this correction.

## Earlier authorized local dummy reset (already completed)

Before the clarification, the user permitted deleting dummy data. The previous
reset removed 67 documents, 9 lots, 26 movements, 31 journals and linked operational
rows, one bank statement, 3 warehouses and 6 locations. Users, six parties,
catalog, recipes/routes, accounts, company and calendar were retained. It created
one initial Main Warehouse. No second reset is being performed. New warehouses
can now be added alongside it; old dummy transactions have not been restored.

Private full SQL backup: `storage/app/private/before-main-warehouse-20261116.sql`
(454,433 bytes), SHA-256
`2B7FD6FACB8B61CB9714C75E4AFB8DFAE5968CC207416D2CF870145BB097D3C6`.
Recovery should first restore the full dump to a separate database for verification.
Overwriting the active database would replace any transactions since the reset.

The non-production `warehouse:reset-dummy` command remains an explicitly destructive
maintenance tool with preview-by-default, exact-database/actor/backup requirements,
transaction rollback and FK checks. Never run it as a normal deployment/setup step.
No automatic deletion or consolidation is required to use warehouse-only screens.

Existing installations with multiple old internal locations retain those identities
for history; new receipt choices expose one default per warehouse. A dedicated data
consolidation of such legacy records is not included. Exact MySQL 8.4 and concurrency
stress remain unverified; local checks use PHP 8.4.9 and MariaDB.
