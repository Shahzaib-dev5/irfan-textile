# Physical suit stock follow-up — 2026-10-28

User-authorized simplification of daily Production/Finished Goods entry and
Wholesale client history. Tax, payroll and statutory filing remain outside scope.

## Operator flow

Processing → Processed fabric inventory → Physical Suit Stock → Add ready suits
→ Wholesale order/dispatch/invoice → Finance receipt and settlement.

Physical Suit Stock lists article/SKU, name, design, color, suit configuration,
batch/lot, Vol reference, warehouse/location, quality, on-hand, reserved,
available and carrying value. Article definitions still belong to Catalog.
Vol reference uses the existing lot external reference; it is not a new master.
Batch number is a unique stock lot; warehouse transfers preserve that identity.
Source-history links reuse existing layer traceability and processing job history.

Stock entry has two evidenced sources:

* Existing suits at system opening: actual whole suits, supported positive cost
  per suit, signed schedule reference and notes. Existing Inventory opening
  posting adds stock and credits opening equity. Never use this for goods already
  represented in the ERP or as a daily manufacturing shortcut.
* New suits made from our material: select actual source lots and consumed
  quantities, finished article, actual suit count and destination. One transaction
  creates an actual recipe, confirmed batch and completed output report through
  existing Production services. Full-batch quantities avoid per-suit rounding.
  Source FIFO cost (including recorded processing charges) transfers to output.

No manual editable balance or cost-free daily stock creation is introduced.
Complex WIP, separately measured wastage, recoverable scrap, added conversion
costs and packing remain under Advanced Manufacturing. The simple conversion
records all entered material as consumed into the finished output and does not
claim separate wastage reporting or a pre-approved standard recipe.
Purchased finished suits should use Purchasing; physical count corrections and
damage use the linked Inventory workflow. Transfers use existing warehouse moves.
Pending/held/rejected stock needs appropriate quality release before sales.

## Stock and client ledgers

Stock pages derive quantity/reservations/value from existing ledgers. Existing
Sales dispatch reduces the same stock and records COGS/revenue; invoices establish
AR, and Finance receipts/credits/reversals feed the same client ledger. The new
Sales customer history includes dated deliveries (article, batch, warehouse,
quantity and sale amount), debit/credit entries and net billed balance filtered
to this customer role and receivable accounts. Credit exposure remains separately
labelled because it also includes unbilled deliveries and order commitments.
Payment allocation does not post a second receipt. Finance permissions remain
separate; Wholesale can see its customer history, not general Finance controls.

## Files and routes

New Inventory PhysicalSuitStock service, PhysicalSuitRequest, PhysicalSuitController,
physical/index and physical/create Blade views, PhysicalSuitStockTest.
Changed Inventory routes/inquiry, core layout, Processing navigation, Sales
workspace/history/show views and FinanceSalesIntegrationTest.

* GET `/physical-stock`: warehouse suit/pack balances.
* GET `/physical-stock/processed-fabric`: processed fabric balances/history.
* GET `/physical-stock/create`: simple ready-suit entry.
* POST `/physical-stock`: atomic confirm.
* Existing `/sales/customer-history/{customer}`: delivery and client ledger.

Existing inventory view/manage/post and production manage/confirm permissions
are enforced at routes and posting services. No new role grants or passwords.
UUID-derived child keys, input hashes and company locks preserve retry safety.
Failed conversion rolls back recipe, batch, output, stock, cost and audit together.

## Schema, deployment and verification

No new tables or migrations. No application business data, opening balances or
transactions are seeded. Existing stock, production, journal and open-item tables
remain authoritative. Deploy PHP/Blade changes and rebuild CSS assets; clear
route/view caches if previously cached. Do not run migrate:fresh on the app DB.

The Sales invoice error (attempt to read lot on null) was caused by Blade
directives touching plain text: invoice rendering continued into return-only
fields. Separate directive boundaries fix this without altering stock history.

Verification results are recorded in TESTING.md. The first attempt failed during
isolated test-schema setup because C: was full, before any assertions. The empty
new test file was restored after space was freed. No application migration ran.

Suggested commit: `feat: simplify physical suit stock and expose customer ledger`.
Final checks: 22 tests / 102 assertions passed; Vite build (69 modules), four-route
registration, Blade compilation and 167 compiled PHP view syntax checks passed.
Ready for operator review. Interactive browser acceptance and exact MySQL 8.4
verification remain pending. No Git repository was present, so no commit was made.
