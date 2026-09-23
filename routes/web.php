<?php

use App\Http\Controllers\Admin\AuditController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\BrandingController;
use App\Http\Controllers\Admin\ComponentController;
use App\Http\Controllers\Admin\GroupController;
use App\Http\Controllers\Admin\IncidentController;
use App\Http\Controllers\Admin\InstallController;
use App\Http\Controllers\Admin\IntegrationController;
use App\Http\Controllers\Admin\InvitationController;
use App\Http\Controllers\Admin\MailTemplateController;
use App\Http\Controllers\Admin\OverviewController;
use App\Http\Controllers\Admin\PageMailController;
use App\Http\Controllers\Admin\PagesController;
use App\Http\Controllers\Admin\PasswordResetController;
use App\Http\Controllers\Admin\ProfileController;
use App\Http\Controllers\Admin\SearchController;
use App\Http\Controllers\Admin\SessionController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\SsoController;
use App\Http\Controllers\Admin\StatusPageSettingsController;
use App\Http\Controllers\Admin\SubscriberController;
use App\Http\Controllers\Admin\TwoFactorController;
use App\Http\Controllers\Admin\UpdateController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\StatusPageController;
use App\Http\Controllers\SubscribeController;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsurePageCapability;
use App\Http\Middleware\NoStore;
use App\Http\Middleware\ResolveStatusPage;
use App\Models\Incident;
use App\Services\PageUrls;
use App\Services\SelfUpdater;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\Route;

Route::get('/', [StatusPageController::class, 'show'])->name('status');

// Subscriptions. The sign-up is rate limited per IP; the links in the mails
// are signed on their path (signed:relative), so a proxy that speaks http to
// Laravel cannot break an https link.
Route::post('subscribe', [SubscribeController::class, 'store'])->middleware('throttle:5,10')->name('subscribe');
Route::get('subscribe/confirm/{subscriber}', [SubscribeController::class, 'confirm'])->middleware('signed:relative')->name('subscribe.confirm');
Route::get('unsubscribe/{subscriber}', [SubscribeController::class, 'unsubscribe'])->middleware('signed:relative')->name('unsubscribe');
// One-click (RFC 8058): the mail client POSTs here with no session, hence no CSRF (see bootstrap/app.php).
Route::post('unsubscribe/{subscriber}', [SubscribeController::class, 'unsubscribe'])->middleware('signed:relative');

Route::prefix('admin')->name('admin.')->middleware(NoStore::class)->group(function () {
    // Outside the guest group on purpose: this route guards itself on whether an
    // account exists, which is a different question from whether you are signed in.
    Route::get('install', [InstallController::class, 'form'])->name('install');
    Route::post('install', [InstallController::class, 'store'])->middleware('throttle:10,1')->name('install.store');

    Route::middleware('guest')->group(function () {
        Route::get('forgot-password', [PasswordResetController::class, 'requestForm'])->name('password.request');
        Route::post('forgot-password', [PasswordResetController::class, 'sendLink'])->middleware('throttle:5,1')->name('password.email');
        Route::get('reset-password/{token}', [PasswordResetController::class, 'resetForm'])->middleware('throttle:30,1')->name('password.reset');
        Route::post('reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:5,1')->name('password.update');
        Route::get('login', [AuthController::class, 'form'])->name('login');
        Route::post('login', [AuthController::class, 'login'])->name('login.attempt');
        Route::get('sso/redirect', [SsoController::class, 'redirect'])->name('sso.redirect');
        Route::get('sso/callback', [SsoController::class, 'callback'])->name('sso.callback');
        Route::get('two-factor', [TwoFactorController::class, 'form'])->name('two-factor');
        Route::post('two-factor', [TwoFactorController::class, 'verify'])->name('two-factor.verify');
        // An invitation is a password link with a longer life and its own token table.
        Route::get('welcome/{token}', [InvitationController::class, 'show'])->middleware('throttle:30,1')->name('invitation');
        Route::post('welcome', [InvitationController::class, 'accept'])->middleware('throttle:5,1')->name('invitation.accept');
    });

    // AuthenticateSession is what makes a password change actually kick the other
    // sessions out; logoutOtherDevices does nothing without it.
    Route::middleware(['auth', AuthenticateSession::class])->group(function () {
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');

        Route::get('/', fn () => redirect()->to(PageUrls::landing(auth()->user())));

        Route::get('overview', [OverviewController::class, 'show'])->name('overview');
        // Only what the signed in user may open; see App\Services\AdminSearch.
        Route::get('search', SearchController::class)->middleware('throttle:60,1')->name('search');

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

        Route::get('status-page', [StatusPageSettingsController::class, 'edit'])->name('status-page');
        Route::get('status-page/preview', [StatusPageController::class, 'preview'])->name('status-page.preview');
        Route::put('status-page', [StatusPageSettingsController::class, 'update'])->name('status-page.update');

        Route::get('subscribers', [SubscriberController::class, 'index'])->name('subscribers');
        // Before {subscriber}: "export" is not an id.
        Route::get('subscribers/export', [SubscriberController::class, 'export'])->name('subscribers.export');
        Route::post('subscribers/enabled', [SubscriberController::class, 'toggle'])->name('subscribers.toggle');
        Route::post('subscribers/{subscriber}/resend', [SubscriberController::class, 'resend'])->name('subscribers.resend');
        Route::delete('subscribers/{subscriber}', [SubscriberController::class, 'destroy'])->name('subscribers.destroy');

        Route::get('integrations', [IntegrationController::class, 'index'])->name('integrations');
        Route::post('integrations/notifications', [IntegrationController::class, 'storeEndpoint'])->name('integrations.endpoints.store');
        Route::post('integrations/notifications/{endpoint}/test', [IntegrationController::class, 'testEndpoint'])->name('integrations.endpoints.test');
        Route::delete('integrations/notifications/{endpoint}', [IntegrationController::class, 'destroyEndpoint'])->name('integrations.endpoints.destroy');

        Route::get('profile', [ProfileController::class, 'show'])->name('profile');
        Route::put('profile', [ProfileController::class, 'update'])->name('profile.update');
        Route::put('profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password');
        Route::get('profile/two-factor', fn () => redirect()->route('admin.profile')); // a bookmarked POST route is not an error page
        Route::post('profile/two-factor', [ProfileController::class, 'startTwoFactor'])->name('profile.two-factor.start');
        Route::post('profile/two-factor/confirm', [ProfileController::class, 'confirmTwoFactor'])->name('profile.two-factor.confirm');
        Route::delete('profile/two-factor', [ProfileController::class, 'disableTwoFactor'])->name('profile.two-factor.disable');
        Route::post('profile/recovery-codes', [ProfileController::class, 'regenerateRecoveryCodes'])->name('profile.recovery-codes');
        Route::put('profile/preferences', [ProfileController::class, 'updatePreferences'])->name('profile.preferences');
        // Your own sessions only: the controller never reads another account's rows.
        Route::delete('profile/sessions', [SessionController::class, 'destroyOthers'])->name('profile.sessions.others');
        Route::delete('profile/sessions/{session}', [SessionController::class, 'destroy'])->where('session', '[a-f0-9]{64}')->name('profile.sessions.destroy');
        // Hiding a "Good to know" note is personal, so it sits with the profile and needs no admin.
        Route::post('notes/{id}/restore', [ProfileController::class, 'restoreNote'])->where('id', '[a-z0-9.-]+')->name('notes.restore-one');
        Route::post('notes/restore', [ProfileController::class, 'restoreNotes'])->name('notes.restore');
        Route::post('notes/{id}/dismiss', [ProfileController::class, 'dismissNote'])->name('notes.dismiss')->where('id', '[a-z0-9.-]+');

        // Everything that changes the installation itself rather than what it reports on.
        Route::middleware(EnsureAdmin::class)->group(function () {
            Route::get('users', [UserController::class, 'index'])->name('users');
            Route::post('users', [UserController::class, 'store'])->name('users.store');
            Route::get('users/{user}/pages', [UserController::class, 'editPages'])->name('users.pages.edit');
            Route::put('users/{user}/pages', [UserController::class, 'updatePages'])->name('users.pages.update');
            Route::put('users/{user}/role', [UserController::class, 'updateRole'])->name('users.role');
            Route::put('users/{user}/access', [UserController::class, 'updateAccess'])->name('users.access');
            Route::post('users/{user}/invite', [UserController::class, 'invite'])->middleware('throttle:10,1')->name('users.invite');
            Route::delete('users/{user}', [UserController::class, 'destroy'])->name('users.destroy');

            Route::post('integrations/tokens', [IntegrationController::class, 'storeToken'])->name('integrations.tokens.store');
            Route::delete('integrations/tokens/{token}', [IntegrationController::class, 'destroyToken'])->name('integrations.tokens.destroy');
            Route::post('integrations/webhook/rotate', [IntegrationController::class, 'rotateSecret'])->name('integrations.webhook.rotate');

            Route::get('audit', [AuditController::class, 'index'])->name('audit');
            Route::get('audit/export', [AuditController::class, 'export'])->name('audit.export');

            Route::get('updates', [UpdateController::class, 'index'])->name('updates');
            Route::post('updates', [UpdateController::class, 'apply'])->name('updates.apply');
            Route::post('updates/backup', [UpdateController::class, 'backup'])->name('updates.backup');
            // Before {name}, so "progress" is never read as a backup to download.
            Route::get('updates/backup/progress', [UpdateController::class, 'progress'])->name('updates.backup.progress');
            // The name is a folder under storage/app/backups; the pattern keeps
            // slashes and dots out of it, and the controller checks the path again.
            Route::get('updates/backup/{name}', [UpdateController::class, 'download'])->name('updates.backup.download')->where('name', SelfUpdater::NAME_PATTERN);
            Route::delete('updates/backup/{name}', [UpdateController::class, 'destroy'])->name('updates.backup.destroy')->where('name', SelfUpdater::NAME_PATTERN);
            Route::post('updates/backup/{name}/rollback', [UpdateController::class, 'rollback'])->name('updates.backup.rollback')->where('name', SelfUpdater::NAME_PATTERN);

            Route::get('settings', [SettingsController::class, 'edit'])->name('settings');
            Route::put('settings', [SettingsController::class, 'update'])->name('settings.update');
            Route::put('settings/mail', [SettingsController::class, 'updateMail'])->name('settings.mail');
            Route::post('settings/mail-test', [SettingsController::class, 'sendTestMail'])->name('settings.mail-test');

            // The single sign-on form lives on the Settings screen now; the old
            // address stays for bookmarks and the docs.
            Route::get('sso', fn () => redirect()->route('admin.settings', ['tab' => 'sso'], 301))->name('sso');
            Route::put('sso', [SsoController::class, 'update'])->name('sso.update');

            Route::get('branding', [BrandingController::class, 'edit'])->name('branding');
            Route::put('branding', [BrandingController::class, 'update'])->name('branding.update');
            Route::post('branding/activate', [BrandingController::class, 'activate'])->name('branding.activate');
            Route::post('branding/deactivate', [BrandingController::class, 'deactivate'])->name('branding.deactivate');

            // Mail templates: the screen and the preview are for every admin; the
            // three writes are checked against the brand pack in the controller.
            Route::get('mail-templates', [MailTemplateController::class, 'edit'])->name('mail-templates');
            Route::match(['get', 'post'], 'mail-templates/preview', [MailTemplateController::class, 'preview'])->name('mail-templates.preview');
            Route::put('mail-templates', [MailTemplateController::class, 'update'])->name('mail-templates.update');
            Route::post('mail-templates/reset', [MailTemplateController::class, 'reset'])->name('mail-templates.reset');
            Route::post('mail-templates/test', [MailTemplateController::class, 'sendTest'])->name('mail-templates.test');
        });
    });
});

// Shared hosting can disable symlink(). Laravel serves only the public disk.
Route::get('/storage/{path}', function (string $path) {
    $root = realpath(storage_path('app/public'));
    $file = realpath(storage_path('app/public/'.$path));
    abort_unless($root && $file && str_starts_with($file, $root.DIRECTORY_SEPARATOR) && is_file($file), 404);

    return response()->file($file, ['X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "sandbox; default-src 'none'"]);
})->where('path', '.*')->name('public.upload');

// Register explicit page routes from the same actions, so legacy and page routes
// cannot drift in validation or middleware. Account/install routes remain central.
$pageRouteNames = ['status', 'subscribe', 'subscribe.confirm', 'unsubscribe'];
$pageAdminPrefixes = ['overview', 'components', 'groups', 'incidents', 'status-page', 'subscribers', 'integrations', 'branding', 'mail-templates', 'mail'];
$originalRoutes = Route::getRoutes()->getRoutes();
foreach ($originalRoutes as $original) {
    $name = $original->getName();
    $adminPageRoute = $name && str_starts_with($name, 'admin.')
        && in_array(explode('.', substr($name, 6))[0], $pageAdminPrefixes, true)
        && ! in_array($name, ['admin.branding.activate', 'admin.branding.deactivate'], true);
    $publicPageRoute = in_array($name, $pageRouteNames, true)
        || ($original->uri() === 'unsubscribe/{subscriber}');
    if (! $adminPageRoute && ! $publicPageRoute) {
        continue;
    }
    $original->middleware(ResolveStatusPage::class);
    if ($adminPageRoute) {
        $original->withoutMiddleware(EnsureAdmin::class)->middleware(EnsurePageCapability::class);
    }
    $uri = $adminPageRoute
        ? 'admin/pages/{statusPage}/'.substr($original->uri(), 6)
        : 'status/{slug}'.($original->uri() === '/' ? '' : '/'.$original->uri());
    $action = $original->getAction();
    $action['as'] = $name ? 'page.'.$name : null;
    $copy = clone $original;
    $copy->setUri($uri);
    $copy->setAction($action);
    Route::getRoutes()->add($copy);
}

Route::prefix('admin/pages')->name('admin.pages.')->middleware(['web', 'auth', AuthenticateSession::class, NoStore::class, EnsureAdmin::class])->group(function () {
    Route::get('/', [PagesController::class, 'index'])->name('index');
    Route::get('/create', [PagesController::class, 'create'])->name('create');
    Route::post('/', [PagesController::class, 'store'])->name('store');
    Route::get('/{statusPage}/edit', [PagesController::class, 'edit'])->name('edit');
    Route::put('/{statusPage}', [PagesController::class, 'update'])->name('update');
    Route::post('/{statusPage}/archive', [PagesController::class, 'archive'])->name('archive');
});
Route::get('admin/no-pages', fn () => view('admin.no-pages'))->middleware(['auth', AuthenticateSession::class, NoStore::class])->name('admin.no-pages');

foreach (['admin' => 'admin.', 'admin/pages/{statusPage}' => 'page.admin.'] as $prefix => $names) {
    Route::prefix($prefix)->name($names)->middleware(['auth', AuthenticateSession::class, NoStore::class, ResolveStatusPage::class, EnsurePageCapability::class])->group(function () {
        Route::get('mail', [PageMailController::class, 'edit'])->name('mail.edit');
        Route::put('mail', [PageMailController::class, 'update'])->name('mail.update');
        Route::post('mail-test', [PageMailController::class, 'test'])->name('mail.test');
    });
}
