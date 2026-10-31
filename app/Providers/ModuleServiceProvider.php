<?php

namespace App\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class ModuleServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        foreach (config('modules', []) as $key => $module) {
            $basePath = app_path('Modules/'.$module['directory']);
            $routes = $basePath.'/Routes/web.php';
            $views = $basePath.'/Resources/views';

            if (is_file($routes) && ! $this->app->routesAreCached()) {
                Route::middleware('web')->group(function () use ($routes): void {
                    require $routes;
                });
            }

            if (is_dir($views)) {
                $this->loadViewsFrom($views, 'module.'.$key);
            }
        }
    }
}
