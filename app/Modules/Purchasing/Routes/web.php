<?php

use App\Modules\Core\Http\Controllers\TransactionDocumentController;
use App\Modules\Purchasing\Http\Controllers\PurchasingController;
use App\Modules\Purchasing\Services\PurchasingWorkspace;
use Illuminate\Support\Facades\Route;

// Supplier identity screens remain owned by the shared Parties module.
Route::middleware(['auth', 'active.user'])->prefix('purchasing')->name('purchasing.')->group(function () {
    Route::get('/', [PurchasingController::class, 'overview'])->middleware('permission:purchasing.documents.view')->name('overview');
    foreach (PurchasingWorkspace::PAGES as $kind => $page) {
        Route::middleware('permission:purchasing.documents.view')->group(function () use ($kind, $page) {
            Route::get('/'.$page['path'], [PurchasingController::class, 'index'])->defaults('workspace_kind', $kind)->name($page['path'].'.index');
            Route::get('/'.$page['path'].'/create', [PurchasingController::class, 'form'])->middleware('permission:purchasing.documents.manage')->defaults('workspace_kind', $kind)->name($page['path'].'.create');
            Route::get('/'.$page['path'].'/{document}', [PurchasingController::class, 'show'])->defaults('workspace_kind', $kind)->name($page['path'].'.show');
            Route::get('/'.$page['path'].'/{document}/edit', [PurchasingController::class, 'form'])->middleware('permission:purchasing.documents.manage')->defaults('workspace_kind', $kind)->name($page['path'].'.edit');
        });
        Route::post('/'.$page['path'], [PurchasingController::class, 'save'])->middleware('permission:purchasing.documents.manage')->defaults('workspace_kind', $kind)->name($page['path'].'.store');
        Route::put('/'.$page['path'].'/{document}', [PurchasingController::class, 'save'])->middleware('permission:purchasing.documents.manage')->defaults('workspace_kind', $kind)->name($page['path'].'.update');
    }
    Route::middleware('permission:purchasing.documents.view')->group(function () {
        Route::get('/documents', [PurchasingController::class, 'index'])->name('index');
        Route::get('/history', [PurchasingController::class, 'history'])->name('history');
        Route::get('/payable-sources', [PurchasingController::class, 'payables'])->name('payables');
        Route::get('/documents/create', [PurchasingController::class, 'form'])->middleware('permission:purchasing.documents.manage')->name('create');
        Route::get('/documents/{document}', [PurchasingController::class, 'show'])->name('show');
        Route::get('/documents/{document}/print', TransactionDocumentController::class)->defaults('document_module', 'purchasing')->defaults('document_format', 'print')->name('print');
        Route::get('/documents/{document}/pdf', TransactionDocumentController::class)->defaults('document_module', 'purchasing')->defaults('document_format', 'pdf')->name('pdf');
        Route::get('/documents/{document}/edit', [PurchasingController::class, 'form'])->middleware('permission:purchasing.documents.manage')->name('edit');
    });
    Route::post('/documents', [PurchasingController::class, 'save'])->middleware('permission:purchasing.documents.manage')->name('store');
    Route::put('/documents/{document}', [PurchasingController::class, 'save'])->middleware('permission:purchasing.documents.manage')->name('update');
    Route::post('/documents/{document}/{action}', [PurchasingController::class, 'action'])->whereIn('action', ['confirm', 'submit', 'post', 'cancel', 'return_to_draft', 'close', 'reject_delivery'])->name('action');
});
