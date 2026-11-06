@php
$user = auth()->user();
$modules = [
    'catalog' => ['Textile', 'catalog.index', ['master' => 'items'], 'catalog.masters.view'],
    'inventory' => ['Inventory', 'inventory.index', [], 'inventory.view'],
    'warehouses' => ['Warehouses', 'warehouses.index', [], 'warehouses.view'],
    'parties' => ['Parties', 'parties.index', [], null],
    'purchasing' => ['Purchasing', 'purchasing.orders.index', [], 'purchasing.documents.view'],
    'processing' => ['Processing', 'processing.jobs.index', [], 'processing.documents.view'],
    'production' => ['Production', 'production.batches.index', [], 'production.view'],
    'finished-goods' => ['Finished goods', 'finished-goods.index', [], 'finished-goods.view'],
    'sales' => ['Sales', 'sales.index', [], 'sales.documents.view'],
    'finance' => ['Finance', 'finance.index', [], 'accounting.finance.view'],
];
$patterns = [
    'catalog' => 'catalog.*', 'inventory' => 'inventory.*', 'warehouses' => 'warehouses.*',
    'parties' => 'parties.*', 'purchasing' => 'purchasing.*', 'processing' => 'processing.*',
    'production' => 'production.*', 'finished-goods' => 'finished-goods.*', 'sales' => 'sales.*', 'finance' => 'finance.*',
];
$activeModule = null;
foreach ($patterns as $key => $pattern) {
    if (request()->routeIs($pattern)) { $activeModule = $key; break; }
}
if ($activeModule === null && request()->routeIs('physical-stock.*')) $activeModule = 'inventory';
$canSeeParties = $user->hasPermissionTo('purchasing.supplier.view') || $user->hasPermissionTo('processing.vendor.view') || $user->hasPermissionTo('sales.customer.view');
$subtabs = match ($activeModule) {
    'catalog' => array_merge(collect(App\Modules\Catalog\Services\CatalogWorkspace::SCREENS)->map(fn ($screen, $key) => [$screen['title'], 'catalog.index', ['master' => $key], 'catalog.index', 'catalog.masters.view'])->values()->all(), [['Collections / seasons', 'catalog.collections', [], 'catalog.collections', 'catalog.masters.view']]),
    'inventory' => [
        ['Stock', 'inventory.index', [], 'inventory.index', 'inventory.view'], ['Stock documents', 'inventory.documents.index', [], 'inventory.documents.index', 'inventory.view'],
        ['Stock ledger', 'inventory.movements', [], 'inventory.movements', 'inventory.view'], ['Reconciliation', 'inventory.reconciliation', [], 'inventory.reconciliation', 'inventory.view'],
        ['Physical suits', 'physical-stock.index', [], 'physical-stock.index', 'inventory.view'], ['Processed fabric', 'physical-stock.fabric', [], 'physical-stock.fabric', 'inventory.view'],
        ['Add ready suits', 'physical-stock.create', [], 'physical-stock.create', 'inventory.manage'],
    ],
    'warehouses' => [['Warehouse list', 'warehouses.index', [], 'warehouses.index', 'warehouses.view'], ['Add warehouse', 'warehouses.create', [], 'warehouses.create', 'warehouses.manage']],
    'parties' => [['Party directory', 'parties.index', [], 'parties.index', null]],
    'purchasing' => array_merge(collect(App\Modules\Purchasing\Services\PurchasingWorkspace::PAGES)->map(fn ($page) => [$page['title'], 'purchasing.'.$page['path'].'.index', [], 'purchasing.'.$page['path'].'.index', 'purchasing.documents.view'])->values()->all(), [['Payables', 'purchasing.payables', [], 'purchasing.payables', 'purchasing.documents.view'], ['Prices & history', 'purchasing.history', [], 'purchasing.history', 'purchasing.documents.view']]),
    'processing' => [
        ['Jobs', 'processing.jobs.index', [], 'processing.jobs.index', 'processing.documents.view'], ['Fabric Out', 'processing.fabric.out', [], 'processing.fabric.out', 'processing.documents.manage'],
        ['Fabric In', 'processing.fabric.in', [], 'processing.fabric.in', 'processing.documents.manage'], ['Dispatch history', 'processing.dispatches.index', [], 'processing.dispatches.index', 'processing.documents.view'],
        ['Receipt history', 'processing.receipts.index', [], 'processing.receipts.index', 'processing.documents.view'], ['Stock with vendors', 'processing.custody', [], 'processing.custody', 'processing.documents.view'],
        ['Bills', 'processing.bills.index', [], 'processing.bills.index', 'processing.documents.view'], ['Service types', 'processing.services.index', [], 'processing.services.index', 'processing.documents.view'],
        ['Reports', 'processing.reports', [], 'processing.reports', 'processing.documents.view'], ['Payables', 'processing.payables', [], 'processing.payables', 'processing.documents.view'],
    ],
    'production' => [['Orders / batches', 'production.batches.index', [], 'production.batches.index', 'production.view'], ['Recipes', 'production.boms', [], 'production.boms', 'production.view'], ['Reports', 'production.reports.index', [], 'production.reports.index', 'production.view'], ['WIP progress', 'production.progress', [], 'production.progress', 'production.view']],
    'finished-goods' => [['Packing records', 'finished-goods.index', [], 'finished-goods.index', 'finished-goods.view'], ['Specifications', 'finished-goods.specifications', [], 'finished-goods.specifications', 'finished-goods.view'], ['Batches & costing', 'finished-goods.batches', [], 'finished-goods.batches', 'finished-goods.view'], ['Stock & quality', 'finished-goods.inventory', [], 'finished-goods.inventory', 'finished-goods.view']],
    'sales' => array_merge(collect(['order' => 'Orders', 'dispatch' => 'Dispatch', 'invoice' => 'Invoices', 'return' => 'Returns', 'credit' => 'Credit notes'])->map(fn ($label, $kind) => [$label, 'sales.index', ['kind' => $kind], 'sales.index', 'sales.documents.view'])->values()->all(), [['Availability', 'sales.availability', [], 'sales.availability', 'sales.documents.view']]),
    'finance' => [
        ['Journals', 'finance.index', [], 'finance.index', 'accounting.finance.view'], ['Payments', 'finance.create', ['kind' => 'payment'], 'finance.create', 'accounting.finance.post'],
        ['Receipts', 'finance.create', ['kind' => 'receipt'], 'finance.create', 'accounting.finance.post'], ['Accounts & cash/bank', 'finance.accounts', [], 'finance.accounts', 'accounting.finance.view'],
        ['Open items', 'finance.report', ['report' => 'open'], 'finance.report', 'accounting.finance.view'], ['Statements', 'finance.report', [], 'finance.report', 'accounting.finance.view'],
        ['Bank reconciliation', 'finance.bank', [], 'finance.bank', 'accounting.finance.reconcile'],
    ],
    default => [],
};
$isSubtabActive = function (string $routeName, array $parameters) {
    if (! request()->routeIs($routeName)) return false;
    foreach (['master', 'kind', 'report'] as $key) {
        if (array_key_exists($key, $parameters) && request()->route($key) !== null && (string) request()->route($key) !== (string) $parameters[$key]) return false;
        if ($key === 'master' && array_key_exists($key, $parameters) && request()->route('master') !== (string) $parameters[$key]) return false;
        if (in_array($key, ['kind', 'report'], true) && array_key_exists($key, $parameters) && request()->query($key) !== (string) $parameters[$key]) return false;
        if (in_array($key, ['kind', 'report'], true) && ! array_key_exists($key, $parameters) && request()->query($key)) return false;
    }
    return true;
};
@endphp
<nav aria-label="Modules" class="border-b border-line bg-white px-4 sm:px-7">
    <div class="flex gap-1 overflow-x-auto py-2">
        @foreach($modules as $key => [$label, $route, $parameters, $permission])
            @if(($permission && $user->hasPermissionTo($permission)) || ($key === 'parties' && $canSeeParties))
                <a href="{{ route($route, $parameters) }}" @if($activeModule === $key) aria-current="page" @endif class="whitespace-nowrap rounded-lg px-3 py-2 text-sm {{ $activeModule === $key ? 'bg-emerald-50 font-semibold text-brand' : 'text-slate-600 hover:bg-slate-50' }}">{{ $label }}</a>
            @endif
        @endforeach
    </div>
</nav>
@if($subtabs)
<nav aria-label="{{ $modules[$activeModule][0] }} sections" class="border-b border-line bg-slate-50 px-4 sm:px-7">
    <div class="flex gap-1 overflow-x-auto py-1.5">
        @foreach($subtabs as [$label, $route, $parameters, $routeName, $permission])
            @if(!$permission || $user->hasPermissionTo($permission))
                @php($active = $isSubtabActive($routeName, $parameters))
                <a href="{{ route($route, $parameters) }}" @if($active) aria-current="page" @endif class="whitespace-nowrap rounded-md px-3 py-1.5 text-xs {{ $active ? 'bg-white font-semibold text-brand shadow-sm' : 'text-slate-600 hover:bg-white' }}">{{ $label }}</a>
            @endif
        @endforeach
    </div>
</nav>
@endif
