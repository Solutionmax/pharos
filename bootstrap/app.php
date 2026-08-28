<?php

use App\Http\Middleware\ApiTokenAuth;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

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

        // One-click unsubscribe is a POST from a mail provider's server, with
        // no session and no form. The signed URL is its credential.
        $middleware->validateCsrfTokens(except: ['unsubscribe/*']);

        // The token check has to run before route-model binding, or a request
        // without a token gets a 404 that tells a stranger which ids exist.
        $middleware->prependToPriorityList(SubstituteBindings::class, ApiTokenAuth::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
