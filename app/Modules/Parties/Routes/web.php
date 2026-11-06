<?php

use App\Modules\Parties\Http\Controllers\PartyManagementController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'active.user'])->group(function (): void {
    Route::get('/parties', [PartyManagementController::class, 'partyIndex'])->name('parties.index');
    Route::get('/parties/create', [PartyManagementController::class, 'partyCreate'])->name('parties.create');
    Route::post('/parties', [PartyManagementController::class, 'partyStore'])->name('parties.store');
    Route::get('/parties/{party}/edit', [PartyManagementController::class, 'partyEdit'])->name('parties.edit');
    Route::put('/parties/{party}', [PartyManagementController::class, 'partyUpdate'])->name('parties.update');
    Route::delete('/parties/{party}', [PartyManagementController::class, 'partyDeactivate'])->name('parties.deactivate');

    foreach ([
        'purchasing/suppliers' => ['supplier', 'purchasing.supplier'],
        'processing/vendors' => ['vendor', 'processing.vendor'],
        'sales/customers' => ['customer', 'sales.customer'],
    ] as $path => [$role, $permission]) {
        Route::prefix($path)->name($role.'.')->group(function () use ($permission, $role): void {
            Route::get('/', [PartyManagementController::class, 'index'])->middleware('permission:'.$permission.'.view')->defaults('role', $role)->name('index');
            Route::get('/create', [PartyManagementController::class, 'create'])->middleware('permission:'.$permission.'.manage')->defaults('role', $role)->name('create');
            Route::post('/', [PartyManagementController::class, 'store'])->middleware('permission:'.$permission.'.manage')->defaults('role', $role)->name('store');
            Route::get('/{party}/edit', [PartyManagementController::class, 'edit'])->middleware('permission:'.$permission.'.manage')->defaults('role', $role)->name('edit');
            Route::put('/{party}', [PartyManagementController::class, 'update'])->middleware('permission:'.$permission.'.manage')->defaults('role', $role)->name('update');
            Route::delete('/{party}', [PartyManagementController::class, 'deactivate'])->middleware('permission:'.$permission.'.manage')->defaults('role', $role)->name('deactivate');
        });
    }

    Route::get('/processing/vendor-services/{vendorService}/rates', [PartyManagementController::class, 'rates'])->middleware('permission:processing.vendor.view')->name('vendor.rates');
    Route::post('/processing/vendor-services/{vendorService}/rates', [PartyManagementController::class, 'publishRate'])->middleware('permission:processing.vendor.manage')->name('vendor.rates.publish');
});
