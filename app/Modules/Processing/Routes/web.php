<?php

use App\Modules\Core\Http\Controllers\TransactionDocumentController;
use App\Modules\Processing\Http\Controllers\FabricFlowController as F;
use App\Modules\Processing\Http\Controllers\ProcessingController as C;
use App\Modules\Processing\Services\ProcessingWorkspace;
use Illuminate\Support\Facades\Route;

// Shared vendor identity and historical rate routes remain in Parties.
Route::middleware(['auth', 'active.user', 'permission:processing.documents.view'])->prefix('processing')->name('processing.')->group(function () {
    Route::get('/', [C::class, 'overview'])->name('overview');
    Route::get('/fabric-out', [F::class, 'form'])->defaults('flow_kind', 'out')->middleware('permission:processing.documents.manage')->name('fabric.out');
    Route::get('/fabric-in', [F::class, 'form'])->defaults('flow_kind', 'in')->middleware('permission:processing.documents.manage')->name('fabric.in');
    Route::get('/fabric-drafts/{flow}', [F::class, 'form'])->middleware('permission:processing.documents.manage')->name('fabric.draft');
    Route::post('/fabric-flows', [F::class, 'store'])->middleware('permission:processing.documents.manage')->name('fabric.store');
    Route::get('/payables', [C::class, 'payables'])->name('payables');
    foreach (ProcessingWorkspace::PAGES as $kind => $page) {
        Route::get('/'.$page['path'], [C::class, 'index'])->defaults('workspace_kind', $kind)->name($page['path'].'.index');
        Route::get('/'.$page['path'].'/create', [C::class, 'form'])->middleware('permission:processing.documents.manage')->defaults('workspace_kind', $kind)->name($page['path'].'.create');
        Route::post('/'.$page['path'], [C::class, 'save'])->middleware('permission:processing.documents.manage')->defaults('workspace_kind', $kind)->name($page['path'].'.store');
        Route::get('/'.$page['path'].'/{document}', [C::class, 'show'])->defaults('workspace_kind', $kind)->name($page['path'].'.show');
    }
    Route::get('/documents', [C::class, 'index'])->name('index');
    Route::get('/documents/create', [C::class, 'form'])->middleware('permission:processing.documents.manage')->name('create');
    Route::post('/documents', [C::class, 'save'])->middleware('permission:processing.documents.manage')->name('store');
    Route::get('/documents/{document}', [C::class, 'show'])->name('show');
    Route::get('/documents/{document}/print', TransactionDocumentController::class)->defaults('document_module', 'processing')->defaults('document_format', 'print')->name('print');
    Route::get('/documents/{document}/pdf', TransactionDocumentController::class)->defaults('document_module', 'processing')->defaults('document_format', 'pdf')->name('pdf');
    Route::post('/documents/{document}/{action}', [C::class, 'action'])->whereIn('action', ['post', 'cancel'])->name('action');
    Route::get('/routes', [C::class, 'routes'])->name('routes');
    Route::get('/types', [C::class, 'types'])->name('services.index');
    Route::post('/routes', [C::class, 'createRoute'])->middleware('permission:processing.routes.manage')->name('routes.store');
    Route::post('/routes/{route}/publish', [C::class, 'publish'])->middleware('permission:processing.routes.manage')->name('routes.publish');
    Route::post('/services', [C::class, 'createService'])->middleware('permission:processing.routes.manage')->name('services.store');
    Route::get('/custody', [C::class, 'custody'])->name('custody');
    Route::get('/reports', [C::class, 'reports'])->name('reports');
});
