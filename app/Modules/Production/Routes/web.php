<?php

use App\Modules\Core\Http\Controllers\TransactionDocumentController;
use App\Modules\Production\Http\Controllers\ProductionController as C;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'active.user', 'permission:production.view'])->prefix('production')->name('production.')->group(function () {
    Route::get('/', fn () => to_route('production.batches.index'))->name('overview');
    Route::get('/progress', [C::class, 'progress'])->name('progress');
    Route::get('/boms', [C::class, 'boms'])->name('boms');
    Route::post('/boms', [C::class, 'saveBom'])->middleware('permission:production.manage')->name('boms.store');
    foreach (['batch' => 'batches', 'report' => 'reports'] as $kind => $path) {
        Route::get('/'.$path, [C::class, 'index'])->defaults('workspace_kind', $kind)->name($path.'.index');
        Route::get('/'.$path.'/create', [C::class, 'form'])->middleware('permission:production.manage')->defaults('workspace_kind', $kind)->name($path.'.create');
        Route::post('/'.$path, [C::class, 'save'])->middleware('permission:production.manage')->defaults('workspace_kind', $kind)->name($path.'.store');
    }
    Route::get('/documents/{document}', [C::class, 'show'])->whereNumber('document')->name('show');
    Route::post('/documents/{document}/{action}', [C::class, 'action'])->whereNumber('document')->whereIn('action', ['post', 'cancel'])->name('action');
    Route::get('/documents/{document}/pdf', TransactionDocumentController::class)->defaults('document_module', 'production')->defaults('document_format', 'pdf')->name('pdf');
    Route::get('/documents/{document}/print', TransactionDocumentController::class)->defaults('document_module', 'production')->defaults('document_format', 'print')->name('print');
});
