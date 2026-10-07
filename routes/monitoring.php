<?php

use App\Http\Controllers\Admin\ProbeLocationController;
use App\Http\Middleware\NoStore;
use App\Http\Middleware\ResolveStatusPage;
use Illuminate\Support\Facades\Route;

foreach (['admin' => 'admin.', 'admin/pages/{statusPage}' => 'page.admin.'] as $prefix => $names) {
    Route::prefix($prefix)->name($names)->middleware(['auth', NoStore::class, ResolveStatusPage::class])->group(function () {
        Route::get('locations', [ProbeLocationController::class, 'index'])->name('locations');
        Route::post('locations', [ProbeLocationController::class, 'store'])->name('locations.store');
        Route::get('locations/credential', [ProbeLocationController::class, 'credential'])->name('locations.credential');
        Route::delete('locations/{location}', [ProbeLocationController::class, 'destroy'])->name('locations.destroy');
    });
}
