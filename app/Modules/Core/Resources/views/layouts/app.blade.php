<!doctype html>
<html lang="en" @if(request()->routeIs('catalog.index')) class="catalog-list-screen" @endif>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <script>try { if (localStorage.getItem('erpTheme') === 'dark') document.documentElement.classList.add('dark-mode'); if (window.matchMedia('(min-width: 1024px)').matches && localStorage.getItem('erpSidebarCollapsed') === 'true') document.documentElement.classList.add('sidebar-collapsed'); } catch (_) {}</script>
    <title>@yield('title', 'Textile ERP') · Textile ERP</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-canvas text-ink antialiased" x-data="{ sidebarOpen: false, sidebarCollapsed: false, darkMode: false, init() { try { this.sidebarCollapsed = localStorage.getItem('erpSidebarCollapsed') === 'true'; this.darkMode = localStorage.getItem('erpTheme') === 'dark' } catch (_) {} }, toggleTheme() { this.darkMode = !this.darkMode; document.documentElement.classList.toggle('dark-mode', this.darkMode); try { localStorage.setItem('erpTheme', this.darkMode ? 'dark' : 'light') } catch (_) {} }, toggleSidebar() { if (window.matchMedia('(min-width: 1024px)').matches) { this.sidebarCollapsed = !this.sidebarCollapsed; localStorage.setItem('erpSidebarCollapsed', this.sidebarCollapsed ? 'true' : 'false'); document.documentElement.classList.toggle('sidebar-collapsed', this.sidebarCollapsed) } else { this.sidebarOpen = !this.sidebarOpen } } }">
    <div class="min-h-screen lg:flex">
        <div x-cloak x-show="sidebarOpen" x-transition.opacity class="fixed inset-0 z-30 bg-slate-950/40 lg:hidden" @click="sidebarOpen = false"></div>
        <aside class="fixed inset-y-0 left-0 z-40 flex w-72 -translate-x-full flex-col border-r border-line bg-white transition-transform lg:sticky lg:top-0 lg:h-screen lg:shrink-0 lg:translate-x-0" :class="[(sidebarOpen && 'translate-x-0'), (sidebarCollapsed && 'lg:hidden')]">
            <a href="{{ route('dashboard') }}" class="flex h-20 items-center gap-3 border-b border-line px-6">
                <x-erp.brand-mark />
                <span><span class="block text-sm font-semibold tracking-tight">Textile ERP</span><span class="block text-xs text-slate-500">Manufacturing & wholesale</span></span>
            </a>
            <nav class="flex-1 space-y-1 overflow-y-auto px-3 py-5" aria-label="Main navigation">
                <a href="{{ route('dashboard') }}" class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm {{ request()->routeIs('dashboard')?'bg-emerald-50 font-semibold text-brand':'font-medium text-slate-600 hover:bg-slate-50' }}">Dashboard</a>
                @if(auth()->user()?->hasPermissionTo('inventory.view'))<a href="{{ route('inventory.index') }}" class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm {{ request()->routeIs('inventory.*')?'bg-emerald-50 font-semibold text-brand':'text-slate-600 hover:bg-slate-50' }}">Inventory</a>@endif
                @if(auth()->user()?->hasPermissionTo('warehouses.view'))<a href="{{ route('warehouses.index') }}" class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm {{ request()->routeIs('warehouses.*')?'bg-emerald-50 font-semibold text-brand':'text-slate-600 hover:bg-slate-50' }}">Warehouses</a>@endif
                @if(auth()->user()?->hasPermissionTo('purchasing.supplier.view') || auth()->user()?->hasPermissionTo('processing.vendor.view') || auth()->user()?->hasPermissionTo('sales.customer.view'))<a href="{{ route('parties.index') }}" class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm {{ request()->routeIs('parties.*')?'bg-emerald-50 font-semibold text-brand':'text-slate-600 hover:bg-slate-50' }}">Parties</a>@endif
                <p class="px-3 pb-2 text-[10px] font-semibold uppercase tracking-[0.16em] text-slate-400">Modules</p>
                <x-erp.catalog-navigation />
                <x-erp.purchasing-navigation :active-kind="$kind ?? null" />
                <x-erp.processing-navigation :active-kind="$kind ?? null" />
                <x-erp.physical-stock-navigation />
                @if(auth()->user()?->hasPermissionTo('production.view') || auth()->user()?->hasPermissionTo('finished-goods.view'))
                <details @if(request()->is('production*','finished-goods*')) open @endif class="rounded-lg border border-line p-2"><summary class="cursor-pointer px-1 py-2 text-sm text-slate-600">Advanced Manufacturing</summary><x-erp.production-navigation /><x-erp.finished-goods-navigation /></details>
                @endif
                <x-erp.sales-navigation />
                <x-erp.finance-navigation />
                @foreach ([ 'HR & Payroll', 'Reporting'] as $module)
                    <span class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm text-slate-500"><span class="w-5 text-center text-slate-300">·</span>{{ $module }}<span class="ml-auto rounded bg-slate-100 px-1.5 py-0.5 text-[10px] text-slate-400">Soon</span></span>
                @endforeach
                <p class="px-3 pb-2 pt-7 text-[10px] font-semibold uppercase tracking-[0.16em] text-slate-400">Administration</p>
                @if(auth()->user()?->hasPermissionTo('identity.users.view'))<a href="{{ route('identity.users.index') }}" class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm text-slate-600 hover:bg-slate-50">Users</a>@endif
                @if(auth()->user()?->hasPermissionTo('identity.roles.view'))<a href="{{ route('identity.roles.index') }}" class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm text-slate-600 hover:bg-slate-50">Roles</a>@endif
                @if(auth()->user()?->hasPermissionTo('identity.permissions.view'))<a href="{{ route('identity.permissions.index') }}" class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm text-slate-600 hover:bg-slate-50">Permissions</a>@endif
                @if(auth()->user()?->hasPermissionTo('core.company.view'))<a href="{{ route('core.company.edit') }}" class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm text-slate-600 hover:bg-slate-50">Company settings</a>@endif
                @if(auth()->user()?->hasPermissionTo('accounting.calendar.view'))<a href="{{ route('accounting.calendar.index') }}" class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm text-slate-600 hover:bg-slate-50">Financial calendar</a>@endif
            </nav>
            <div class="border-t border-line px-5 py-4 text-xs text-slate-500">Single company · PKR</div>
        </aside>
        <div class="flex min-h-screen min-w-0 flex-1 flex-col">
            <header class="sticky top-0 z-20 flex h-16 items-center justify-between border-b border-line bg-white/95 px-4 backdrop-blur sm:px-7">
                <div class="flex items-center gap-3"><button type="button" class="rounded-lg p-2 text-slate-600 hover:bg-slate-100" @click="toggleSidebar()" :aria-expanded="window.matchMedia('(min-width: 1024px)').matches ? !sidebarCollapsed : sidebarOpen" aria-label="Toggle navigation" title="Toggle sidebar"><svg aria-hidden="true" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" d="M4 6h16M4 12h16M4 18h16" /></svg></button><span class="text-sm text-slate-500">@yield('breadcrumb', 'Workspace')</span></div>
                <div class="flex items-center gap-2 sm:gap-4">
                    <button type="button" class="rounded-lg border border-line px-3 py-2 text-xs font-medium text-slate-600 hover:bg-slate-50" @click="toggleTheme()" :aria-pressed="darkMode" x-text="darkMode ? 'Light mode' : 'Dark mode'">Dark mode</button>
                    <span class="hidden text-xs text-slate-500 sm:inline">{{ now()->timezone('Asia/Karachi')->format('D, d M Y') }}</span>
                    @auth
                        <div class="hidden text-right sm:block"><div class="text-sm font-medium">{{ auth()->user()->name }}</div><div class="text-xs text-slate-500">Signed in</div></div>
                        <form method="POST" action="{{ route('logout') }}">@csrf<button class="rounded-lg border border-line px-3 py-2 text-xs font-medium hover:bg-slate-50">Sign out</button></form>
                    @else
                        <a href="{{ route('login') }}" class="rounded-lg border border-line px-3 py-2 text-xs font-medium hover:bg-slate-50">Sign in</a>
                    @endauth
                </div>
            </header>
            @auth
                <x-erp.module-tabs />
            @endauth
            <main class="w-full flex-1 px-4 py-6 sm:px-7 sm:py-8">
                <x-erp.flash-messages />
                @yield('content')
            </main>
            <footer class="border-t border-line px-4 py-4 text-xs text-slate-400 sm:px-7">Textile ERP <span class="mx-1">·</span> Internal business system</footer>
        </div>
    </div>
</body>
</html>
