<?php

use App\Modules\Inventory\Http\Controllers\InventoryController;
use App\Modules\Inventory\Http\Controllers\PhysicalSuitController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'active.user', 'permission:inventory.view'])->prefix('physical-stock')->name('physical-stock.')->group(function () {
    $controller = PhysicalSuitController::class;
    Route::get('/', [$controller, 'index'])->name('index');
    Route::get('/processed-fabric', [$controller, 'index'])->name('fabric');
    Route::get('/create', [$controller, 'create'])->middleware('permission:inventory.manage')->name('create');
    Route::post('/', [$controller, 'store'])->middleware('permission:inventory.manage')->name('store');
});

Route::middleware(['auth', 'active.user'])->prefix('inventory')->name('inventory.')->group(function () {
    Route::middleware('permission:inventory.view')->group(function () {
        Route::get('/', [InventoryController::class, 'index'])->name('index');
        Route::get('/movements', [InventoryController::class, 'movements'])->name('movements');
        Route::get('/reconciliation', [InventoryController::class, 'reconcile'])->name('reconciliation');
        Route::get('/documents', [InventoryController::class, 'documents'])->name('documents.index');
        Route::get('/documents/create', [InventoryController::class, 'form'])->name('documents.create')->middleware('permission:inventory.manage');
        Route::get('/documents/{document}', [InventoryController::class, 'show'])->name('documents.show');
        Route::get('/documents/{document}/edit', [InventoryController::class, 'form'])->name('documents.edit')->middleware('permission:inventory.manage');
    });
    Route::post('/documents', [InventoryController::class, 'save'])->name('documents.store')->middleware('permission:inventory.manage');
    Route::put('/documents/{document}', [InventoryController::class, 'save'])->name('documents.update')->middleware('permission:inventory.manage');
    Route::post('/documents/{document}/{action}', [InventoryController::class, 'action'])->whereIn('action', ['post', 'cancel', 'reserve', 'release', 'reverse'])->name('documents.action');
    Route::post('/reconciliation', [InventoryController::class, 'repair'])->middleware('permission:inventory.reconcile')->name('repair');
    Route::post('/lots/{lot}/quality', [InventoryController::class, 'quality'])->middleware('permission:inventory.manage')->name('quality');
});
