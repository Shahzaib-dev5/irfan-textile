# Future plan: opening balances and go-live for a running company

Recorded: 2026-10-21 (Asia/Karachi)

**Status: planning only; not implemented or approved as a database design.**
The user requested this file to preserve the discussion for future work. This
request does not authorize application code, migrations, imports or postings.
Phase 8 remains the current implementation boundary. A separate explicit request
and schema review are required before implementing this plan.

## 1. Business requirement

The company already operates before adopting the ERP. At the starting date it
may own grey fabric, processed fabric and finished goods, and may have fabric
with dyeing, embroidery or other contractors. It may also owe suppliers/vendors
for earlier work.

Start the ERP from verified opening positions. Do not recreate fictitious
purchase orders, GRNs, dispatches or bills merely to force old balances through
the new-transaction workflow. Preserve the shared party identity and configurable
processing routes; do not create a separate legacy supplier/vendor master.

## 2. Current implementation versus future work

| Area | Current status | Future requirement |
| --- | --- | --- |
| Evidenced opening inventory | Available in Phase 6 | Reuse its posting, valuation and audit safeguards where suitable |
| New processing jobs, dispatches and receipts | Available in Phase 8 | Preserve their behavior |
| Existing fabric at a contractor | Contractor locations exist; dedicated opening job/custody linkage is not implemented | Establish opening outstanding quantities and corresponding cost layers without another dispatch |
| Receiving against opening processing work | Not implemented | Receipts must settle the appropriate opening custody source |
| Historical unpaid supplier/vendor bills | Dedicated opening payable workflow is not implemented | Carry verified obligations into the shared accounting/open-item system |
| Payments and settlements | Not implemented | Separate authorized accounting work must supply settlement functionality |
| Production, packing and sales operations | Outside current scope | Coordinate any future WIP, finished goods or receivable openings with those modules |

An opening quantity at a contractor location alone does not provide the job and
dispatch references required by current processing receipts. Do not use that as
a workaround for opening processing jobs.

## 3. Cutover policy

Choose one business starting date and an exact cutoff for legacy entries. For
example, a 1 November start means the opening snapshot contains balances at the
end of 31 October. Dates are illustrative, not a scheduled go-live.

1. Complete and reconcile legacy entries through the cutoff.
2. Physically count warehouse stock and obtain contractor balance confirmations.
3. Reconcile unpaid bills and previously recognized charges with the accountant.
4. Preview, validate and approve opening records.
5. Post openings once into an open financial year and accounting period.
6. Enter subsequent activity through normal ERP transactions.

Decide how movements during the count/import window are controlled. Either use
a brief entry freeze or an explicitly reconciled bridge register; never record
the same movement in both the opening snapshot and the new ERP flow.

## 4. Opening data to collect

| Group | Required information |
| --- | --- |
| Warehouse stock | Item/SKU, actual processing condition, unit, warehouse/location, lot, physical roll where tracked, quantity, quality and verified carrying value |
| Contractor stock | Shared Vendor role, service/capability, location, current stage, input and expected output items, outstanding input quantity, lot/roll references and carrying value |
| Processing agreement | Legacy job/challan reference and date, vendor, agreed rate/billing unit, evidence of negotiated terms, yield/loss allowance and remaining stages where known |
| Processing history summary | Historical issued, received and lost quantities; outstanding quantity must reconcile to these summaries |
| Charges and billing | Charges already included in stock value, accrued but unbilled charges, previously billed service quantity/value and remaining eligible billing quantity/value |
| Unpaid obligations | Party and applicable role, original invoice reference/date, due date, original amount, payments/credits already applied and verified outstanding PKR amount |
| Evidence | Count sheets, contractor confirmations, challans, invoices, valuation working papers and approver |

Record fabric in its actual condition. Already dyed fabric enters as the
appropriate processed item; it must not be reintroduced as grey fabric and dyed
again. An item master defines identity, not an inventory quantity.

Physical roll identifiers must represent actual rolls. If an old roll was split
before cutover, confirm the resulting physical rolls and retain the legacy
reference; do not represent one physical roll at two locations. Do not invent
unknown roll genealogy, acquisition dates or costs.

## 5. Example: fabric already with a dyeing vendor

At cutoff, the legacy records and contractor confirmation show:

| Detail | Quantity |
| --- | ---: |
| Previously issued | 1,000 M |
| Previously returned | 400 M |
| Loss recorded before cutoff | 0 M |
| Still with contractor | 600 M |

Establish an **opening processing outstanding balance of 600 M**, linked to the
vendor, stage, source references and verified carrying value. The historical
1,000/400 figures are supporting history, not new ERP stock movements.

Any part of the earlier 400 M still physically held by the company belongs in
the warehouse opening snapshot in its actual condition. Do not automatically
open all 400 M if some was subsequently consumed or sold before cutoff.

After go-live, a receipt of 200 M with no loss closes 200 M of opening custody:

`600 M opening outstanding - 200 M source quantity closed = 400 M outstanding`

The received output enters internal storage once. Any normal/abnormal loss must
be separately measured and reconciled. Later receipts settle the same remaining
opening custody. No fake new dispatch is needed.

## 6. Stock, valuation and financial separation

- Contractor-held fabric remains company-owned inventory; location does not
  turn it into a vendor purchase or payable.
- Quantity custody and money owed are separate balances. A vendor may hold
  600 M and also have an unrelated PKR 30,000 unpaid invoice.
- Opening inventory requires ledger movements and supported FIFO/value layers,
  not direct edits to an on-hand quantity or projection.
- Opening custody must settle through its own traceable cost source. It must not
  borrow another job's stock merely because the lot and location are shared.
- Identify which processing costs were already capitalized or accrued before
  cutover. A later receipt or bill must not recognize those same costs again.
- A previously billed service quantity cannot become eligible for billing again
  through an opening job. A later invoice for an opening accrual must clear the
  recognized obligation without capitalizing the charge twice.
- A partially paid legacy invoice must carry its verified remaining obligation;
  decide separately whether historical settlements are imported or retained as
  supporting evidence. Do not represent historical payments as new payments.
- Opening inventory, accruals and AP must reconcile to one approved opening
  accounting schedule. Do not post a full opening trial balance and then add the
  same inventory/payable balances again through operational openings.

Exact accounts, journal mappings, FIFO date policy, billing basis and rounding
rules require an explicit design and accountant acceptance before implementation.

## 7. Proposed user workflow

The following screens/actions are proposals, not existing menu options:

1. **Opening / Go-live setup:** cutoff, opening batch reference and evidence.
2. **Opening warehouse stock:** verified internal stock by item/lot/roll.
3. **Opening contractor work:** vendor, stage, outstanding fabric and frozen terms.
4. **Opening supplier/vendor obligations:** unpaid invoices and recognized accruals.
5. **Preview and reconciliation:** quantities, valuation, obligations and duplicates.
6. **Approve and post:** one controlled, auditable opening operation or explicitly
   linked batches, with balanced accounting and duplicate-posting prevention.
7. **Normal operations:** receive opening custody, continue remaining stages and
   match only eligible future charges. Payment settlement requires its own module.

Opening work may start at any verified processing stage. Do not force a fixed
Dan/Dyeing/Embroidery sequence or replay completed stages. Reuse existing service
and route definitions when they fit the confirmed work.

## 8. Decisions required before code or migrations

- Review the approved architecture and existing opening documents before choosing
  whether to extend them or add dedicated opening custody sources. No table or
  column change is approved by this note.
- Define opening job/stage status and how a receipt identifies its opening source
  without requiring a fabricated dispatch.
- Define incomplete historical rate/reference handling and approval of evidenced
  negotiated terms; do not silently substitute today's vendor rate.
- Define current versus opening cost/accrual/bill eligibility and prevent overlap
  with the opening trial balance and existing live ERP transactions.
- Decide how incomplete routes, legacy roll splits, total-loss-only closures,
  disputed contractor balances and inactive legacy parties are handled.
- Define import identifiers, duplicate detection, preview errors, permissions,
  approval, cutoff adjustments and compensating reversals.
- Review dependency on future Accounting, Production and Sales work. This note
  does not authorize those modules or a general accounting/payroll implementation.
- Explain migration impact and obtain approval before changing approved schema
  decisions. Never reset or delete existing application business data.

## 9. Future validation and acceptance checklist

- [ ] Warehouse counts and contractor confirmations agree with posted openings.
- [ ] The same party can remain Supplier and Vendor without duplicate identities.
- [ ] Already processed stock starts at its actual condition and stage.
- [ ] Historical references and opening carrying values remain traceable.
- [ ] Partial opening-custody receipts leave the correct remaining quantity.
- [ ] Accepted output, rejected returns and loss reconcile to custody closed.
- [ ] Multiple jobs/vendors sharing a location cannot consume one another's layers.
- [ ] Historical billed quantities, capitalized costs and accruals are not duplicated.
- [ ] Opening AP agrees with the approved unpaid-invoice schedule and opening GL.
- [ ] Retry, simultaneous posting and duplicate imports have one persisted effect.
- [ ] Posted openings cannot be edited/deleted; corrections use approved reversals.
- [ ] Existing normal inventory/purchasing/processing tests still pass.
- [ ] DDL, upgrade and lifecycle/concurrency tests pass on target MySQL 8.4.
- [ ] Business owner and accountant sign off before actual opening import.

## 10. References and current delivery

- [Current project state](PROJECT_STATE.md)
- [Inventory operating guide](WAREHOUSES_INVENTORY.md)
- [Processing operating guide](PROCESSING.md)
- [Purchasing operating guide](PURCHASING.md)
- [Approved database decisions](DATABASE_DECISIONS.md)
- [Migration plan](MIGRATION_PLAN.md)

This delivery creates this planning file only. No new tables, migrations, routes,
screens, services or business balances are created. Application tests are not
required for this documentation-only note; verify its references and scope.
Suggested documentation commit: `docs: record future opening balances and go-live plan`.
Ready as a planning reference; future implementation requires a separate request.
