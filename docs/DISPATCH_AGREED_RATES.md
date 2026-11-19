# Rates agreed during Fabric Out

Updated 2026-11-18: dispatch rates are now **optional**. Blank records rate pending;
explicit zero records a free service. Vendor/service changes clear the entered
rate; date changes preserve it. No published vendor contract is required or created.
The service supplies the initial billing unit. PCS embroidery is also available.

Fabric In records the actual rate and billing unit, required when the dispatch
rate was pending. Each receipt freezes its own pricing for accrual and matched
bills. Existing receipts fall back to their original frozen job rate; historical
contracts remain unchanged. Advanced published-contract jobs retain their existing
controls. The current additive migration, cutting flow, checks and limits are in
[FABRIC_CONVERSION.md](FABRIC_CONVERSION.md).

## Database and files

Migration `2026_11_17_000002_allow_dispatch_agreed_processing_rates` makes
`processing_job_steps.vendor_service_rate_id` nullable, preserving its FK.
Existing references stay intact. No new table or route. Rollback is blocked if
dispatch-agreed steps exist. Local migration applied after private backup
`storage/app/private/before-dispatch-rates-20261118.sql` (345995 bytes).

Files: FabricFlowRequest, FabricFlowService, ProcessingService,
FabricFlowController, fabric-vendor Blade, vendor list/edit/rates guidance,
ProcessingFabricFlowTest and fabric-browser.mjs. SimpleProcessingFlowTest's stale
draft-print wording assertion was updated to the existing print warning.

Verification is in TESTING.md. No live dispatch/receipt/bill was created for
testing. Full-suite, exact MySQL 8.4 and concurrent-worker validation remain
outside the performed checks. Suggested commit:
`feat(processing): agree vendor rates on each fabric dispatch`.
