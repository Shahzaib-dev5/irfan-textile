<?php

use App\Modules\Identity\Console\CreateInitialAdministrator;
use App\Modules\Identity\Http\Middleware\EnsureActiveUser;
use App\Modules\Identity\Http\Middleware\EnsurePermission;
use App\Modules\Warehouses\Console\ResetDummyWarehouse;
use App\Modules\Core\Console\ResetBusinessData;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([CreateInitialAdministrator::class, ResetDummyWarehouse::class, ResetBusinessData::class])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'active.user' => EnsureActiveUser::class,
            'permission' => EnsurePermission::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
