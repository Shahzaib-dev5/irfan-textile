# Guided Textile master data workspace

## Catalog list layout refinement (2026-11-05)

Master lists use a compact heading, one expandable search/filter panel, and a
fixed-layout table with concise definitions. Item type chips were consolidated
into the classification dropdown. Long item codes truncate visually while
remaining available as a title. Pagination remains in place; the
footer reports the current visible range and total, and pagination controls
appear when more than one page exists. The Items list shows five records per page
and allows vertical page scrolling so all content and pagination remain reachable;
the table fits the available width without horizontal scrolling. Other master lists retain
20 records per page. Catalog sub-tabs remain available in the top module bar and
the Textile master data sidebar. The redundant in-page catalog breadcrumb
was removed; the application breadcrumb and Setup guide action remain. No route,
filter behavior, table data, permission or schema changed.

## Finished product measurement in Phase 10

Choose Finished product, then SUIT for an individual design/color variant or PACK
for a distinct packed assortment. The default stays SUIT. PACK needs its own SKU
and Finished Goods packing specification; it is not a conversion of raw meters
or a substitute for a SUIT variant. Existing measurement/reference locks remain.

Date: 2026-10-22 (Asia/Karachi). User-authorized interface follow-up to the existing
Catalog module, alongside the Processing and Purchasing guided screens. No new
business phase or schema is introduced. Existing domain rules remain in
[TEXTILE_MASTER_DATA.md](TEXTILE_MASTER_DATA.md).

## Start here

Open **Textile master data > Overview / Start here** in the sidebar. Each master
has a separate list and create/view/edit screen:

| Screen | What belongs here | Example |
|---|---|---|
| Textile items | Actual SKU used by purchases, inventory and processing | GREY-COTTON-01, Grey cotton |
| Item categories | Groups containing textile items; never a stock identity | Cotton fabric, Packing materials |
| Units of measure | Physical measurement and precision | M, KG, PCS, SUIT |
| Designs | Reusable design identity; optional collection/season label | D-101, Summer 2027 |
| Colors | Reusable color names and optional display swatch | Navy blue |
| Finished product variants | Finished SUIT item linked to design/color/configuration | Three-piece suit, piece count 3 |
| Collections / seasons | Browse season labels already entered on designs | Summer 2027 |

A category does not receive stock. A finished variant defines a product; it does
not manufacture one. Saving any master creates no opening quantity or movement.

## Create the first fabric item

1. Check **Units of measure** for active M. Standard configuration already defines
   the ordinary units; do not create a second meter code.
2. In **Item categories**, create a grouping such as Cotton fabric if missing.
3. Open **Textile items > New textile item** or the Grey / kora fabric card on the
   overview. Choose the type; then enter unique SKU, recognizable name and category.
4. Review the suggested M base unit and Stock-managed setting. Choose whether to
   track individual physical rolls **before saving**. Lot/quantity tracking is
   the simpler choice when staff does not identify each real roll.
5. Enter required fiber composition, for example 100% cotton. Width in millimeters,
   weave and GSM are optional, collapsed under Optional fabric details.
6. Save item. The next-actions panel links to permitted Purchasing, Inventory and
   Processing screens. Receive real fabric through a GRN, or use the auditable
   Inventory opening-stock mechanism where appropriate. Do not invent receipts.
7. Create a **Processed fabric** item, for example DYED-COTTON-01, as a separate
   output stock identity when the processing route needs it. It also uses M.

The form preserves input after validation failure. Only fields applicable to the
selected type are submitted; temporarily switching type retains unsaved fabric
text in the browser. Explicitly changing type or pressing Use suggested settings
suggests a compatible unit and resets roll tracking to off for review. Existing
item settings are never silently changed when opening an edit page.

## Understand the settings

| Setting | Meaning | Important limit |
|---|---|---|
| Stock-managed item | Quantities are tracked through inventory documents | Fabric and finished suits require it; services have no physical stock |
| Track individual fabric rolls | Every physical roll gets its own number and measured length | Applies to grey/processed fabric; a roll moves whole between locations |
| Stock measurement unit | Canonical unit for the item's inventory quantity | Fabric M; complete finished suits SUIT |
| Alternate unit | Another compatible unit for the same item with an explicit conversion | Does not convert component PCS into suits or assign a fixed length to ROLL |
| Active | Available for new work under existing dependency rules | Deactivation retains history and is blocked by remaining stock/active dependencies |

**Choose measurement and roll tracking before the first fabric save.** A fabric
specification itself references the identity, so the existing service freezes
type, base unit and tracking flags immediately after saving fabric, even with no
stock. Conversions and variants also protect identity. Purchase lines or stock
lots additionally protect SKU and fabric specification. The form explains and
locks those fields; name/description remain editable. A different stock identity
requires a new SKU. This follow-up does not relax the server rules or introduce
roll splitting.

## Other item types

| Type | Suggested settings | Details |
|---|---|---|
| Grey / kora fabric | M; stocked | Raw purchased fabric; composition required |
| Processed fabric | M; stocked | Processing output; composition required |
| Material / consumable | Choose actual physical unit; stocked | Thread, lace and other supplies |
| Packaging material | Choose actual physical unit; stocked | Bags and boxes, often PCS |
| Finished product | SUIT; stocked | Complete suit definition; configure variant separately |
| Scrap | Choose actual physical unit; stocked | Saleable/reusable offcuts, for example KG |
| Nonstock service | SVC; nonstock; no rolls | Service definition; no physical stock |

Suggestions depend on active configured units. Missing required units do not
produce a fabricated ID: configure/reactivate the right unit first. Count units
use zero decimal places. Existing unit code, dimension and precision are shown
as protected; names/status remain editable under existing service validation.

## Alternate units on their own screen

Open an existing item and choose **Alternate units**. Only unused compatible
units are offered. For an item stocked in M, enter **CM**, factor **0.01**:
`125 CM * 0.01 = 1.250000 M`. The base unit already has factor 1.

Existing factors can be updated for future documents. Posted quantity/cost
snapshots are preserved. The unit identity of an existing conversion cannot be
changed. ROLL has a measured variable length and cannot have a fixed conversion;
finished SUIT may use SET as a whole-product count, not component PCS.

## Authorization

Existing `catalog.masters.view` controls overview, lists, details and the sidebar.
Existing `.manage` controls preparation/editing and conversion writes. The
alternate-unit page permits a viewer to read or a manager to maintain, preserving
manage-only role behavior. Backend policies/Form Requests/services remain
authoritative; hiding controls is not the access boundary. Next-action links also
respect Purchasing, Inventory and Processing permissions.

## Completion report

### 1. Features delivered

Permission-aware nested Catalog sidebar, setup overview, separate master screens,
item-type quick filters, category versus SKU explanations, guided type-specific
item entry, optional fabric details, stock/roll guidance, protected edit fields,
standalone compatible alternate-unit maintenance and next-action links. All seven
classifications, existing search/filter/pagination, shared templates and backend
validation/auditing are preserved. No new business CRUD module is added.

### 2. Files created and modified

Created:

- `app/Modules/Catalog/Services/CatalogWorkspace.php`
- `app/Modules/Catalog/Resources/views/masters/overview.blade.php`
- `app/Modules/Catalog/Resources/views/masters/item-fields.blade.php`
- `app/Modules/Catalog/Resources/views/masters/conversions.blade.php`
- `resources/views/components/erp/catalog-navigation.blade.php`
- `resources/js/catalog.js`
- `tests/Feature/CatalogWorkspaceTest.php`
- `tests/Frontend/catalog.test.mjs`
- `tests/Frontend/catalog-browser.mjs`
- `docs/TEXTILE_MASTER_UX.md`

Modified:

- `app/Modules/Catalog/Http/Controllers/MasterDataController.php`
- `app/Modules/Catalog/Routes/web.php`
- Catalog `Resources/views/masters/{nav,index,form,collections}.blade.php`
- `app/Modules/Core/Resources/views/layouts/app.blade.php`
- `resources/views/components/erp/master-field.blade.php`
- `resources/js/app.js`
- `AGENTS.md`, `README.md`, `docs/TEXTILE_MASTER_DATA.md`, `docs/PRD.md`,
  `docs/ARCHITECTURE.md`, `docs/PROJECT_OVERVIEW.md`, `docs/BUSINESS_RULES.md`,
  `docs/DEVELOPMENT_PHASES.md`, `docs/PROJECT_STATE.md`, `docs/CHANGELOG.md`,
  `docs/TESTING.md`, `docs/DEPLOYMENT.md`.

`CatalogService`, `CatalogRegistry`, models, policies, Form Requests, migrations,
seeders and package definitions are unchanged. Production bundles are generated
by Vite; no dependency is installed.

### 3. Tables and migrations

None. The existing 78 application tables are unchanged. No application DB
migration/reset, business-data seeding, environment edit or credential change
was run. Automated database writes are confined to the guarded isolated test DB.

### 4. Routes and screens

Two added GET routes: `/catalog` (`catalog.overview`) and
`/catalog/items/{item}/conversions` (`catalog.conversions.index`). Existing nine
route templates are retained: six parameterized master list/create/store/show/edit/
update routes, collection browsing, and conversion store/update. `route:list`
reports **11 registered Catalog routes**, nine preserved plus two new. Expanding
the closed six-value master parameter gives 41 concrete method/URL combinations.
Existing URLs still work. Conversion writes now return to their own page.

### 5. Checks and results

Full regression: **140 tests / 910 assertions** passed, including all nine race
checks. **21 Node tests** passed; Catalog, Purchasing and Processing Edge
desktop/mobile checks, Pint, Vite build (66 modules) and route cache/list/clear
passed. Blade compilation/lint passed for **123 templates**, cache cleared. See
[TESTING.md](TESTING.md) for evidence and reproducible commands.
The original 15 Catalog tests / 150 assertions passed. Nine new HTTP/workspace
cases / 74 assertions passed. All 21 Node tests passed. Seven synthetic Catalog
pages passed Edge desktop/mobile interaction, form-submission payload and
permission/read-only checks without submitting business documents.

### 6. Known limits

Exact MySQL 8.4 and live operator acceptance remain pending; local SQL testing
uses MariaDB 10.4.32. Browser smoke uses rendered synthetic test fixtures, not
live business data. Existing identity freezes, whole-roll movement and no
roll-split limits remain. Manufacturing, purchasing/processing changes, existing
vendor opening-stock import and payments are outside this UX follow-up.

### 7. Documentation updated

This guide and all documents listed in section 2 explain the current UX and scope.
Phase 5's schema/implementation report remains historical. Phase 8 remains the
latest business phase; no future phase has been started.

### 8. Suggested Git commit message

`feat(catalog): guide textile item setup with separate master screens`

### 9. Readiness for approval

Ready for code review and operator acceptance after the final checks recorded in
TESTING.md. Deployment to authoritative MySQL 8.4 remains subject to its pending
validation. Stop after this authorized interface follow-up.
