<?php

namespace App\Providers;

use App\Models\AccessRole;
use App\Models\AccountingPeriod;
use App\Models\Company;
use App\Models\Document;
use App\Models\FinancialYear;
use App\Models\Party;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Modules\Catalog\CatalogRegistry;
use App\Policies\AccessRolePolicy;
use App\Policies\AccountingPeriodPolicy;
use App\Policies\CatalogPolicy;
use App\Policies\CompanyPolicy;
use App\Policies\FinancialYearPolicy;
use App\Policies\FinishedGoodsPolicy;
use App\Policies\InventoryPolicy;
use App\Policies\PartyPolicy;
use App\Policies\ProcessingPolicy;
use App\Policies\ProductionPolicy;
use App\Policies\PurchasingPolicy;
use App\Policies\SalesPolicy;
use App\Policies\UserPolicy;
use App\Policies\WarehousePolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        foreach (['viewAny', 'view', 'create', 'update'] as $ability) {
            Gate::define('purchasing.'.$ability, fn (User $user, ...$args) => app(PurchasingPolicy::class)->{$ability}($user, ...$args));
        }
        foreach (['viewAny', 'view', 'create'] as $ability) {
            Gate::define('processing.'.$ability, fn (User $user, ...$args) => app(ProcessingPolicy::class)->{$ability}($user, ...$args));
        }
        foreach (['viewAny', 'view', 'create'] as $ability) {
            Gate::define('production.'.$ability, fn (User $user, ...$args) => app(ProductionPolicy::class)->{$ability}($user, ...$args));
        }
        foreach (['viewAny', 'view', 'create'] as $ability) {
            Gate::define('finished-goods.'.$ability, fn (User $user, ...$args) => app(FinishedGoodsPolicy::class)->{$ability}($user, ...$args));
        }
        foreach (['viewAny', 'view', 'create'] as $ability) {
            Gate::define('sales.'.$ability, fn (User $user, ...$args) => app(SalesPolicy::class)->{$ability}($user, ...$args));
        }
        Gate::policy(Document::class, InventoryPolicy::class);
        Gate::policy(Warehouse::class, WarehousePolicy::class);
        Gate::policy(WarehouseLocation::class, WarehousePolicy::class);
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(AccessRole::class, AccessRolePolicy::class);
        Gate::policy(Company::class, CompanyPolicy::class);
        Gate::policy(FinancialYear::class, FinancialYearPolicy::class);
        Gate::policy(AccountingPeriod::class, AccountingPeriodPolicy::class);
        Gate::policy(Party::class, PartyPolicy::class);
        foreach (CatalogRegistry::MODELS as $model) {
            Gate::policy($model, CatalogPolicy::class);
        }

        RateLimiter::for('login', function (Request $request): Limit {
            $email = mb_strtolower((string) $request->input('email'));

            return Limit::perMinute(5)->by($email.'|'.$request->ip());
        });

        RateLimiter::for('password-reset', fn (Request $request): Limit => Limit::perMinute(3)->by(mb_strtolower((string) $request->input('email')).'|'.$request->ip()));
    }
}
