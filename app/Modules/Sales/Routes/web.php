<?php

use App\Modules\Sales\Http\Controllers\SalesController as C;
use App\Modules\Sales\Http\Controllers\SalesDocumentController;
use Illuminate\Support\Facades\Route;

// Customer CRUD remains in Parties; no second customer identity master.
Route::middleware(['auth', 'active.user', 'permission:sales.documents.view'])->prefix('sales')->name('sales.')->group(function () {
    Route::get('/', fn () => to_route('sales.index'))->name('overview');
    Route::get('/documents', [C::class, 'index'])->name('index');
    Route::get('/documents/create', [C::class, 'create'])->middleware('permission:sales.documents.manage')->name('create');
    Route::post('/documents', [C::class, 'store'])->middleware('permission:sales.documents.manage')->name('store');
    Route::get('/items', [C::class, 'items'])->name('items');
    Route::get('/availability', [C::class, 'availability'])->name('availability');
    Route::get('/customer-history/{customer}', [C::class, 'history'])->whereNumber('customer')->name('history');
    Route::get('/documents/{document}', [C::class, 'show'])->whereNumber('document')->name('show');
    Route::post('/documents/{document}/{action}', [C::class, 'action'])->whereNumber('document')->whereIn('action', ['post', 'cancel', 'close', 'reserve', 'release'])->name('action');
    Route::get('/documents/{document}/{format}', SalesDocumentController::class)->whereNumber('document')->whereIn('format', ['pdf', 'print'])->name('document');
});
