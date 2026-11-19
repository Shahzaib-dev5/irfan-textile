# Direct purchase: item creation and received measurement

Implemented 2026-11-17 within the authorized Inventory/Purchasing follow-up.

## Operator flow

Open Inventory > Direct fabric purchase. Enter supplier, received date and
warehouse. Select an existing fabric/material or Add new item here. Inline entry
asks classification (processed/grey/material/packaging/scrap), name, code and an
optional fabric type such as Cotton lawn. Classification selects the existing
seeded category; no duplicate category/type setup or roll/stock checkbox is needed.
Catalog maintenance permission is required for new identities.

Select the actual stock measurement for each received lot. Grey and processed
fabric support M, PCS, SET and THAAN; other materials can use configured physical
units. Whole count precision is enforced. SET may describe a processed front/back
set and does not automatically create a finished SUIT. Each line needs a lot
number, actual quantity and positive provisional cost.

Optional thaan count, set count and actual total meters describe the same
delivery. They are immutable receipt evidence, not extra inventory or live
secondary balances. Choose the rate basis and quantity. A different rate basis
must reconcile to its entered supplementary total. Example: stock 120 SET,
10 THAAN, 600 M; rate PKR 12,000 per THAAN x 10 = PKR 120,000. The stock receives
120 SET at PKR 1,000 per SET. No global thaan-to-meter or set-to-meter factor is
created. Amounts that cannot reconcile at existing stock-cost precision are
rejected rather than silently rounded into an inconsistent invoice.

Supplier invoice is optional. Without it, Confirm receipt posts the existing GRN
and inventory/GRNI journal only. No supplier payable is fabricated. On the posted
receipt use Create supplier bill when the invoice arrives; existing partial
matching and exact-cost rules apply, without stock replay. Actual payments before
an invoice use existing Finance supplier advances. With an invoice, the same
confirmation posts GRN, matched supplier bill and optional allocated payment.

## Data and posting

Migration `2026_11_17_000001_add_direct_purchase_measurements` adds:

- `textile_definitions`: shared code/name/classification/category/fabric type.
- Nullable `items.textile_definition_id`, with a unique definition/unit pair.
- `receipt_measurements`: one original measurement/rate snapshot per GRN line.
- `direct_purchase_confirmations`: complete-request hash and receipt/bill/payment
  references for idempotent repeat submission.
- The count unit THAAN if missing; nullable fabric composition for simple purchase
  setup. Optional composition is never fabricated from fabric type.

One definition can own separate internal Item identities for M, PCS, SET or
THAAN. Direct purchase displays the definition once; inventory and operational
screens retain distinct unit identities so existing FIFO, journals, returns,
transfers and sales cannot mix dimensions. Existing item IDs, base units, lots,
posted snapshots and valuations are unchanged. Old items join a definition only
when an additional unit is first purchased. New measurement identities require
Catalog permission; existing permitted identities can be reused by receipt staff.
Processing-generated output identities do not inherit the purchase definition.

The full flow runs under the company lock in one database transaction. Invalid
receipt/payment/measurement rolls back new definitions, items, stock and journals.
Replays require identical payloads. Permissions are checked before replay too.
Invoice posting permission is required only if an invoice is supplied. Existing
Wholesale separation remains unchanged. No new route or independent balance.

## Files and limits

Implementation: PurchaseMeasurement, DirectFabricPurchase, its Form Request,
InventoryController, direct-fabric-purchase Blade and direct-purchase.js; Item and
TextileDefinition models, Catalog validation/configuration and FabricFlow output
replication; receipt-measurements component on the Purchasing receipt page.

This follow-up does not implement automatic M-to-PCS Processing conversion,
per-thaan packing allocation, simultaneous remaining meters/sets balances, or
free-form units. Stock movement uses the primary selected unit; secondary totals
remain clearly labelled original received evidence. Advanced Catalog creation
remains available. Supplier bills retain the established exact-cost restriction.

Local deployment: a private SQL backup was taken before applying the additive
migration (`storage/app/private/before-purchase-measurements-20261117.sql`, 329971
bytes). No live goods receipt, bill or demo stock was created. Build assets and
clear application caches after deploying; migration down does not remove THAAN
or force populated optional compositions back to NOT NULL. Rollback of the new
tables requires a backup and will lose their metadata; do not roll it back on a
used production database without a recovery plan.

Verification is recorded in TESTING.md. Suggested commit:
`feat(inventory): simplify direct purchases with flexible item measurements`.
