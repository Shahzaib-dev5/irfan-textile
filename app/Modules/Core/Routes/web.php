<?php

use App\Modules\Core\Http\Controllers\CompanyController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::get('/health', function () {
    DB::select('SELECT 1');

    return view('module.core::health');
})->name('health');

Route::middleware(['auth', 'active.user'])->group(function (): void {
    Route::view('/dashboard', 'module.core::dashboard')->name('dashboard');
    Route::get('/settings/company', [CompanyController::class, 'edit'])->middleware('permission:core.company.view')->name('core.company.edit');
    Route::put('/settings/company', [CompanyController::class, 'update'])->middleware('permission:core.company.manage')->name('core.company.update');
    Route::get('/settings/company/logo', [CompanyController::class, 'logo'])->middleware('permission:core.company.view')->name('core.company.logo');
});
