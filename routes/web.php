<?php

use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\BrandingController;
use App\Http\Controllers\Admin\ComponentController;
use App\Http\Controllers\Admin\GroupController;
use App\Http\Controllers\Admin\IncidentController;
use App\Http\Controllers\Admin\IntegrationController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\UpdateController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\StatusPageController;
use App\Http\Middleware\NoStore;
use App\Models\Incident;
use Illuminate\Support\Facades\Route;

Route::get('/', [StatusPageController::class, 'show'])->name('status');

Route::prefix('admin')->name('admin.')->middleware(NoStore::class)->group(function () {
    Route::middleware('guest')->group(function () {
        Route::get('login', [AuthController::class, 'form'])->name('login');
        Route::post('login', [AuthController::class, 'login'])->name('login.attempt');
    });

    Route::middleware('auth')->group(function () {
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');

        Route::get('/', fn () => redirect()->route('admin.components'));

        Route::get('components', [ComponentController::class, 'index'])->name('components');
        Route::get('components/create', [ComponentController::class, 'create'])->name('components.create');
        Route::post('components', [ComponentController::class, 'store'])->name('components.store');
        Route::get('components/{component}/edit', [ComponentController::class, 'edit'])->name('components.edit');
        Route::put('components/{component}', [ComponentController::class, 'update'])->name('components.update');
        Route::delete('components/{component}', [ComponentController::class, 'destroy'])->name('components.destroy');

        Route::get('services', [GroupController::class, 'index'])->name('groups');
        Route::get('services/create', [GroupController::class, 'create'])->name('groups.create');
        Route::post('services', [GroupController::class, 'store'])->name('groups.store');
        Route::get('services/{group}/edit', [GroupController::class, 'edit'])->name('groups.edit');
        Route::put('services/{group}', [GroupController::class, 'update'])->name('groups.update');
        Route::delete('services/{group}', [GroupController::class, 'destroy'])->name('groups.destroy');
        Route::post('services/{group}/move', [GroupController::class, 'move'])->name('groups.move');

        Route::get('incidents', [IncidentController::class, 'index'])->name('incidents');
        Route::get('incidents/create', [IncidentController::class, 'create'])->name('incidents.create');
        Route::post('incidents', [IncidentController::class, 'store'])->name('incidents.store');
        Route::get('incidents/{incident}/update', fn (Incident $incident) => view('admin.incident-update', [
            'incident' => $incident->load('updates'),
        ]))->name('incidents.update-form');
        Route::post('incidents/{incident}/update', [IncidentController::class, 'addUpdate'])->name('incidents.update');

        Route::get('settings', [SettingsController::class, 'edit'])->name('settings');
        Route::get('settings/preview', [StatusPageController::class, 'preview'])->name('settings.preview');
        Route::put('settings', [SettingsController::class, 'update'])->name('settings.update');

        Route::get('integrations', [IntegrationController::class, 'index'])->name('integrations');
        Route::post('integrations/tokens', [IntegrationController::class, 'storeToken'])->name('integrations.tokens.store');
        Route::delete('integrations/tokens/{token}', [IntegrationController::class, 'destroyToken'])->name('integrations.tokens.destroy');
        Route::put('integrations/webhook', [IntegrationController::class, 'updateWebhook'])->name('integrations.webhook');
        Route::post('integrations/webhook/rotate', [IntegrationController::class, 'rotateSecret'])->name('integrations.webhook.rotate');

        Route::get('users', [UserController::class, 'index'])->name('users');
        Route::post('users', [UserController::class, 'store'])->name('users.store');
        Route::put('users/{user}/password', [UserController::class, 'updatePassword'])->name('users.password');
        Route::delete('users/{user}', [UserController::class, 'destroy'])->name('users.destroy');

        Route::get('updates', [UpdateController::class, 'index'])->name('updates');
        Route::post('updates', [UpdateController::class, 'apply'])->name('updates.apply');

        Route::get('branding', [BrandingController::class, 'edit'])->name('branding');
        Route::put('branding', [BrandingController::class, 'update'])->name('branding.update');
        Route::post('branding/activate', [BrandingController::class, 'activate'])->name('branding.activate');
    });
});
