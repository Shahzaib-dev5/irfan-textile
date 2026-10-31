<?php

use App\Modules\Core\Http\Controllers\TransactionDocumentController;
use App\Modules\FinishedGoods\Http\Controllers\CostDocumentController;
use App\Modules\FinishedGoods\Http\Controllers\FinishedGoodsController as C;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'active.user', 'permission:finished-goods.view'])->prefix('finished-goods')->name('finished-goods.')->group(function () {
    Route::get('/', [C::class, 'overview'])->name('overview');
    Route::get('/specifications', [C::class, 'specifications'])->name('specifications');
    Route::post('/specifications', [C::class, 'publish'])->middleware('permission:finished-goods.manage')->name('specifications.store');
    Route::get('/packing', [C::class, 'index'])->name('index');
    Route::get('/packing/create', [C::class, 'form'])->middleware('permission:finished-goods.manage')->name('create');
    Route::post('/packing', [C::class, 'save'])->middleware('permission:finished-goods.manage')->name('store');
    Route::get('/batches', [C::class, 'batches'])->name('batches');
    Route::get('/inventory', [C::class, 'inventory'])->name('inventory');
    Route::post('/quality', [C::class, 'quality'])->middleware('permission:finished-goods.quality')->name('quality');
    Route::get('/trace/{layer}', [C::class, 'trace'])->whereNumber('layer')->name('trace');
    Route::get('/costing/{document}', [C::class, 'costing'])->whereNumber('document')->name('costing');
    Route::get('/costing/{document}/{format}', CostDocumentController::class)->whereNumber('document')->whereIn('format', ['pdf', 'print'])->name('costing.document');
    Route::get('/documents/{document}', [C::class, 'show'])->whereNumber('document')->name('show');
    Route::post('/documents/{document}/{action}', [C::class, 'action'])->whereNumber('document')->whereIn('action', ['post', 'cancel'])->name('action');
    foreach (['pdf', 'print'] as $format) {
        Route::get('/documents/{document}/'.$format, TransactionDocumentController::class)->defaults('document_module', 'finished-goods')->defaults('document_format', $format)->whereNumber('document')->name($format);
    }
});
