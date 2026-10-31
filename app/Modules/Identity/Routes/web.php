<?php

use App\Modules\Identity\Http\Controllers\AccessRolesController;
use App\Modules\Identity\Http\Controllers\LoginController;
use App\Modules\Identity\Http\Controllers\PasswordResetController;
use App\Modules\Identity\Http\Controllers\PermissionsController;
use App\Modules\Identity\Http\Controllers\UsersController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:login')->name('login.store');
    Route::get('/forgot-password', [PasswordResetController::class, 'requestForm'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'sendLink'])->middleware('throttle:password-reset')->name('password.email');
    Route::get('/reset-password/{token}', [PasswordResetController::class, 'resetForm'])->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:password-reset')->name('password.update');
});

Route::middleware(['auth', 'active.user'])->group(function (): void {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    Route::prefix('identity')->name('identity.')->group(function (): void {
        Route::get('/users', [UsersController::class, 'index'])->middleware('permission:identity.users.view')->name('users.index');
        Route::get('/users/create', [UsersController::class, 'create'])->middleware('permission:identity.users.manage')->name('users.create');
        Route::post('/users', [UsersController::class, 'store'])->middleware('permission:identity.users.manage')->name('users.store');
        Route::get('/users/{user}/edit', [UsersController::class, 'edit'])->middleware('permission:identity.users.manage')->name('users.edit');
        Route::put('/users/{user}', [UsersController::class, 'update'])->middleware('permission:identity.users.manage')->name('users.update');
        Route::get('/roles', [AccessRolesController::class, 'index'])->middleware('permission:identity.roles.view')->name('roles.index');
        Route::get('/roles/create', [AccessRolesController::class, 'create'])->middleware('permission:identity.roles.manage')->name('roles.create');
        Route::post('/roles', [AccessRolesController::class, 'store'])->middleware('permission:identity.roles.manage')->name('roles.store');
        Route::get('/roles/{role}/edit', [AccessRolesController::class, 'edit'])->middleware('permission:identity.roles.manage')->name('roles.edit');
        Route::put('/roles/{role}', [AccessRolesController::class, 'update'])->middleware('permission:identity.roles.manage')->name('roles.update');
        Route::get('/permissions', [PermissionsController::class, 'index'])->middleware('permission:identity.permissions.view')->name('permissions.index');
    });
});
