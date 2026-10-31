# Phase 5 textile master data

## Processing follow-up ? 2026-10-27

The authorized [Fabric Out / Fabric In workflow](PROCESSING_FABRIC_FLOW.md) adds automatic
processed output identities, laat/component evidence, per-suit embroidery contracts
and atomic direct vendor handovers. Daily entry no longer requires manual routes
or jobs; historical and Production-linked workflows remain available. One additive
flow-history table and one service configuration preserve existing stock, journals
and permissions. Physical base-unit quantities remain authoritative; component
counts do not automatically create finished suits. Earlier Processing workflow
requirements below are historical where superseded by that guide.


## Phase 11 Sales references (2026-10-26)
Confirmed/unfinished Sales order lines also protect item stock identity. Referenced
SUIT design/color configurations cannot be silently reassigned. Wholesale selects
active finished SUIT variants or separate PACK SKUs; item-specific whole-suit SET
conversion is snapshotted on orders. Item master still has no stock quantity.
See [SALES.md](SALES.md).

## Phase 10 Catalog integration (2026-10-26)

The seven item classifications remain unchanged. Finished product items now allow
whole SUIT (an individual design/color variant) or whole PACK (a separate packed
assortment SKU). PACK is configured by Finished Goods setup. Only SUIT items may
have product_variants; PACK contents belong to published packing specifications.
No generic PACK/SUIT unit conversion is allowed, and fabric stays measured in M.
Published pack content protects component/pack SKU measurement identity and suit
variant design/color/configuration. See FINISHED_GOODS_COSTING.md.

Current integration: Phase 8 Processing now uses the shared party, stock/FIFO,
calendar and source-journal foundations. See [PROCESSING.md](PROCESSING.md).
The phase-specific scope below remains the historical implementation contract.

Implemented scope: master definitions only. No stock position, opening quantity,
warehouse balance, processing job, BOM, purchase, sale or accounting posting is
created by these screens, factories or configuration seeders.

## Current guided screens (2026-10-22)

Open **Textile master data > Overview / Start here**. Items, categories, units,
designs, colors, finished variants and collection browsing have separate sidebar
entries. The guided item form asks type first and displays only relevant fields;
stock/roll meanings and whole-roll limits are explained. Alternate units now have
a separate page linked from item details/edit. Choose roll tracking before saving
fabric: its specification already freezes the measurement identity. Existing
settings are preserved on edit; no quantity is entered in the master. See
[TEXTILE_MASTER_UX.md](TEXTILE_MASTER_UX.md) for operator instructions, file/route
inventory and the current completion report. The Phase 5 sections below describe
its original implementation; current regression evidence is in TESTING.md.

## Approved schema mappings

- Raw grey/kora and processed fabric are `items` with types `grey_fabric` and
  `processed_fabric`. Their physical base unit is M and they require a typed
  `fabric_specifications` row. Width is millimeters and GSM is descriptive;
  neither is an inventory quantity.
- Materials and consumables use `trim` with configurable categories (including
  Consumables). Packaging has its own `packaging` type. Finished suits, scrap and
  nonstock services retain the approved `finished`, `scrap` and `service` types.
  The classification is authoritative; category labels organize records.
- An individual finished suit item is measured in whole SUIT units. A separate
  PACK SKU uses an explicit Finished Goods specification. One `product_variants` row
  binds that SKU to a design, color, configuration code and positive component
  piece count. Piece count describes one suit; it does not create stock pieces.
- Collections are the approved `designs.season` descriptor, managed on designs
  and browsed/filterable at `/catalog/collections`. There is no new collection
  identity, foreign key or season table.
- Configurable composition, weave, category trees, collection labels, colors,
  design names and configuration codes provide textile variation without an
  EAV/JSON attribute system or new columns per design. Arbitrary new structured
  attribute types still require an approved schema change.

## Implemented tables and constraints

Migration: `2026_10_21_000001_create_textile_catalog_tables.php`. The seven tables
below are from the approved Phase 1 inventory. Existing `units` is reused.
All tables use InnoDB; integer FKs are `BIGINT UNSIGNED` and restrict delete and
update. Mutable timestamps are non-null DATETIME(6), default current timestamp,
with automatic updated-at refresh. Master rows have checked BOOLEAN is_active,
default 1. No `deleted_at` or quantity column is added.

| Table | Columns beyond ordinary id/timestamps/active base | Constraints and access indexes |
|---|---|---|
| item_categories | parent_id BIGINT UNSIGNED NULL; code VARCHAR(40) NOT NULL; name VARCHAR(100) NOT NULL | PK id, UQ code; parent FK to same table; (parent_id,is_active) index also supports the FK. Cycle validation uses the locked singleton company row. |
| items | category_id BIGINT UNSIGNED NOT NULL; sku VARCHAR(60) NOT NULL; name VARCHAR(200) NOT NULL; item_type VARCHAR(30) NOT NULL; base_unit_id BIGINT UNSIGNED NOT NULL; is_stocked BOOLEAN DEFAULT 1; track_rolls BOOLEAN DEFAULT 0; description TEXT NULL | PK id; UQ sku; FKs category/unit; indexes base unit, (category_id,item_type,is_active), (item_type,is_active), (name,is_active); CHECK approved item types and booleans, service implies nonstock/no rolls. |
| item_unit_conversions | item_id, unit_id BIGINT UNSIGNED NOT NULL; factor_to_base DECIMAL(20,10) NOT NULL | Ordinary entity with id and both timestamps, no is_active; UQ (item_id,unit_id), positive factor CHECK; item/unit FKs; unit index. No factor is shared globally across items. |
| fabric_specifications | item_id BIGINT UNSIGNED PK/FK; width_mm, gsm DECIMAL(12,3) NULL; composition VARCHAR(200) NOT NULL; weave VARCHAR(100) NULL | No surrogate id or is_active; timestamps; width/GSM CHECK NULL or positive. Fabric-kind/one-spec invariant enforced transactionally. |
| designs | code VARCHAR(50) NOT NULL; name VARCHAR(150) NOT NULL; season VARCHAR(60) NULL; description TEXT NULL | PK id; UQ code; indexes (season,is_active), name. |
| colors | code VARCHAR(40) NOT NULL; name VARCHAR(100) NOT NULL; hex_code CHAR(7) NULL | PK id; UQ code; name index; hex format validated by Form Request and service. Shade batches remain future lot attributes. |
| product_variants | item_id, design_id, color_id BIGINT UNSIGNED NOT NULL; configuration_code VARCHAR(40) NOT NULL; piece_count TINYINT UNSIGNED NOT NULL; description VARCHAR(500) NULL | PK id; UQ item_id and (design_id,color_id,configuration_code); FKs item/design/color; color index; positive piece count CHECK; finished whole-suit identity validated in service. |

## Measurements and conversion integrity

`units` retains its approved code, name, dimension and decimal_places columns.
Codes, dimensions and precision cannot be rewritten through maintenance after
creation. Labels and status can change under audit. Count units require zero
decimal places. Configuration adds CM, YD, SET, DOZEN and SVC to existing units;
seeders use first-create semantics and preserve existing definitions.

The item base unit has implicit factor 1. An alternative unit must have the same
dimension and a positive DECIMAL(20,10) factor. Fabric base is M, never SUIT.
ROLL cannot have a fixed conversion because its length is measured. Complete
suits may use SET as an alternate whole-product count, never component PCS.
Material SKUs cannot use SUIT. Count factors must produce whole base units.

`CatalogService::convertToBase` uses BCMath strings and returns DECIMAL-scale six
digits. It rejects overflow and precision loss rather than rounding quantities
silently. For example, 125 CM × 0.01 = 1.250000 M. Entered-unit and base-unit
precision are both checked. No conversion writes inventory. Future document
lines must snapshot factor, entered quantity and canonical quantity.

Conversion maintenance locks the company and item, scopes conversion IDs to
their item and preserves the unit identity. Factors can be updated for future
use under audit; existing posting snapshots must remain unchanged in Phase 6.
Item classification/measurement flags are protected when a fabric specification,
conversion or variant already depends on them. The Inventory phase must extend
this guard to stock usage before posting is enabled; no inventory table is
queried or invented in Phase 5.

## Boundaries, authorization and lifecycle

Closed `CatalogRegistry` definitions map six master kinds to real models and
form fields. They do not store schema metadata or business attributes in EAV.
Thin controllers use Form Requests and CatalogPolicy; transactions, domain
validation and audits live in CatalogService. The singleton company row is a
conservative mutex for all catalog mutations, including category-tree edits.
Company setup is required. This serializes low-frequency master maintenance;
future high-volume posting must use the approved stock/document locking order.

Permissions are `catalog.masters.view` and `catalog.masters.manage`. View-only
users have read screens and cannot access create/edit/conversion writes. Unknown
master kinds return 404. SQL, audit and policy rules supplement rather than
replace each other. No hard-delete route is exposed, and catalog model delete
events reject physical deletion. Deactivate dependent active records before
their parent/unit/design/color. Audit snapshots accompany master, specification
and factor changes in the same transaction.

## Screens and installation

- `/catalog/units`, `/catalog/categories`, `/catalog/items`, `/catalog/designs`,
  `/catalog/colors`, `/catalog/variants`: list/search/status filters, pagination,
  create/view/edit, validation and status maintenance.
- Item lists filter by classification/category; design lists filter collection.
  Collection browsing groups existing design labels with pagination.
- Item details include specifications and a link to separate item-specific conversion maintenance.
  Shared Blade field components and form/list templates keep behavior consistent.
- Run normal migrations and `php artisan db:seed` on the intended application
  connection. `CatalogConfigurationSeeder` creates reference units/categories,
  never item quantities or fabricated opening balances. Set up the company and
  grant catalog permissions before maintaining records.
- Eight factories cover units, categories, items, fabric specs, conversions,
  designs, colors and variants. They are test-data helpers, not production stock.
- Composer explicitly requires ext-bcmath; dependency package versions are
  unchanged. PHP 8.3+ remains required.

## Review checklist

- [x] Reuse existing units; no duplicate material/product identity masters.
- [x] Preserve all seven approved item types and typed fabric attributes.
- [x] Collections map to designs.season; no unapproved table or EAV schema.
- [x] Exact conversion arithmetic, dimensions, suit/piece and roll rules enforced.
- [x] Real migrations, FKs, unique keys, row checks and lookup indexes.
- [x] Master screens, policies, validation, status, audits and factories exist.
- [x] No master quantity/opening-stock field or inventory tables.
- [ ] Validate the new DDL on authoritative MySQL 8.4 LTS.
- [ ] Exercise independent-connection contention against the catalog mutex.
- [ ] Extend identity freezing and snapshot rules when Inventory is implemented.

Current execution evidence and phase readiness are in PROJECT_STATE.md.
