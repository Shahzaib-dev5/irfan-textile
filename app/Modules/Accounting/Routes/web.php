<?php

use App\Modules\Accounting\Http\Controllers\FinanceController;
use App\Modules\Accounting\Http\Controllers\FinancialCalendarController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'active.user'])->prefix('accounting')->name('accounting.')->group(function (): void {
    Route::get('/calendar', [FinancialCalendarController::class, 'index'])->middleware('permission:accounting.calendar.view')->name('calendar.index');
    Route::post('/financial-years', [FinancialCalendarController::class, 'storeYear'])->middleware('permission:accounting.calendar.manage')->name('calendar.years.store');
    Route::post('/financial-years/{year}/periods', [FinancialCalendarController::class, 'storePeriod'])->middleware('permission:accounting.calendar.manage')->name('calendar.periods.store');
    Route::patch('/financial-years/{year}', [FinancialCalendarController::class, 'updateYear'])->middleware('permission:accounting.calendar.manage')->name('calendar.years.update');
    Route::patch('/accounting-periods/{period}', [FinancialCalendarController::class, 'updatePeriod'])->middleware('permission:accounting.calendar.manage')->name('calendar.periods.update');
});

// Module web routes are registered through ModuleServiceProvider.

Route::middleware(['auth', 'active.user', 'permission:accounting.finance.view'])->prefix('accounting/finance')->name('finance.')->group(function () {
    $c = FinanceController::class;
    Route::get('/', [$c, 'index'])->name('index');
    Route::get('/supplier-credits', [$c, 'supplierCredit'])->middleware('permission:accounting.finance.post')->name('supplier-credit');
    Route::post('/supplier-credits', [$c, 'supplierCreditStore'])->middleware('permission:accounting.finance.post')->name('supplier-credit.store');
    Route::get('/create', [$c, 'create'])->name('create');
    Route::post('/documents', [$c, 'store'])->name('store');
    Route::get('/documents/{document}', [$c, 'show'])->name('show');
    Route::get('/documents/{document}/{format}', [$c, 'pdf'])->whereIn('format', ['pdf', 'print'])->name('document');
    Route::get('/accounts', [$c, 'accounts'])->name('accounts');
    Route::post('/accounts', [$c, 'accountSave'])->middleware('permission:accounting.finance.configure')->name('accounts.save');
    Route::post('/banks', [$c, 'bankSave'])->middleware('permission:accounting.finance.configure')->name('banks.save');
    Route::get('/reports', [$c, 'report'])->name('report');
    Route::get('/bank-reconciliation', [$c, 'bank'])->middleware('permission:accounting.finance.reconcile')->name('bank');
    Route::post('/statements', [$c, 'statementSave'])->middleware('permission:accounting.finance.reconcile')->name('statements.save');
    Route::post('/bank-reconciliation', [$c, 'reconcile'])->middleware('permission:accounting.finance.reconcile')->name('bank.confirm');
});
