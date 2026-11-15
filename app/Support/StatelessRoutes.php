<?php

namespace App\Support;

use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * What the web group adds that a machine never needs. Badges, the feed, the
 * widget, embed.js, report downloads, metrics and uploaded files are read by
 * other sites, feed readers and scripts. With these they started a session row
 * and sent a cookie on every request, including on answers a proxy may cache.
 */
final class StatelessRoutes
{
    /** @var list<class-string> */
    public const MIDDLEWARE = [
        EncryptCookies::class,
        AddQueuedCookiesToResponse::class,
        StartSession::class,
        ShareErrorsFromSession::class,
        ValidateCsrfToken::class,
    ];
}
