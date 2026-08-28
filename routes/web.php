<?php

use App\Http\Controllers\Admin\AuditController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\BrandingController;
use App\Http\Controllers\Admin\ComponentController;
use App\Http\Controllers\Admin\GroupController;
use App\Http\Controllers\Admin\IncidentController;
use App\Http\Controllers\Admin\InstallController;
use App\Http\Controllers\Admin\IntegrationController;
use App\Http\Controllers\Admin\ProfileController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\SsoController;
use App\Http\Controllers\Admin\TwoFactorController;
use App\Http\Controllers\Admin\UpdateController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\StatusPageController;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\NoStore;
use App\Models\Incident;
use Illuminate\Support\Facades\Route;

Route::get('/', [StatusPageController::class, 'show'])->name('status');

Route::prefix('admin')->name('admin.')->middleware(NoStore::class)->group(function () {
    // Outside the guest group on purpose: this route guards itself on whether an
    // account exists, which is a different question from whether you are signed in.
    Route::get('install', [InstallController::class, 'form'])->name('install');
    Route::post('install', [InstallController::class, 'store'])->name('install.store');

    Route::middleware('guest')->group(function () {
        Route::get('login', [AuthController::class, 'form'])->name('login');
        Route::post('login', [AuthController::class, 'login'])->name('login.attempt');
        Route::get('sso/redirect', [SsoController::class, 'redirect'])->name('sso.redirect');
        Route::get('sso/callback', [SsoController::class, 'callback'])->name('sso.callback');
        Route::get('two-factor', [TwoFactorController::class, 'form'])->name('two-factor');
        Route::post('two-factor', [TwoFactorController::class, 'verify'])->name('two-factor.verify');
    });

    // AuthenticateSession is what makes a password change actually kick the other
    // sessions out; logoutOtherDevices does nothing without it.
    Route::middleware(['auth', \Illuminate\Session\Middleware\AuthenticateSession::class])->group(function () {
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');

        Route::get('/', fn () => redirect()->route('admin.components'));

        Route::get('components', [ComponentController::class, 'index'])->name('components');
        Route::get('components/create', [ComponentController::class, 'create'])->name('components.create');
        Route::post('components', [ComponentController::class, 'store'])->name('components.store');
        // Before the {component} routes: "tags" is not an id, and a wildcard
        // segment would swallow it.
        Route::delete('components/tags/{tag}', [ComponentController::class, 'destroyTag'])->name('components.tags.destroy');
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
        Route::delete('incidents/{incident}', [IncidentController::class, 'destroy'])->name('incidents.destroy');

        Route::get('settings', [SettingsController::class, 'edit'])->name('settings');
        Route::get('settings/preview', [StatusPageController::class, 'preview'])->name('settings.preview');
        Route::put('settings', [SettingsController::class, 'update'])->name('settings.update');

        Route::get('integrations', [IntegrationController::class, 'index'])->name('integrations');
        Route::post('integrations/notifications', [IntegrationController::class, 'storeEndpoint'])->name('integrations.endpoints.store');
        Route::post('integrations/notifications/{endpoint}/test', [IntegrationController::class, 'testEndpoint'])->name('integrations.endpoints.test');
        Route::delete('integrations/notifications/{endpoint}', [IntegrationController::class, 'destroyEndpoint'])->name('integrations.endpoints.destroy');

        Route::get('profile', [ProfileController::class, 'show'])->name('profile');
        Route::put('profile', [ProfileController::class, 'update'])->name('profile.update');
        Route::put('profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password');
        Route::post('profile/two-factor', [ProfileController::class, 'startTwoFactor'])->name('profile.two-factor.start');
        Route::post('profile/two-factor/confirm', [ProfileController::class, 'confirmTwoFactor'])->name('profile.two-factor.confirm');
        Route::delete('profile/two-factor', [ProfileController::class, 'disableTwoFactor'])->name('profile.two-factor.disable');
        Route::post('profile/recovery-codes', [ProfileController::class, 'regenerateRecoveryCodes'])->name('profile.recovery-codes');

        // Everything that changes the installation itself rather than what it reports on.
        Route::middleware(EnsureAdmin::class)->group(function () {
            Route::get('users', [UserController::class, 'index'])->name('users');
            Route::post('users', [UserController::class, 'store'])->name('users.store');
            Route::put('users/{user}/role', [UserController::class, 'updateRole'])->name('users.role');
            Route::delete('users/{user}', [UserController::class, 'destroy'])->name('users.destroy');

            Route::post('integrations/tokens', [IntegrationController::class, 'storeToken'])->name('integrations.tokens.store');
            Route::delete('integrations/tokens/{token}', [IntegrationController::class, 'destroyToken'])->name('integrations.tokens.destroy');
            Route::post('integrations/webhook/rotate', [IntegrationController::class, 'rotateSecret'])->name('integrations.webhook.rotate');

            Route::get('audit', [AuditController::class, 'index'])->name('audit');
            Route::get('audit/export', [AuditController::class, 'export'])->name('audit.export');

            Route::get('updates', [UpdateController::class, 'index'])->name('updates');
            Route::post('updates', [UpdateController::class, 'apply'])->name('updates.apply');

            Route::get('sso', [SsoController::class, 'edit'])->name('sso');
            Route::put('sso', [SsoController::class, 'update'])->name('sso.update');

            Route::get('branding', [BrandingController::class, 'edit'])->name('branding');
            Route::put('branding', [BrandingController::class, 'update'])->name('branding.update');
            Route::post('branding/activate', [BrandingController::class, 'activate'])->name('branding.activate');
        });
    });
});
