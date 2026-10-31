<?php

use App\Modules\Warehouses\Http\Controllers\WarehouseController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'active.user'])->prefix('warehouses')->name('warehouses.')->group(function () {
    Route::get('/', [WarehouseController::class, 'index'])->middleware('permission:warehouses.view')->name('index');
    Route::middleware('permission:warehouses.manage')->group(function () {
        Route::get('/create', [WarehouseController::class, 'form'])->name('create');
        Route::get('/{record}/edit', [WarehouseController::class, 'form'])->whereNumber('record')->name('edit');
        Route::post('/', [WarehouseController::class, 'save'])->name('store');
        Route::put('/{record}', [WarehouseController::class, 'save'])->whereNumber('record')->name('update');
    });
});
