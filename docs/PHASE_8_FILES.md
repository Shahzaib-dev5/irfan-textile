# Phase 8 file manifest

The 2026-10-27 follow-up file inventory and activation results are in
[PROCESSING_FABRIC_FLOW.md](PROCESSING_FABRIC_FLOW.md). The list below records the
original Phase 8 implementation and earlier UX work.


The following files were created or modified for Processing and Contractor
Management. No Git repository was initialized; this manifest describes workspace
changes, not a Git diff. Public build outputs and disposable test/cache/log files
are generated artifacts and are excluded from the source list.

## Created

- [app/Models/FabricRollLineage.php](../app/Models/FabricRollLineage.php)
- [app/Models/ProcessingDispatch.php](../app/Models/ProcessingDispatch.php)
- [app/Models/ProcessingDispatchLine.php](../app/Models/ProcessingDispatchLine.php)
- [app/Models/ProcessingJob.php](../app/Models/ProcessingJob.php)
- [app/Models/ProcessingJobStep.php](../app/Models/ProcessingJobStep.php)
- [app/Models/ProcessingReceipt.php](../app/Models/ProcessingReceipt.php)
- [app/Models/ProcessingReceiptLine.php](../app/Models/ProcessingReceiptLine.php)
- [app/Models/ProcessingRouteStep.php](../app/Models/ProcessingRouteStep.php)
- [app/Models/ProcessingRouteVersion.php](../app/Models/ProcessingRouteVersion.php)
- [app/Models/PurchaseInvoiceProcessingMatch.php](../app/Models/PurchaseInvoiceProcessingMatch.php)
- [app/Modules/Processing/Http/Controllers/ProcessingController.php](../app/Modules/Processing/Http/Controllers/ProcessingController.php)
- [app/Modules/Processing/Http/Requests/ProcessingActionRequest.php](../app/Modules/Processing/Http/Requests/ProcessingActionRequest.php)
- [app/Modules/Processing/Http/Requests/ProcessingRequest.php](../app/Modules/Processing/Http/Requests/ProcessingRequest.php)
- [app/Modules/Processing/Resources/views/custody.blade.php](../app/Modules/Processing/Resources/views/custody.blade.php)
- [app/Modules/Processing/Resources/views/form.blade.php](../app/Modules/Processing/Resources/views/form.blade.php)
- [app/Modules/Processing/Resources/views/index.blade.php](../app/Modules/Processing/Resources/views/index.blade.php)
- [app/Modules/Processing/Resources/views/line.blade.php](../app/Modules/Processing/Resources/views/line.blade.php)
- [app/Modules/Processing/Resources/views/nav.blade.php](../app/Modules/Processing/Resources/views/nav.blade.php)
- [app/Modules/Processing/Resources/views/reports.blade.php](../app/Modules/Processing/Resources/views/reports.blade.php)
- [app/Modules/Processing/Resources/views/route-step.blade.php](../app/Modules/Processing/Resources/views/route-step.blade.php)
- [app/Modules/Processing/Resources/views/routes.blade.php](../app/Modules/Processing/Resources/views/routes.blade.php)
- [app/Modules/Processing/Resources/views/show.blade.php](../app/Modules/Processing/Resources/views/show.blade.php)
- [app/Modules/Processing/Services/ProcessingRoutes.php](../app/Modules/Processing/Services/ProcessingRoutes.php)
- [app/Modules/Processing/Services/ProcessingService.php](../app/Modules/Processing/Services/ProcessingService.php)
- [app/Policies/ProcessingPolicy.php](../app/Policies/ProcessingPolicy.php)
- [database/factories/ProcessingRouteVersionFactory.php](../database/factories/ProcessingRouteVersionFactory.php)
- [database/migrations/2026_10_21_000004_create_processing_tables.php](../database/migrations/2026_10_21_000004_create_processing_tables.php)
- [database/seeders/ProcessingConfigurationSeeder.php](../database/seeders/ProcessingConfigurationSeeder.php)
- [docs/PHASE_8_FILES.md](../docs/PHASE_8_FILES.md)
- [docs/PROCESSING.md](../docs/PROCESSING.md)
- [tests/Feature/ProcessingConcurrencyTest.php](../tests/Feature/ProcessingConcurrencyTest.php)
- [tests/Feature/ProcessingRoutesTest.php](../tests/Feature/ProcessingRoutesTest.php)
- [tests/Feature/ProcessingTest.php](../tests/Feature/ProcessingTest.php)
- [tests/Support/ProcessingFixtures.php](../tests/Support/ProcessingFixtures.php)

## Modified

- [docs/database/FINANCE_ERD.md](../docs/database/FINANCE_ERD.md)
- [AGENTS.md](../AGENTS.md)
- [README.md](../README.md)
- [app/Modules/Accounting/Services/FinancialCalendarService.php](../app/Modules/Accounting/Services/FinancialCalendarService.php)
- [app/Modules/Accounting/Services/SourceJournal.php](../app/Modules/Accounting/Services/SourceJournal.php)
- [app/Modules/Core/Resources/views/layouts/app.blade.php](../app/Modules/Core/Resources/views/layouts/app.blade.php)
- [app/Modules/Core/Services/AuditLogger.php](../app/Modules/Core/Services/AuditLogger.php)
- [app/Modules/Inventory/Resources/views/movements.blade.php](../app/Modules/Inventory/Resources/views/movements.blade.php)
- [app/Modules/Inventory/Services/StockLedger.php](../app/Modules/Inventory/Services/StockLedger.php)
- [app/Modules/Parties/Services/PartyManagementService.php](../app/Modules/Parties/Services/PartyManagementService.php)
- [app/Modules/Processing/Routes/web.php](../app/Modules/Processing/Routes/web.php)
- [app/Modules/Purchasing/Http/Controllers/PurchasingController.php](../app/Modules/Purchasing/Http/Controllers/PurchasingController.php)
- [app/Modules/Purchasing/Services/PurchaseDocuments.php](../app/Modules/Purchasing/Services/PurchaseDocuments.php)
- [app/Modules/Purchasing/Services/PurchasingService.php](../app/Modules/Purchasing/Services/PurchasingService.php)
- [app/Modules/Warehouses/Http/Controllers/WarehouseController.php](../app/Modules/Warehouses/Http/Controllers/WarehouseController.php)
- [app/Providers/AppServiceProvider.php](../app/Providers/AppServiceProvider.php)
- [config/erp_permissions.php](../config/erp_permissions.php)
- [database/migrations/2026_10_21_000002_create_warehouse_and_inventory_tables.php](../database/migrations/2026_10_21_000002_create_warehouse_and_inventory_tables.php)
- [database/seeders/DatabaseSeeder.php](../database/seeders/DatabaseSeeder.php)
- [docs/ARCHITECTURE.md](../docs/ARCHITECTURE.md)
- [docs/BUSINESS_RULES.md](../docs/BUSINESS_RULES.md)
- [docs/CHANGELOG.md](../docs/CHANGELOG.md)
- [docs/DATABASE_DECISIONS.md](../docs/DATABASE_DECISIONS.md)
- [docs/DATABASE_REVIEW.md](../docs/DATABASE_REVIEW.md)
- [docs/DATABASE_SCHEMA.md](../docs/DATABASE_SCHEMA.md)
- [docs/DEPLOYMENT.md](../docs/DEPLOYMENT.md)
- [docs/DEVELOPMENT_PHASES.md](../docs/DEVELOPMENT_PHASES.md)
- [docs/ERD.md](../docs/ERD.md)
- [docs/MIGRATION_PLAN.md](../docs/MIGRATION_PLAN.md)
- [docs/PRD.md](../docs/PRD.md)
- [docs/PROJECT_OVERVIEW.md](../docs/PROJECT_OVERVIEW.md)
- [docs/PROJECT_STATE.md](../docs/PROJECT_STATE.md)
- [docs/PURCHASING.md](../docs/PURCHASING.md)
- [docs/TESTING.md](../docs/TESTING.md)
- [docs/TEXTILE_MASTER_DATA.md](../docs/TEXTILE_MASTER_DATA.md)
- [docs/WAREHOUSES_INVENTORY.md](../docs/WAREHOUSES_INVENTORY.md)
- [docs/database/COMMERCIAL_FINANCE.md](../docs/database/COMMERCIAL_FINANCE.md)
- [docs/database/CORE_CATALOG.md](../docs/database/CORE_CATALOG.md)
- [docs/database/INVENTORY_PRODUCTION.md](../docs/database/INVENTORY_PRODUCTION.md)
- [tests/Feature/PurchasingConcurrencyTest.php](../tests/Feature/PurchasingConcurrencyTest.php)
- [tests/Support/inventory_worker.php](../tests/Support/inventory_worker.php)

Formatter-only changes in earlier functionality include SourceJournal,
PurchaseDocuments, PurchasingService, WarehouseController, the Phase 6 migration
and PurchasingConcurrencyTest. No prior migration SQL semantics changed. Other
modified integrations implement the Processing permissions, calendars, audit,
custody protection, master-rate safety, source navigation and stock-only price
history filtering described in PROJECT_STATE.md. No .env, credentials, Composer
or npm dependency manifests were changed. Targeted application database migrations
were not executed; development tests use the explicitly isolated test database.

## Guided workspace follow-up (2026-10-22)

See [PROCESSING_UX.md](PROCESSING_UX.md#2-files-created-and-modified) for the
new read service, sidebar, focused screens, Alpine previews, tests and modified
controllers/routes/views. No migrations or packages are added.
