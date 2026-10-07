<?php

use App\Http\Controllers\Admin\MonitoringSystemController;
use App\Http\Controllers\Admin\PasskeyController;
use App\Http\Controllers\Admin\ProbeLocationController;
use App\Http\Controllers\Api\WebCronController;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\NoStore;
use App\Http\Middleware\ResolveStatusPage;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\Route;

foreach (['admin' => 'admin.', 'admin/pages/{statusPage}' => 'page.admin.'] as $prefix => $names) {
    Route::prefix($prefix)->name($names)->middleware(['auth', AuthenticateSession::class, NoStore::class, ResolveStatusPage::class])->group(function () {
        Route::get('locations', [ProbeLocationController::class, 'index'])->name('locations');
        Route::post('locations', [ProbeLocationController::class, 'store'])->name('locations.store');
        Route::get('locations/credential', [ProbeLocationController::class, 'credential'])->name('locations.credential');
        Route::delete('locations/{location}', [ProbeLocationController::class, 'destroy'])->name('locations.destroy');
    });
}

Route::prefix('admin')->name('admin.')->middleware(['auth', AuthenticateSession::class, NoStore::class, EnsureAdmin::class])->group(function () {
    Route::get('system-monitoring', [MonitoringSystemController::class, 'index'])->name('system-monitoring');
    Route::put('system-monitoring/cron', [MonitoringSystemController::class, 'cron'])->name('system-monitoring.cron');
    Route::post('backup-destinations', [MonitoringSystemController::class, 'store'])->name('backup-destinations.store');
    Route::post('backup-destinations/{destination}/run', [MonitoringSystemController::class, 'run'])->middleware('throttle:2,1,pharos-backup-run:')->name('backup-destinations.run');
    Route::delete('backup-destinations/{destination}', [MonitoringSystemController::class, 'destroy'])->name('backup-destinations.destroy');
});
Route::prefix('admin/passkeys')->name('admin.passkeys.')->middleware(['guest', NoStore::class, 'throttle:10,1,pharos-passkey-login:'])->group(function () {
    Route::post('login/options', [PasskeyController::class, 'loginOptions'])->name('login.options');
    Route::post('login', [PasskeyController::class, 'login'])->name('login');
});
Route::prefix('admin/profile/passkeys')->name('admin.profile.passkeys.')->middleware(['auth', AuthenticateSession::class, NoStore::class, 'throttle:10,1,pharos-passkey-profile:'])->group(function () {
    Route::post('options', [PasskeyController::class, 'createOptions'])->name('options');
    Route::post('/', [PasskeyController::class, 'register'])->name('store');
    Route::delete('{passkey}', [PasskeyController::class, 'destroy'])->name('destroy');
});
Route::get('cron/run', WebCronController::class)->middleware([NoStore::class, 'throttle:2,1,pharos-web-cron:'])->name('cron.run');
