<?php

use App\Modules\Catalog\Http\Controllers\MasterDataController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'active.user'])->prefix('catalog')->name('catalog.')->group(function (): void {
    Route::get('/', fn () => to_route('catalog.index', ['master' => 'items']))->middleware('permission:catalog.masters.view')->name('overview');
    Route::get('/items/{item}/conversions', [MasterDataController::class, 'conversions'])->whereNumber('item')->name('conversions.index');
    Route::get('/collections', [MasterDataController::class, 'collections'])->middleware('permission:catalog.masters.view')->name('collections');
    Route::post('/items/{item}/conversions', [MasterDataController::class, 'saveConversion'])->middleware('permission:catalog.masters.manage')->whereNumber('item')->name('conversions.store');
    Route::put('/items/{item}/conversions/{conversion}', [MasterDataController::class, 'saveConversion'])->middleware('permission:catalog.masters.manage')->whereNumber(['item', 'conversion'])->name('conversions.update');
    Route::whereIn('master', ['units', 'categories', 'items', 'designs', 'colors', 'variants'])->group(function (): void {
        Route::get('/{master}', [MasterDataController::class, 'index'])->middleware('permission:catalog.masters.view')->name('index');
        Route::get('/{master}/create', [MasterDataController::class, 'create'])->middleware('permission:catalog.masters.manage')->name('create');
        Route::post('/{master}', [MasterDataController::class, 'store'])->middleware('permission:catalog.masters.manage')->name('store');
        Route::get('/{master}/{record}/edit', [MasterDataController::class, 'edit'])->middleware('permission:catalog.masters.manage')->whereNumber('record')->name('edit');
        Route::get('/{master}/{record}', [MasterDataController::class, 'show'])->middleware('permission:catalog.masters.view')->whereNumber('record')->name('show');
        Route::put('/{master}/{record}', [MasterDataController::class, 'update'])->middleware('permission:catalog.masters.manage')->whereNumber('record')->name('update');
    });
});
