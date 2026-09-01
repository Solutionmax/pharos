<?php

use App\Http\Middleware\ApiTokenAuth;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\TokenMismatchException;

return Application::configure(basePath: dirname(__DIR__))
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

        // Behind Cloudflare or a Docker reverse proxy every request arrives from
        // the proxy's address, so the per-IP limits on login, subscribe and
        // heartbeat would share one bucket. Only what the operator names is
        // trusted: a forged X-Forwarded-For from the open internet must not count.
        if (($proxies = trim((string) env('TRUSTED_PROXIES', ''))) !== '') {
            $middleware->trustProxies(at: $proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));
        }

        // One-click unsubscribe is a POST from a mail provider's server, with
        // no session and no form. The signed URL is its credential.
        $middleware->validateCsrfTokens(except: ['unsubscribe/*']);

        // The token check has to run before route-model binding, or a request
        // without a token gets a 404 that tells a stranger which ids exist.
        $middleware->prependToPriorityList(SubstituteBindings::class, ApiTokenAuth::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
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
