# Inventory, processing, production and finished-goods specifications

## Phase 11 Sales inventory integration (2026-10-26)
StockLedger posts sales_dispatch/sales_return with FIFO/value/cost links; shared
stock_reservations gains nullable sales_order_line_id and composite order parent
FK while old transfer reservations remain unchanged. Inventory-owned
SalesReservations handles sales reserve/fulfill/release under the same mutex.
Sales does not maintain independent stock totals. See [SALES.md](../SALES.md).

## Phase 10 implemented status (2026-10-26)

IP38-IP44 are now executable in migration
2026_10_26_000001_create_finished_goods_packing_tables.php. The seven tables reuse
existing variants, documents, movements, FIFO/value/cost links and warehouses.
No existing field/check/history is rewritten. Configuration adds whole-count PACK
for separate finished packed SKUs, with explicit contents rather than a unit
conversion. See [Phase 10 guide](../FINISHED_GOODS_COSTING.md) for cross-row checks,
full-recovery limitations, actual costing and verified execution/activation status.

## Phase 9 implementation status (2026-10-26)

IP29-IP37 are now executable in migration
2026_10_24_000001_create_production_tables.php. The nine approved table definitions
below are implemented with document/line FKs, unique movement references and
DECIMAL/check constraints. Integration adds optional nullable
processing_jobs.manufacturing_batch_document_id -> manufacturing_batches.document_id
(indexed, RESTRICT). No old job payload is rewritten; NULL batch links are omitted
from legacy hashes. Packing/FinishedGoods operational tables remain design only.
See [Production guide](../PRODUCTION.md) for service-enforced cross-row rules,
completion-only reports and verified execution limits.

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

Status: approved Phase 1 architecture. This document specifies a database; the warehouse/core inventory subset is implemented in Phase 6; processing is implemented in Phase 8; production and packing remain future scope.

## Reading the specifications

All tables use InnoDB and the project's agreed `utf8mb4` collation. `NN` means `NOT NULL`; `N` means nullable with `DEFAULT NULL`; `—` means no default, so the caller must supply a value. Unless a table explicitly says otherwise, it includes `id BIGINT UNSIGNED NN AUTO_INCREMENT PRIMARY KEY`, `created_at DATETIME(6) NN DEFAULT CURRENT_TIMESTAMP(6)`, and `updated_at DATETIME(6) NN DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6)`. These are real columns in each table, not an optional convention.

`H` replaces the ordinary `id` with `document_id BIGINT UNSIGNED NN — PRIMARY KEY REFERENCES documents(id)` and retains both timestamps. `L` includes ordinary `id`, timestamps, `document_id BIGINT UNSIGNED NN — REFERENCES <named header>(document_id)`, `line_no SMALLINT UNSIGNED NN —`, and `UNIQUE(document_id,line_no)`. `I` means an immutable posted fact with ordinary `id` and `created_at`, but **no `updated_at`**. A table marked `I` with additional FK columns uses those columns exactly as listed. Header and line timestamps never authorize edits after posting.

Every FK uses `ON DELETE RESTRICT ON UPDATE RESTRICT`; no physical cascades erase business history. An index covering each FK as its leftmost column is mandatory; below, `FK indexes` means individual nonunique indexes on all FK columns not already leftmost in a PK, unique key or listed composite index. `BOOL` is `BOOLEAN` with a `CHECK(column IN (0,1))`; MySQL's Boolean alias alone does not restrict values. Amounts are functional PKR `DECIMAL(20,4)`, quantities `DECIMAL(20,6)`, unit rates `DECIMAL(20,8)`, and fractions `DECIMAL(20,10)`. All named enumerations below are `VARCHAR` plus a stated `CHECK`, not MySQL `ENUM`.

All document roots, parties, vendor profiles, units, items, product variants, accounting accounts and journals referenced below are specified in the other domain chapters. In particular, `vendor_profiles.party_role_id` is the FK target; there is no independent vendor identity. All quantity columns use their item's base unit unless explicitly named `service_quantity` or accompanied by a unit column. Rates and their units are frozen on approval/posting.

## Table inventory

| Area | Tables |
|---|---|
| Warehouses and physical identity | `warehouses`, `warehouse_locations`, `stock_lots`, `fabric_rolls`, `fabric_roll_lineage`, `stock_positions` |
| Stock and valuation ledgers | `stock_balances`, `stock_movements`, `stock_cost_layers`, `inventory_layer_allocations`, `inventory_value_entries`, `inventory_cost_links`, `stock_reservations` |
| Inventory documents | `inventory_transfers`, `inventory_transfer_lines`, `inventory_adjustments`, `inventory_adjustment_lines`, `inventory_cost_adjustments`, `inventory_cost_adjustment_lines` |
| Configurable subcontract processing | `processing_route_versions`, `processing_route_steps`, `processing_jobs`, `processing_job_steps`, `processing_dispatches`, `processing_dispatch_lines`, `processing_receipts`, `processing_receipt_lines`, `processing_charge_allocations` |
| Manufacturing and costing | `bills_of_materials`, `bill_of_material_lines`, `manufacturing_batches`, `manufacturing_batch_materials`, `production_reports`, `production_material_lines`, `production_output_lines`, `production_wastage_lines`, `production_cost_allocations` |
| Packing and finished goods | `product_pack_specs`, `product_pack_spec_lines`, `packing_orders`, `packing_material_lines`, `packing_output_lines`, `packages`, `package_contents` |

There are 44 tables in this chapter. No separate finished-stock ledger exists: finished goods use the same lots, locations, movements and valuation system as grey fabric and materials.

## Warehouses and physical identity

### IP01 `warehouses`

Standard columns plus:

| Column | MySQL type | Null | Default | Reference / meaning |
|---|---|---|---|---|
| code | VARCHAR(30) | NN | — | Unique operational code |
| name | VARCHAR(120) | NN | — | Warehouse or custody grouping |
| address | VARCHAR(500) | N | NULL | Physical/contact description |
| is_active | BOOL | NN | 1 | Prevent new activity when inactive |

Constraints/indexes: `UNIQUE(code)`; `INDEX(is_active,name)`. A warehouse is a grouping, not the inventory owner.

### IP02 `warehouse_locations`

Standard columns plus:

| Column | MySQL type | Null | Default | Reference / meaning |
|---|---|---|---|---|
| warehouse_id | BIGINT UNSIGNED | NN | — | FK `warehouses.id` |
| code | VARCHAR(30) | NN | — | Unique within warehouse |
| name | VARCHAR(120) | NN | — | Bin, floor, quarantine, transit or contractor location |
| location_type | VARCHAR(20) | NN | — | `internal`, `contractor`, `transit`, `quarantine` |
| contractor_vendor_party_role_id | BIGINT UNSIGNED | N | NULL | FK `vendor_profiles.party_role_id` |
| is_active | BOOL | NN | 1 | New posting eligibility |

Constraints/indexes: `UNIQUE(warehouse_id,code)`; `CHECK(location_type IN (...))`; `CHECK((location_type='contractor' AND contractor_vendor_party_role_id IS NOT NULL) OR (location_type<>'contractor' AND contractor_vendor_party_role_id IS NULL))`; `INDEX(contractor_vendor_party_role_id,is_active)`; FK indexes. Separate locations represent separately accountable vendor facilities; a party's address is not a stock dimension.

### IP03 `stock_lots`

Standard columns plus:

| Column | MySQL type | Null | Default | Reference / meaning |
|---|---|---|---|---|
| item_id | BIGINT UNSIGNED | NN | — | FK `items.id` |
| lot_number | VARCHAR(50) | NN | — | Company-issued unique code |
| origin_document_id | BIGINT UNSIGNED | NN | — | FK `documents.id`; creating receipt/report/adjustment |
| external_lot_reference | VARCHAR(100) | N | NULL | Supplier/contractor reference, not unique |
| manufactured_on | DATE | N | NULL | Optional production date |
| expires_on | DATE | N | NULL | Optional material expiry |
| quality_status | VARCHAR(20) | NN | 'pending' | `pending`, `released`, `held`, `rejected` |

Constraints/indexes: `UNIQUE(lot_number)`; `INDEX(item_id,quality_status)`; `INDEX(origin_document_id)`; `CHECK(quality_status IN (...))`; `CHECK(expires_on IS NULL OR manufactured_on IS NULL OR expires_on>=manufactured_on)`; FK indexes. Identity and item are immutable after first posting. Quality status is controlled, audited operational metadata; quarantine relocations need stock documents. A lot has no balance column.

### IP04 `fabric_rolls`

Standard columns plus:

| Column | MySQL type | Null | Default | Reference / meaning |
|---|---|---|---|---|
| stock_lot_id | BIGINT UNSIGNED | NN | — | FK `stock_lots.id` |
| roll_number | VARCHAR(60) | NN | — | Unique company roll identity |
| external_roll_reference | VARCHAR(100) | N | NULL | Supplier mark |
| width_mm | DECIMAL(12,3) | N | NULL | Actual measured width |
| is_active | BOOL | NN | 1 | Replaced/exhausted identifiers can be retired |

Constraints/indexes: `UNIQUE(roll_number)`; `INDEX(stock_lot_id,is_active)`; `CHECK(width_mm IS NULL OR width_mm>0)`; FK indexes. Remaining meters are derived from movements. A partially cut roll stays the same identity until a physically separate child roll is created.

### IP05 `fabric_roll_lineage`

`I` columns plus:

| Column | MySQL type | Null | Default | Reference / meaning |
|---|---|---|---|---|
| document_id | BIGINT UNSIGNED | NN | — | FK `documents.id`; physical split/join/transformation |
| parent_roll_id | BIGINT UNSIGNED | NN | — | FK `fabric_rolls.id` |
| child_roll_id | BIGINT UNSIGNED | NN | — | FK `fabric_rolls.id` |
| parent_quantity_used | DECIMAL(20,6) | NN | — | Quantity in parent's item base unit |

Constraints/indexes: `UNIQUE(document_id,parent_roll_id,child_roll_id)`; `CHECK(parent_roll_id<>child_roll_id)`; `CHECK(parent_quantity_used>0)`; FK indexes. Posting rejects cycles, mismatched input consumption and duplicate physical representation. These checks require locked aggregate reads, not a cross-row SQL CHECK.

### IP06 `stock_positions`

Standard columns plus:

| Column | MySQL type | Null | Default | Reference / meaning |
|---|---|---|---|---|
| stock_lot_id | BIGINT UNSIGNED | NN | — | FK `stock_lots.id` |
| warehouse_location_id | BIGINT UNSIGNED | NN | — | FK `warehouse_locations.id` |
| fabric_roll_id | BIGINT UNSIGNED | N | NULL | FK `fabric_rolls.id`; NULL for non-roll stock |
| roll_key | BIGINT UNSIGNED | NN | GENERATED ALWAYS AS (IFNULL(fabric_roll_id,0)) STORED | Uniqueness helper, never an FK |

Constraints/indexes: `UNIQUE(stock_lot_id,warehouse_location_id,roll_key)`; `INDEX(warehouse_location_id,stock_lot_id)`; `INDEX(fabric_roll_id)`; FK indexes. There is no ordinary caller-supplied default for generated `roll_key`; it is always computed. `0` is reserved as the no-roll sentinel and never allocated as an entity ID. The posting service verifies `fabric_rolls.stock_lot_id=stock_positions.stock_lot_id`, and enforces `items.track_rolls`; those cross-table assertions cannot be SQL CHECK constraints. No nullable unique key is relied on to deduplicate unrolled stock.

## Stock and valuation ledgers

### IP07 `stock_balances`

Override standard PK: `stock_position_id BIGINT UNSIGNED NN — PRIMARY KEY REFERENCES stock_positions(id)`; both standard timestamps.

| Column | MySQL type | Null | Default | Reference / meaning |
|---|---|---|---|---|
| on_hand_quantity | DECIMAL(20,6) | NN | 0 | Projection of posted stock movements |
| reserved_quantity | DECIMAL(20,6) | NN | 0 | Projection of active reservations |
| lock_version | BIGINT UNSIGNED | NN | 0 | Optimistic API/UI guard; database locks still required |

Constraints/indexes: `CHECK(on_hand_quantity>=0)`; `CHECK(reserved_quantity>=0 AND reserved_quantity<=on_hand_quantity)`. This is a rebuildable projection, updated only inside posting/reservation transactions after locking its row. It is not a second authoritative ledger.

### IP08 `stock_movements`

`I` columns plus:

| Column | MySQL type | Null | Default | Reference / meaning |
|---|---|---|---|---|
| document_id | BIGINT UNSIGNED | NN | — | FK `documents.id` |
| sequence | INT UNSIGNED | NN | — | Deterministic per-document row sequence |
| stock_position_id | BIGINT UNSIGNED | NN | — | FK `stock_positions.id` |
| quantity_delta | DECIMAL(20,6) | NN | — | Positive receipt; negative issue |
| movement_reason | VARCHAR(30) | NN | — | `purchase_receipt`, `purchase_return`, `transfer`, `processing`, `production`, `packing`, `sales_dispatch`, `sales_return`, `opening`, `count`, `damage`, `reversal` |
| reversal_of_movement_id | BIGINT UNSIGNED | N | NULL | FK `stock_movements.id`; exact full compensating row |

Constraints/indexes: `UNIQUE(document_id,sequence)`; `UNIQUE(reversal_of_movement_id)`; `INDEX(stock_position_id,id)`; `CHECK(sequence>0)`; `CHECK(quantity_delta<>0)`; `CHECK(movement_reason IN (...))`; FK indexes. Posting time/date/actor are obtained from the document root. The service enforces reversal equality and permitted source header kinds. Typed document detail rows reference their exact movement IDs; `document_id` alone does not enforce subtype membership.

### IP09 `stock_cost_layers`

Standard columns plus:

| Column | MySQL type | Null | Default | Reference / meaning |
|---|---|---|---|---|
| stock_position_id | BIGINT UNSIGNED | NN | — | FK `stock_positions.id` |
| receipt_stock_movement_id | BIGINT UNSIGNED | NN | — | FK `stock_movements.id`; positive receipt |
| fifo_date | DATE | NN | — | Original acquisition date carried through transfers |
| remaining_quantity | DECIMAL(20,6) | NN | — | Locked, rebuildable allocation projection |

Constraints/indexes: `UNIQUE(receipt_stock_movement_id)`; `INDEX(stock_position_id,fifo_date,receipt_stock_movement_id,id)`; `CHECK(remaining_quantity>=0)`; FK indexes. The unique source movement ID is also the stable FIFO tie-breaker, avoiding a duplicated sequence column. Receipt identity and FIFO ordering are immutable; only the remaining-quantity projection changes. A destination movement/layer is split when different source FIFO layers must retain different origins. Opening quantity is the referenced positive movement, not duplicated here. Dates are preserved on pure transfers; new transformed products take their completion date.

### IP10 `inventory_layer_allocations`

`I` columns plus:

| Column | MySQL type | Null | Default | Reference / meaning |
|---|---|---|---|---|
| stock_movement_id | BIGINT UNSIGNED | NN | — | FK `stock_movements.id` |
| stock_cost_layer_id | BIGINT UNSIGNED | NN | — | FK `stock_cost_layers.id` |
| quantity_delta | DECIMAL(20,6) | NN | — | Same sign as linked movement |

Constraints/indexes: `UNIQUE(stock_movement_id,stock_cost_layer_id)`; `CHECK(quantity_delta<>0)`; FK indexes. Each ordinary positive movement opens one layer and one positive allocation; a positive specific-cost reversal restores its original consumed layer instead; a negative movement can consume multiple layers. Sum allocations equals the movement quantity, and a layer's allocation sum equals its nonnegative remaining quantity. Full receipt reversal consumes that exact layer, so reversal is rejected if subsequent consumption is uncompensated.

### IP11 `inventory_value_entries`

`I` columns plus:

| Column | MySQL type | Null | Default | Reference / meaning |
|---|---|---|---|---|
| document_id | BIGINT UNSIGNED | NN | — | FK `documents.id` |
| sequence | INT UNSIGNED | NN | — | Stable within valuation document |
| stock_cost_layer_id | BIGINT UNSIGNED | NN | — | FK `stock_cost_layers.id` |
| inventory_layer_allocation_id | BIGINT UNSIGNED | N | NULL | FK `inventory_layer_allocations.id`; NULL for value-only delta |
| amount_pkr | DECIMAL(20,4) | NN | — | Signed increase/decrease in inventory carrying value |
| value_reason | VARCHAR(30) | NN | — | `receipt`, `issue`, `conversion`, `service_accrual`, `direct_cost`, `late_cost`, `write_down`, `rounding`, `reversal` |
| reversal_of_value_entry_id | BIGINT UNSIGNED | N | NULL | FK `inventory_value_entries.id` |

Constraints/indexes: `UNIQUE(document_id,sequence)`; `UNIQUE(reversal_of_value_entry_id)`; `INDEX(stock_cost_layer_id,id)`; `INDEX(inventory_layer_allocation_id)`; `CHECK(sequence>0)`; `CHECK(amount_pkr<>0)`; `CHECK(value_reason IN (...))`; FK indexes. Zero-cost stock is valid and has quantity allocations with no zero-value row. Value-only adjustments do not invent physical movements. Allocation/layer/document consistency is enforced during posting.

### IP12 `inventory_cost_links`

`I` columns plus:

| Column | MySQL type | Null | Default | Reference / meaning |
|---|---|---|---|---|
| document_id | BIGINT UNSIGNED | NN | — | FK `documents.id`; consuming/transforming document |
| sequence | INT UNSIGNED | NN | — | Stable document sequence |
| source_layer_allocation_id | BIGINT UNSIGNED | NN | — | FK `inventory_layer_allocations.id`; negative allocation |
| destination_stock_cost_layer_id | BIGINT UNSIGNED | N | NULL | FK `stock_cost_layers.id`; surviving output inventory |
| expense_account_id | BIGINT UNSIGNED | N | NULL | FK `ledger_accounts.id`; COGS, abnormal loss or adjustment expense |
| allocation_fraction | DECIMAL(20,10) | NN | — | Proportion of the consumed source allocation's cost |
| allocated_cost_pkr | DECIMAL(20,4) | NN | — | Original nonnegative cost attributed |

Constraints/indexes: `UNIQUE(document_id,sequence)`; `INDEX(source_layer_allocation_id)`; `INDEX(destination_stock_cost_layer_id)`; FK indexes; `CHECK(sequence>0)`; `CHECK((destination_stock_cost_layer_id IS NULL)<>(expense_account_id IS NULL))`; `CHECK(allocation_fraction>0 AND allocation_fraction<=1)`; `CHECK(allocated_cost_pkr>=0)`. Locked posting verifies fractions total exactly `1.0000000000` per negative allocation and sum original allocated costs equals its consumed cost, with deterministic final-row rounding. The graph cannot cycle: each destination is created by this document; original source layers predate it. A specific-cost reversal uses original allocations and links instead of choosing new FIFO stock.

### IP13 `stock_reservations`

Standard columns plus:

| Column | MySQL type | Null | Default | Reference / meaning |
|---|---|---|---|---|
| document_id | BIGINT UNSIGNED | NN | — | FK `documents.id`; order/job/batch commitment, or a draft transfer reservation in Phase 6 |
| sequence | INT UNSIGNED | NN | — | Stable reservation line |
| stock_position_id | BIGINT UNSIGNED | NN | — | FK `stock_positions.id` |
| quantity | DECIMAL(20,6) | NN | — | Original allocation |
| fulfilled_quantity | DECIMAL(20,6) | NN | 0 | Projection of linked authorized issues |
| released_quantity | DECIMAL(20,6) | NN | 0 | Audited explicit releases |
| expires_at | DATETIME(6) | N | NULL | Optional deadline |

Constraints/indexes: `UNIQUE(document_id,sequence)`; `INDEX(stock_position_id,expires_at)`; `CHECK(quantity>0 AND fulfilled_quantity>=0 AND released_quantity>=0 AND fulfilled_quantity+released_quantity<=quantity)`; FK indexes. Balance locks serialize allocations, fulfillment and releases. Expiry is not effective merely because wall-clock time passes: a transactional release updates both reservation and projection. Reservations are operational commitments, not posted accounting facts. Audit every mutation; reconstruct projection from active remainders. Reserving source stock does not permit negative stock.

## Inventory documents

### IP14 `inventory_transfers`

`H` columns plus:

| Column | MySQL type | Null | Default | Reference / meaning |
|---|---|---|---|---|
| related_transfer_document_id | BIGINT UNSIGNED | N | NULL | FK `inventory_transfers.document_id`; previous transit leg |
| remarks | VARCHAR(500) | N | NULL | Operational note |

Constraints/indexes: FK indexes. A leg is an atomic move between positions; inter-site shipping uses source→transit then transit→destination documents. A source document does not promise physical arrival. Service rejects cycles/self-references in related legs.

### IP15 `inventory_transfer_lines`

`L` to `inventory_transfers`, plus:

| Column | MySQL type | Null | Default | Reference / meaning |
|---|---|---|---|---|
| source_stock_position_id | BIGINT UNSIGNED | NN | — | FK `stock_positions.id` |
| destination_stock_position_id | BIGINT UNSIGNED | NN | — | FK `stock_positions.id` |
| quantity | DECIMAL(20,6) | NN | — | Canonical transferred quantity |
| source_stock_cost_layer_id | BIGINT UNSIGNED | NN | — | FK `stock_cost_layers.id`; exact layer for this split |
| source_stock_movement_id | BIGINT UNSIGNED | N | NULL | FK `stock_movements.id`; filled at posting |
| destination_stock_movement_id | BIGINT UNSIGNED | N | NULL | FK `stock_movements.id`; filled at posting |

Constraints/indexes: inherited line uniqueness; `UNIQUE(source_stock_movement_id)`; `UNIQUE(destination_stock_movement_id)`; `CHECK(quantity>0)`; `CHECK(source_stock_position_id<>destination_stock_position_id)`; FK indexes. Split a planned transfer into layer-specific lines before posting to preserve FIFO origins. Item/unit match is mandatory; different lots/rolls require documented physical split lineage, not an item substitution. Sum quantities and carrying values for the leg is zero. Ordinary internal location transfers create no P&L.

### IP16 `inventory_adjustments`

`H` columns plus:

| Column | MySQL type | Null | Default | Reference / meaning |
|---|---|---|---|---|
| adjustment_reason | VARCHAR(20) | NN | — | `opening`, `count`, `damage`, `write_off` |
| reference | VARCHAR(120) | N | NULL | Count sheet / approval reference |
| explanation | VARCHAR(1000) | NN | — | Mandatory evidence and reason |

Constraints/indexes: `CHECK(adjustment_reason IN (...))`. Physical count differences, opening owned inventory, damage and write-offs use the stock ledger; they never overwrite balances.

### IP17 `inventory_adjustment_lines`

`L` to `inventory_adjustments`, plus:

| Column | MySQL type | Null | Default | Reference / meaning |
|---|---|---|---|---|
| stock_position_id | BIGINT UNSIGNED | NN | — | FK `stock_positions.id` |
| quantity_delta | DECIMAL(20,6) | NN | — | Signed adjustment |
| counted_quantity | DECIMAL(20,6) | N | NULL | Physical count evidence |
| expected_quantity | DECIMAL(20,6) | N | NULL | Balance frozen at count cutoff |
| count_cutoff_movement_id | BIGINT UNSIGNED | N | NULL | FK `stock_movements.id`; count watermark |
| positive_unit_cost_pkr | DECIMAL(20,8) | N | NULL | Required for positive opening/gain; negative issues use actual FIFO |
| offset_account_id | BIGINT UNSIGNED | NN | — | FK `ledger_accounts.id` |
| stock_movement_id | BIGINT UNSIGNED | N | NULL | FK `stock_movements.id`; posting output |

Constraints/indexes: inherited line uniqueness; `UNIQUE(stock_movement_id)`; `CHECK(quantity_delta<>0)`; `CHECK(counted_quantity IS NULL OR counted_quantity>=0)`; `CHECK(expected_quantity IS NULL OR expected_quantity>=0)`; `CHECK((quantity_delta>0 AND positive_unit_cost_pkr IS NOT NULL AND positive_unit_cost_pkr>=0) OR (quantity_delta<0 AND positive_unit_cost_pkr IS NULL))`; FK indexes. Count posting requires both count fields, equality `quantity_delta=counted_quantity-expected_quantity`, and either frozen operations at the cutoff or documented intervening movement reconciliation. It does not compare a stale count to the latest balance.

### IP18 `inventory_cost_adjustments`

`H` columns plus:

| Column | MySQL type | Null | Default | Reference / meaning |
|---|---|---|---|---|
| source_document_id | BIGINT UNSIGNED | NN | — | FK `documents.id`; invoice, credit, count or approved valuation document |
| adjustment_kind | VARCHAR(30) | NN | — | `purchase_price`, `processing_price`, `landed_cost`, `production_cost`, `write_down`, `rounding` |
| explanation | VARCHAR(1000) | NN | — | Business reason |

Constraints/indexes: `INDEX(source_document_id,adjustment_kind)`; `CHECK(adjustment_kind IN (...))`; FK indexes. Posting runs in the same transaction as the originating invoice when possible; otherwise the originating document remains unposted until its dependent cost document can commit atomically. An adjustment never changes the original cost entries.

### IP19 `inventory_cost_adjustment_lines`

`L` to `inventory_cost_adjustments`, plus:

| Column | MySQL type | Null | Default | Reference / meaning |
|---|---|---|---|---|
| origin_stock_cost_layer_id | BIGINT UNSIGNED | NN | — | FK `stock_cost_layers.id`; root receiving the price correction |
| destination_stock_cost_layer_id | BIGINT UNSIGNED | N | NULL | FK `stock_cost_layers.id`; remaining stock target |
| expense_account_id | BIGINT UNSIGNED | N | NULL | FK `ledger_accounts.id`; already sold/lost fraction |
| amount_pkr | DECIMAL(20,4) | NN | — | Signed current-period cost delta |
| inventory_value_entry_id | BIGINT UNSIGNED | N | NULL | FK `inventory_value_entries.id`; only inventory targets |
| allocation_basis | VARCHAR(30) | NN | — | `remaining_quantity`, `cost_lineage`, `specific_write_down`, `rounding` |

Constraints/indexes: inherited line uniqueness; `UNIQUE(inventory_value_entry_id)`; `CHECK((destination_stock_cost_layer_id IS NULL)<>(expense_account_id IS NULL))`; `CHECK(amount_pkr<>0)`; `CHECK(allocation_basis IN (...))`; FK indexes. Line totals must equal the approved source delta; amounts reaching expense targets post directly to GL without a fictitious inventory value. Reductions cannot make any surviving layer negative. Closed periods remain closed; adjustments use the current authorized open period and retain original source references.

## Configurable subcontract processing

### IP20 `processing_route_versions`

Standard columns plus:

| Column | MySQL type | Null | Default | Reference / meaning |
|---|---|---|---|---|
| route_code | VARCHAR(40) | NN | — | Logical route identifier |
| version_number | INT UNSIGNED | NN | — | Immutable published version |
| name | VARCHAR(150) | NN | — | Descriptive route name |
| state | VARCHAR(20) | NN | 'draft' | `draft`, `published`, `retired` |
| is_active | BOOL | NN | 1 | Available for new work |

Constraints/indexes: `UNIQUE(route_code,version_number)`; `INDEX(state,is_active)`; `CHECK(version_number>0)`; `CHECK(state IN (...))`. Published routes and steps are immutable; changes create a new version. Stage order is data, not hardcoded service names.

### IP21 `processing_route_steps`

Standard columns plus:

| Column | MySQL type | Null | Default | Reference / meaning |
|---|---|---|---|---|
| processing_route_version_id | BIGINT UNSIGNED | NN | — | FK `processing_route_versions.id` |
| step_number | SMALLINT UNSIGNED | NN | — | Configured sequential order |
| processing_service_id | BIGINT UNSIGNED | NN | — | FK `processing_services.id` |
| input_item_id | BIGINT UNSIGNED | NN | — | FK `items.id` |
| output_item_id | BIGINT UNSIGNED | NN | — | FK `items.id` |
| planned_yield_ratio | DECIMAL(20,10) | NN | 1 | Output base quantity / input base quantity |
| permitted_normal_loss_fraction | DECIMAL(20,10) | NN | 0 | Planning tolerance, not automatic acceptance |

Constraints/indexes: `UNIQUE(processing_route_version_id,step_number)`; `CHECK(step_number>0 AND planned_yield_ratio>0)`; `CHECK(permitted_normal_loss_fraction>=0 AND permitted_normal_loss_fraction<1)`; FK indexes. The same service can occur more than once; there is no unique service constraint per route. Published versions validate step-to-step item compatibility. Parallel branching is explicitly deferred; separate jobs can represent independent routes.

### IP22 `processing_jobs`

`H` columns plus:

| Column | MySQL type | Null | Default | Reference / meaning |
|---|---|---|---|---|
| processing_route_version_id | BIGINT UNSIGNED | NN | — | FK `processing_route_versions.id` |
| planned_input_quantity | DECIMAL(20,6) | NN | — | First step input base unit |
| required_by | DATE | N | NULL | Operational target |
| instructions | VARCHAR(2000) | N | NULL | Approved processing instructions |

Constraints/indexes: `CHECK(planned_input_quantity>0)`; FK indexes. Posting approves the job definition and frozen steps; it creates no inventory or payable by itself. Dispatch/receipt documents record execution. Job progress and closure are derived from its posted physical documents, not mutable posted quantities.

### IP23 `processing_job_steps`

`L` to `processing_jobs`, plus:

| Column | MySQL type | Null | Default | Reference / meaning |
|---|---|---|---|---|
| processing_route_step_id | BIGINT UNSIGNED | NN | — | FK `processing_route_steps.id` |
| processing_service_id | BIGINT UNSIGNED | NN | — | FK `processing_services.id`; frozen selection |
| vendor_service_id | BIGINT UNSIGNED | NN | — | FK `vendor_services.id`; approved contractor capability |
| vendor_service_rate_id | BIGINT UNSIGNED | NN | — | FK `vendor_service_rates.id`; historic source |
| input_item_id | BIGINT UNSIGNED | NN | — | FK `items.id`; frozen input |
| output_item_id | BIGINT UNSIGNED | NN | — | FK `items.id`; frozen output |
| planned_input_quantity | DECIMAL(20,6) | NN | — | Canonical input |
| planned_output_quantity | DECIMAL(20,6) | NN | — | Canonical output |
| billing_unit_id | BIGINT UNSIGNED | NN | — | FK `units.id`; rate snapshot unit |
| agreed_unit_rate_pkr | DECIMAL(20,8) | NN | — | Immutable approved rate |
| rate_effective_date | DATE | NN | — | Date used to choose the historical rate |
| permitted_normal_loss_fraction | DECIMAL(20,10) | NN | 0 | Frozen job tolerance |

Constraints/indexes: inherited line uniqueness; `UNIQUE(document_id,processing_route_step_id)`; `CHECK(planned_input_quantity>0 AND planned_output_quantity>0 AND agreed_unit_rate_pkr>=0)`; `CHECK(permitted_normal_loss_fraction>=0 AND permitted_normal_loss_fraction<1)`; FK indexes. Cross-table equality of route/service/vendor/rate/units and effective dates is checked while locking vendor service. Negotiated overrides require creating an approved effective rate or explicitly recorded new job version; no silent edit of the snapshot. Meters and PKR/meter are ordinary unit/rate rows used for dyeing.

### IP24 `processing_dispatches`

`H` columns plus:

| Column | MySQL type | Null | Default | Reference / meaning |
|---|---|---|---|---|
| processing_job_document_id | BIGINT UNSIGNED | NN | — | FK `processing_jobs.document_id` |
| vendor_party_role_id | BIGINT UNSIGNED | NN | — | FK `vendor_profiles.party_role_id` |
| contractor_reference | VARCHAR(100) | N | NULL | Contractor challan/acknowledgement |

Constraints/indexes: FK indexes. The company-issued dispatch challan uses its document number; external references need not be unique.

### IP25 `processing_dispatch_lines`

`L` to `processing_dispatches`, plus:

| Column | MySQL type | Null | Default | Reference / meaning |
|---|---|---|---|---|
| processing_job_step_id | BIGINT UNSIGNED | NN | — | FK `processing_job_steps.id` |
| source_stock_position_id | BIGINT UNSIGNED | NN | — | FK `stock_positions.id` |
| contractor_stock_position_id | BIGINT UNSIGNED | NN | — | FK `stock_positions.id` |
| source_stock_cost_layer_id | BIGINT UNSIGNED | NN | — | FK `stock_cost_layers.id`; layer-specific transfer |
| quantity | DECIMAL(20,6) | NN | — | Material dispatched |
| source_stock_movement_id | BIGINT UNSIGNED | N | NULL | FK `stock_movements.id` |
| contractor_stock_movement_id | BIGINT UNSIGNED | N | NULL | FK `stock_movements.id` |

Constraints/indexes: inherited line uniqueness; `UNIQUE(source_stock_movement_id)`; `UNIQUE(contractor_stock_movement_id)`; `CHECK(quantity>0)`; `CHECK(source_stock_position_id<>contractor_stock_position_id)`; FK indexes. Vendor, job, step input item and contractor-location custodian must agree. Dispatch creates paired equal-cost, equal-quantity transfers and exact layer lineage, preserving ownership. Contractor-to-contractor work still uses explicit outgoing and incoming locations.

### IP26 `processing_receipts`

`H` columns plus:

| Column | MySQL type | Null | Default | Reference / meaning |
|---|---|---|---|---|
| processing_job_document_id | BIGINT UNSIGNED | NN | — | FK `processing_jobs.document_id` |
| vendor_party_role_id | BIGINT UNSIGNED | NN | — | FK `vendor_profiles.party_role_id` |
| contractor_reference | VARCHAR(100) | N | NULL | Return challan/reference |
| remarks | VARCHAR(1000) | N | NULL | Acceptance and inspection notes |

Constraints/indexes: FK indexes. A return can receive accepted output, rejected unchanged fabric or both, with separate detail lines. Stock never disappears when sent for processing.

### IP27 `processing_receipt_lines`

`L` to `processing_receipts`, plus:

| Column | MySQL type | Null | Default | Reference / meaning |
|---|---|---|---|---|
| processing_job_step_id | BIGINT UNSIGNED | NN | — | FK `processing_job_steps.id` |
| processing_dispatch_line_id | BIGINT UNSIGNED | NN | — | FK `processing_dispatch_lines.id`; custody source |
| disposition | VARCHAR(20) | NN | 'accepted' | `accepted`, `rejected` |
| source_stock_position_id | BIGINT UNSIGNED | NN | — | FK `stock_positions.id`; contractor input |
| output_stock_position_id | BIGINT UNSIGNED | NN | — | FK `stock_positions.id`; returned/next-stage output |
| source_quantity | DECIMAL(20,6) | NN | — | Input custody quantity closed by this line |
| output_quantity | DECIMAL(20,6) | NN | — | Measured output in output item's canonical unit |
| output_source_equivalent_quantity | DECIMAL(20,6) | NN | — | Output expressed in source units for yield reconciliation |
| normal_loss_quantity | DECIMAL(20,6) | NN | 0 | Input-unit normal process loss |
| abnormal_loss_quantity | DECIMAL(20,6) | NN | 0 | Input-unit abnormal loss |
| accepted_service_quantity | DECIMAL(20,6) | NN | 0 | Billable accepted service in frozen step billing unit |
| service_accrual_pkr | DECIMAL(20,4) | NN | 0 | Frozen rate × accepted service quantity, rounded |
| abnormal_loss_cost_pkr | DECIMAL(20,4) | NN | 0 | Explicit expense allocation from consumed input cost |
| abnormal_loss_account_id | BIGINT UNSIGNED | N | NULL | FK `ledger_accounts.id`; required for abnormal loss |
| source_stock_movement_id | BIGINT UNSIGNED | N | NULL | FK `stock_movements.id`; negative whole input |
| output_stock_movement_id | BIGINT UNSIGNED | N | NULL | FK `stock_movements.id`; positive accepted/rejected output |

Constraints/indexes: inherited line uniqueness; `UNIQUE(source_stock_movement_id)`; `UNIQUE(output_stock_movement_id)`; `CHECK(disposition IN (...))`; `CHECK(source_quantity>0 AND output_quantity>0 AND output_source_equivalent_quantity>0)`; `CHECK(normal_loss_quantity>=0 AND abnormal_loss_quantity>=0 AND accepted_service_quantity>=0 AND service_accrual_pkr>=0 AND abnormal_loss_cost_pkr>=0)`; `CHECK(source_quantity=output_source_equivalent_quantity+normal_loss_quantity+abnormal_loss_quantity)`; FK indexes.

Posting additionally enforces: dispatch outstanding quantity cannot be exceeded; source position matches dispatch destination; output item matches step output when accepted and step input when rejected; rejected lines have no service accrual/loss and equal input/output quantity; both source and output documents are within this job/vendor; normal loss tolerance requires authorized exception when exceeded; abnormal loss account is present exactly when an abnormal expense exists. Same-unit input/output uses actual output quantity as the source equivalent; cross-unit transformations require an explicit approved conversion/yield calculation, retained as these snapshot quantities. A complete catastrophic loss with no physical return uses a separately authorized damage adjustment against contractor stock linked to the job through the source document reference; it cannot fake a positive receipt.

Source input value plus accrued service equals received value plus abnormal expense; normal loss is absorbed by accepted output. Quantity loss does not issue twice. Each receipt's FIFO source allocations and cost links retain exact costing. Outstanding contractor stock by job is dispatched quantity less receipt source quantity and authorized job-linked losses, with reversal signs included.

### IP28 `processing_charge_allocations`

`I` columns plus:

| Column | MySQL type | Null | Default | Reference / meaning |
|---|---|---|---|---|
| purchase_invoice_processing_match_id | BIGINT UNSIGNED | NN | — | FK `purchase_invoice_processing_matches.id` |
| inventory_cost_adjustment_line_id | BIGINT UNSIGNED | NN | — | FK `inventory_cost_adjustment_lines.id` |
| amount_pkr | DECIMAL(20,4) | NN | — | Only the billed-versus-accrued difference |

Constraints/indexes: `UNIQUE(purchase_invoice_processing_match_id,inventory_cost_adjustment_line_id)`; `CHECK(amount_pkr<>0)`; FK indexes. Match rows point to accepted receipt lines, whose frozen rates establish the accrual. No price difference means no allocation row. Partial billing releases proportional accrued cost; all linked allocations sum to the matched price variance, not the full invoice value. Credits and reversals use the originating source's compensating cost documents and cannot bill accepted service twice.

## Manufacturing and per-suit costing

### IP29 `bills_of_materials`

Standard columns plus:

| Column | MySQL type | Null | Default | Reference / meaning |
|---|---|---|---|---|
| product_variant_id | BIGINT UNSIGNED | NN | — | FK `product_variants.id`; finished or intermediate configuration |
| version_number | INT UNSIGNED | NN | — | Version within variant |
| output_quantity | DECIMAL(20,6) | NN | 1 | Yield basis in output item base unit |
| state | VARCHAR(20) | NN | 'draft' | `draft`, `published`, `retired` |
| is_active | BOOL | NN | 1 | Eligible for new batches |

Constraints/indexes: `UNIQUE(product_variant_id,version_number)`; `CHECK(version_number>0 AND output_quantity>0)`; `CHECK(state IN (...))`; FK indexes. Published BOMs are immutable. A two-piece or three-piece suit is a variant/configuration; its material components remain explicit.

### IP30 `bill_of_material_lines`

Standard columns plus:

| Column | MySQL type | Null | Default | Reference / meaning |
|---|---|---|---|---|
| bill_of_material_id | BIGINT UNSIGNED | NN | — | FK `bills_of_materials.id` |
| line_no | SMALLINT UNSIGNED | NN | — | Component order |
| component_item_id | BIGINT UNSIGNED | NN | — | FK `items.id` |
| component_name | VARCHAR(100) | NN | — | E.g. shirt, trouser, dupatta, trim |
| net_quantity | DECIMAL(20,6) | NN | — | Canonical component quantity for BOM yield |
| planned_wastage_fraction | DECIMAL(20,10) | NN | 0 | Gross required = net / (1 − fraction) |

Constraints/indexes: `UNIQUE(bill_of_material_id,line_no)`; `CHECK(line_no>0 AND net_quantity>0)`; `CHECK(planned_wastage_fraction>=0 AND planned_wastage_fraction<1)`; FK indexes. A component may occur on separate functional lines; no false unique(item) rule. Publishing rejects recursive BOM cycles.

### IP31 `manufacturing_batches`

`H` columns plus:

| Column | MySQL type | Null | Default | Reference / meaning |
|---|---|---|---|---|
| bill_of_material_id | BIGINT UNSIGNED | NN | — | FK `bills_of_materials.id`; published version |
| planned_output_quantity | DECIMAL(20,6) | NN | — | Canonical product quantity |
| planned_start_on | DATE | N | NULL | Schedule |
| planned_finish_on | DATE | N | NULL | Schedule |
| instructions | VARCHAR(2000) | N | NULL | Approved production instructions |

Constraints/indexes: `CHECK(planned_output_quantity>0)`; `CHECK(planned_start_on IS NULL OR planned_finish_on IS NULL OR planned_finish_on>=planned_start_on)`; FK indexes. Posting authorizes the immutable batch plan and material snapshot; completion facts belong to reports. Execution state is derived. No mutable `actual_cost` field duplicates the ledgers.

### IP32 `manufacturing_batch_materials`

`L` to `manufacturing_batches`, plus:

| Column | MySQL type | Null | Default | Reference / meaning |
|---|---|---|---|---|
| bill_of_material_line_id | BIGINT UNSIGNED | NN | — | FK `bill_of_material_lines.id` |
| component_item_id | BIGINT UNSIGNED | NN | — | FK `items.id`; frozen snapshot |
| planned_net_quantity | DECIMAL(20,6) | NN | — | Planned consumption excluding loss |
| planned_gross_quantity | DECIMAL(20,6) | NN | — | Planned issue including normal loss |

Constraints/indexes: inherited line uniqueness; `UNIQUE(document_id,bill_of_material_line_id)`; `CHECK(planned_net_quantity>0 AND planned_gross_quantity>=planned_net_quantity)`; FK indexes. A snapshot intentionally preserves approved quantities even if a later BOM version changes.

### IP33 `production_reports`

`H` columns plus:

| Column | MySQL type | Null | Default | Reference / meaning |
|---|---|---|---|---|
| manufacturing_batch_document_id | BIGINT UNSIGNED | NN | — | FK `manufacturing_batches.document_id` |
| is_final | BOOL | NN | 0 | Declares batch complete after this report |
| report_note | VARCHAR(1000) | N | NULL | Yield and variance explanation |

Constraints/indexes: `INDEX(manufacturing_batch_document_id,is_final)`; FK indexes. Each report posts one completed conversion: actual inputs consumed together with good/intermediate/by-product outputs and losses. In-process material remains a physical stock item until converted; long manufacturing workflows use explicit intermediate items/lots and successive reports. There is no untracked monetary WIP bucket. A locked batch permits at most one effective posted final report and no subsequent reports. Reversing a final report requires subsequent dependencies to be unwound.

### IP34 `production_material_lines`

`L` to `production_reports`, plus:

| Column | MySQL type | Null | Default | Reference / meaning |
|---|---|---|---|---|
| manufacturing_batch_material_id | BIGINT UNSIGNED | N | NULL | FK `manufacturing_batch_materials.id`; NULL only for authorized extra/intermediate inputs |
| stock_position_id | BIGINT UNSIGNED | NN | — | FK `stock_positions.id` |
| quantity | DECIMAL(20,6) | NN | — | Gross actual issue, including measured losses |
| extra_material_reason | VARCHAR(500) | N | NULL | Required if no plan line or material substituted |
| stock_movement_id | BIGINT UNSIGNED | N | NULL | FK `stock_movements.id`; negative issue |

Constraints/indexes: inherited line uniqueness; `UNIQUE(stock_movement_id)`; `CHECK(quantity>0)`; FK indexes. The planned item's identity is checked unless an explicit authorized substitution is recorded. Inputs use actual FIFO costs; cost is not taken from a mutable catalog price.

### IP35 `production_output_lines`

`L` to `production_reports`, plus:

| Column | MySQL type | Null | Default | Reference / meaning |
|---|---|---|---|---|
| stock_position_id | BIGINT UNSIGNED | NN | — | FK `stock_positions.id`; output lot and location |
| output_kind | VARCHAR(20) | NN | 'finished' | `finished`, `intermediate`, `byproduct` |
| quantity | DECIMAL(20,6) | NN | — | Accepted canonical output |
| cost_allocation_fraction | DECIMAL(20,10) | NN | — | Share of material/service cost available for good output |
| stock_movement_id | BIGINT UNSIGNED | N | NULL | FK `stock_movements.id`; positive receipt |

Constraints/indexes: inherited line uniqueness; `UNIQUE(stock_movement_id)`; `CHECK(quantity>0)`; `CHECK(cost_allocation_fraction>=0 AND cost_allocation_fraction<=1)`; `CHECK(output_kind IN (...))`; FK indexes. Output fractions total one across positive-cost good outputs after explicitly expensed abnormal loss; zero-cost by-products may have zero share. Co-product allocation policy and exceptions must be approved; do not silently allocate by incomparable meter/piece quantities.

### IP36 `production_wastage_lines`

`L` to `production_reports`, plus:

| Column | MySQL type | Null | Default | Reference / meaning |
|---|---|---|---|---|
| production_material_line_id | BIGINT UNSIGNED | NN | — | FK `production_material_lines.id`; same report |
| waste_class | VARCHAR(20) | NN | — | `normal`, `abnormal` |
| quantity | DECIMAL(20,6) | NN | — | Subset of the issued input, in that input's unit |
| expense_account_id | BIGINT UNSIGNED | N | NULL | FK `ledger_accounts.id`; abnormal waste only |
| expense_amount_pkr | DECIMAL(20,4) | NN | 0 | Actual material cost expensed for abnormal waste |
| reason | VARCHAR(500) | NN | — | Observed loss/defect reason |

Constraints/indexes: inherited line uniqueness; `CHECK(quantity>0 AND expense_amount_pkr>=0)`; `CHECK(waste_class IN (...))`; `CHECK((waste_class='normal' AND expense_account_id IS NULL AND expense_amount_pkr=0) OR (waste_class='abnormal' AND expense_account_id IS NOT NULL))`; FK indexes. Sum wastage per input cannot exceed issued quantity. This table explains input already consumed: **it does not create another negative movement**. Recoverable scraps are output lines/lots and cannot also be classified as unrecoverable waste.

### IP37 `production_cost_allocations`

`L` to `production_reports`, plus:

| Column | MySQL type | Null | Default | Reference / meaning |
|---|---|---|---|---|
| source_journal_line_id | BIGINT UNSIGNED | NN | — | FK `journal_lines.id`; posted eligible actual cost |
| production_output_line_id | BIGINT UNSIGNED | NN | — | FK `production_output_lines.id`; same report |
| cost_category | VARCHAR(30) | NN | — | `direct_labor`, `overhead`, `other_conversion` |
| amount_pkr | DECIMAL(20,4) | NN | — | Actual cost reclassified into this output |
| absorption_account_id | BIGINT UNSIGNED | NN | — | FK `ledger_accounts.id`; authorized expense/clearing account |

Constraints/indexes: inherited line uniqueness; `UNIQUE(document_id,source_journal_line_id,production_output_line_id,cost_category)`; `CHECK(amount_pkr>0)`; `CHECK(cost_category IN (...))`; FK indexes. Source journal lines are locked and net allocations across all documents cannot exceed eligible debit cost after reversals. Payroll/expense recognition and inventory absorption are distinct journal events; absorption credits the relevant expense/clearing account so costs are counted once. Source costs cannot reference this report's own journal. Direct cost additions become value entries on the selected output layers and are included in subsequent cost propagation.

## Packing and finished goods

### IP38 `product_pack_specs`

Standard columns plus:

| Column | MySQL type | Null | Default | Reference / meaning |
|---|---|---|---|---|
| packed_item_id | BIGINT UNSIGNED | NN | — | FK `items.id`; sellable pack SKU, base unit pack |
| version_number | INT UNSIGNED | NN | — | Specification version |
| description | VARCHAR(200) | NN | — | Pack/assortment definition |
| state | VARCHAR(20) | NN | 'draft' | `draft`, `published`, `retired` |
| is_active | BOOL | NN | 1 | Eligible for new packing |

Constraints/indexes: `UNIQUE(packed_item_id,version_number)`; `CHECK(version_number>0)`; `CHECK(state IN (...))`; FK indexes. A pack SKU has its own item; no hidden unit conversion between unequal assortment contents. Published versions are immutable.

### IP39 `product_pack_spec_lines`

Standard columns plus:

| Column | MySQL type | Null | Default | Reference / meaning |
|---|---|---|---|---|
| product_pack_spec_id | BIGINT UNSIGNED | NN | — | FK `product_pack_specs.id` |
| line_no | SMALLINT UNSIGNED | NN | — | Component order |
| component_item_id | BIGINT UNSIGNED | NN | — | FK `items.id`; suit SKU or consumable package |
| component_kind | VARCHAR(20) | NN | — | `saleable_content`, `packaging` |
| quantity_per_pack | DECIMAL(20,6) | NN | — | Canonical component quantity |

Constraints/indexes: `UNIQUE(product_pack_spec_id,line_no)`; `CHECK(line_no>0 AND quantity_per_pack>0)`; `CHECK(component_kind IN (...))`; FK indexes. Publication rejects pack BOM cycles and self-components. Bags, tags and cartons are stock consumables when material enough to track.

### IP40 `packing_orders`

`H` columns plus:

| Column | MySQL type | Null | Default | Reference / meaning |
|---|---|---|---|---|
| product_pack_spec_id | BIGINT UNSIGNED | NN | — | FK `product_pack_specs.id`; published immutable version |
| operation | VARCHAR(20) | NN | 'pack' | `pack`, `unpack` |
| remarks | VARCHAR(1000) | N | NULL | Approved content/variance note |

Constraints/indexes: `CHECK(operation IN (...))`; FK indexes. This is a completed packing conversion document despite the operational name “order”; a draft is the plan. Posting consumes actual input lots and emits output pack lots atomically. Unpacking is a new conversion with actual recoverable contents; it is not an arbitrary reversal after downstream sales.

### IP41 `packing_material_lines`

`L` to `packing_orders`, plus:

| Column | MySQL type | Null | Default | Reference / meaning |
|---|---|---|---|---|
| product_pack_spec_line_id | BIGINT UNSIGNED | N | NULL | FK `product_pack_spec_lines.id`; NULL for source pack on unpack |
| stock_position_id | BIGINT UNSIGNED | NN | — | FK `stock_positions.id` |
| quantity | DECIMAL(20,6) | NN | — | Actual canonical input |
| stock_movement_id | BIGINT UNSIGNED | N | NULL | FK `stock_movements.id`; negative issue |

Constraints/indexes: inherited line uniqueness; `UNIQUE(stock_movement_id)`; `CHECK(quantity>0)`; FK indexes. Packing requires spec-compatible contents. Any loss is explicitly reconciled and expensed through inventory cost links; no quiet difference between input value and output value.

### IP42 `packing_output_lines`

`L` to `packing_orders`, plus:

| Column | MySQL type | Null | Default | Reference / meaning |
|---|---|---|---|---|
| stock_position_id | BIGINT UNSIGNED | NN | — | FK `stock_positions.id`; new pack or recovered component lot |
| quantity | DECIMAL(20,6) | NN | — | Output base quantity |
| cost_allocation_fraction | DECIMAL(20,10) | NN | 1 | Value share; total one for all recovered output |
| stock_movement_id | BIGINT UNSIGNED | N | NULL | FK `stock_movements.id`; positive receipt |

Constraints/indexes: inherited line uniqueness; `UNIQUE(stock_movement_id)`; `CHECK(quantity>0)`; `CHECK(cost_allocation_fraction>=0 AND cost_allocation_fraction<=1)`; FK indexes. Whole-count units reject fractional values according to unit precision. Packing cost is actual constituent FIFO cost including consumed packaging, less separately authorized losses, with deterministic allocation and rounding.

### IP43 `packages`

Standard columns plus:

| Column | MySQL type | Null | Default | Reference / meaning |
|---|---|---|---|---|
| packing_output_line_id | BIGINT UNSIGNED | NN | — | FK `packing_output_lines.id` |
| package_code | VARCHAR(80) | NN | — | Unique barcode/container identity |
| quantity | DECIMAL(20,6) | NN | — | Number of output pack units in this package |
| gross_weight_kg | DECIMAL(12,3) | N | NULL | Shipping evidence |
| sealed_at | DATETIME(6) | N | NULL | Operational event time |

Constraints/indexes: `UNIQUE(package_code)`; `CHECK(quantity>0)`; `CHECK(gross_weight_kg IS NULL OR gross_weight_kg>=0)`; FK indexes. Package data is trace metadata attached to a stock output, **not an additional stock balance**. Package quantities sum to the represented output quantity if full serialization is selected. Initial release supports pack-lot stock movement, not enforced serial-by-serial reservation; package shipment tracking would need an explicit future allocation table before it can be claimed.

### IP44 `package_contents`

Standard columns plus:

| Column | MySQL type | Null | Default | Reference / meaning |
|---|---|---|---|---|
| package_id | BIGINT UNSIGNED | NN | — | FK `packages.id` |
| packing_material_line_id | BIGINT UNSIGNED | NN | — | FK `packing_material_lines.id`; original content provenance |
| quantity | DECIMAL(20,6) | NN | — | Input component quantity attributable to this package |

Constraints/indexes: `UNIQUE(package_id,packing_material_line_id)`; `CHECK(quantity>0)`; FK indexes. Linked package and input line belong to the same packing document. Content totals cannot exceed actual consumed quantities and must cover all saleable content when package-level trace is claimed. There is no residual constituent stock after pack conversion; provenance does not represent ownership twice.

## Posting, custody and valuation invariants

1. **Ownership:** every stock position belongs to the single company. “At vendor” changes custody/location only. Supplier-owned consignment, customer-owned fabric and third-party toll manufacturing are excluded pending an approved owner dimension and accounting policy; they must never be mixed into company on-hand stock.
2. **Atomic posting:** lock document root and verify draft state/version/idempotency key; lock all affected balance rows by ascending position ID; lock FIFO layers in deterministic order; lock any source acceptance/order/reservation aggregates; validate periods/role capability/quality; write movements, allocations, links, value entries, journal and typed line pointers; update projections; mark posted; commit together. No caller may directly append ledger rows.
3. **Absent balances:** create the unique position/balance rows through insert-with-duplicate handling before locking. A missing balance row cannot be protected by a fictional row lock. Retried deadlocks rerun the complete transaction with the same posting key; they do not duplicate ledger rows.
4. **Quantity:** quantities supplied for documents are positive except signed adjustments and compensating entries. Unit conversions use decimal arithmetic and frozen factors; canonical precision and permitted whole-count units are validated before posting. No negative layer, position or unreserved available stock is allowed. Physical returns/reversals select original layers where appropriate.
5. **Physical fabric rolls:** a physical roll may have positive available balance at only one location at a time. A transfer moves its whole remaining roll unless a split produces child identities and lineage. Quarantining a portion requires a physical split. Non-roll stock can span positions by lot.
6. **FIFO:** layers are selected within the issued position in `(fifo_date,receipt_stock_movement_id,id)` order. Picking a physical lot/roll is an explicit traceability choice; FIFO does not override that identity. Cost at issue is each selected layer's current remaining carrying value divided by remaining quantity, using high precision until final PKR rounding. Exhausting a layer takes its exact residual value.
7. **Cost graph:** each negative allocation's carrying value is distributed exactly to descendant layers or expense accounts. Transfers preserve value and original FIFO identity. Processing/production/packing create new product layers; quantity units can differ, while PKR cost must conserve. Never allocate by a sum of incomparable meters, kilograms and pieces.
8. **Late invoices:** match the invoice to accepted receipt/service quantity under lock, release the accrued receipt liability, and calculate only the difference. Distribute that difference to the root layer's surviving quantity and through already consumed proportions in the immutable cost graph to remaining descendant stock or COGS/abnormal loss. Aggregate by target layer/account before writing. Lock the bounded reachable graph and affected balances/layers in deterministic order, reject cycles, and use an explicit operational limit (configurable maximum nodes/time) that fails the whole posting for supervised retry rather than silently skipping descendants. No historical issue or closed-period journal is edited.
9. **Valuation reconciliation:** each document's inventory GL debit minus credit equals its signed inventory value-entry total; physical-only custody movement nets to zero. At every report date, sum inventory value entries by appropriate inventory control account reconciles to GL, and layer carrying values with zero remaining quantity are zero after propagation. An account change applies prospectively through a documented reclassification.
10. **Reversal:** compensating documents reference originals and invert original exact quantity/value allocations, preserving layer provenance. Consumed receipts cannot simply reverse into negative stock. Reverse descendants first or use an explicit current transaction (return, credit, rework, loss or cost adjustment). A reversal is not the same as an ordinary sale/purchase return.
11. **Manufacturing conservation:** input material value + newly absorbed eligible conversion cost = output inventory + abnormal loss expense. Normal waste is included in good-output unit cost. Actual costs already embedded in processed fabric are never capitalized again in the suit batch.
12. **Per-suit costing:** for a single accepted suit output, `(actual input FIFO cost − abnormal loss expense + incremental labor/overhead allocated once) / accepted suit count`. For co-products, first apply approved shares and then divide each output's cost by its own accepted quantity. Packed-suit commercial cost includes actual packaging consumption. Design/BOM cost is a planning estimate; actual layer cost is the accounting source.
13. **Example:** 1,000 meters costing PKR 200,000 and an accepted processing charge of PKR 50,000 yield 950 meters after 50 normal-loss meters: output cost PKR 250,000, approximately PKR 263.15789474/m before posting rounding. If those 950 meters and incremental PKR 30,000 conversion cost produce 100 accepted suits without abnormal loss, actual suit cost is PKR 2,800. A subsequent PKR 5,000 service-price increase follows remaining/sold inventory lineage; it is not all charged to stock when half has already sold.

## Dependency and implementation notes

Create root document, identity, party, item/unit and GL account tables first. Then warehouse/lot/roll/position masters; movement and layer tables; allocation, value and cost-link tables; typed inventory documents; route/BOM/pack versions; jobs and batches; dispatch/receipt/report/packing headers and lines. Cross-domain bridges (`processing_charge_allocations`, production-cost source journal FK) follow purchase matching and journal tables. If a referenced ledger table is created earlier than its typed source, that is intentional: source movements point to the already-existing root document, while the typed line later points to its movement.

Logical ordering of insertions at posting is not migration ordering: a root and typed draft already exist; posting appends movements, then layers/allocations, then value/cost links, then fills protected line pointers inside the same transaction. The absence of a database FK tying a movement's `document_id` to the referencing typed line's `document_id` is explicitly an application posting invariant, verified by tests and reconciliation; do not claim ordinary independent FKs enforce it.

## Review checklist

- [x] Shared parties/vendor roles retained; no independent vendor/customer identity introduced.
- [x] Processing stages/services are configurable and versioned; dyeing meters and PKR/meter are supported.
- [x] Company ownership is independent of contractor custody and transit location.
- [x] Nullable roll uniqueness is normalized; stock dimensions have one authoritative identity.
- [x] Every table has a PK, full column specification, FK deletion policy and index plan.
- [x] Ledger, cache, operational metadata and immutable approved snapshots are distinguished.
- [x] Physical entries and value-only adjustments are separate.
- [x] FIFO layers, cost genealogy, actual reversals, late invoices and closed periods are addressed.
- [x] Normal loss, abnormal loss, recoverable scrap and packing costs are accounted once.
- [x] Draft/posted restrictions and deterministic idempotency exist at the document root.
- [x] Cross-row invariants are assigned to locked posting services rather than impossible CHECKs.
- [x] Deadlocks, missing balance rows, oversubscription and stale physical counts are considered.
- [ ] Confirm company-owned-only stock scope, FIFO policy and current-period late-cost policy.
- [ ] Confirm sequential configurable routes are sufficient for initial workflow.
- [ ] Confirm intermediate-item reporting is sufficient for long-running manufacturing.
- [ ] Confirm package provenance without serial-level dispatch enforcement is sufficient.
- [ ] Implement and exercise MySQL constraints, services and concurrency tests in approved later phases.
