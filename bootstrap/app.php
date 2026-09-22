<?php

use App\Http\Middleware\ApiTokenAuth;
use App\Http\Middleware\CentralAdministration;
use App\Http\Middleware\ResolveStatusPage;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\TokenMismatchException;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // The admin lives under /admin, so the framework's default "login" route
        // name does not exist here.
        $middleware->redirectGuestsTo(fn () => route('admin.login'));
        $middleware->redirectUsersTo(fn () => route('admin.components'));

        $middleware->web(append: SecurityHeaders::class);
        $middleware->appendToGroup('web', CentralAdministration::class);

        // Proxy trust is read by Laravel from config/trustedproxy.php after
        // environment/config loading, including when config is cached.

        // One-click unsubscribe is a POST from a mail provider's server, with
        // no session and no form. The signed URL is its credential.
        $middleware->validateCsrfTokens(except: ['unsubscribe/*', 'status/*/unsubscribe/*']);

        // The token check has to run before route-model binding, or a request
        // without a token gets a 404 that tells a stranger which ids exist.
        $middleware->prependToPriorityList(SubstituteBindings::class, ApiTokenAuth::class);
        $middleware->prependToPriorityList(ApiTokenAuth::class, ResolveStatusPage::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['setup_key', 'signal_token', 'telegram_token', 'url']);
        // A form posted after the session expired used to end on a bare "419 Page
        // Expired". Say what happened and put the person back at the door.
        $exceptions->render(function (TokenMismatchException $e, Request $request) {
            if ($request->expectsJson()) {
                return null;
            }

            return redirect()->route('admin.login')->with('status', 'Your session had expired, so that was not saved. Sign in and try again.');
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();

// Web installs keep the application outside a fixed hosting document root.
$marker = dirname(__DIR__).'/.pharos-public';
if (is_file($marker) && ($public = realpath(trim(file_get_contents($marker)))) && is_dir($public)) {
    $app->usePublicPath($public);
}

return $app;
