<?php

namespace App\Support;

use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Symfony\Component\HttpFoundation\Response;

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

    /**
     * Pages people read. A visitor who arrives without a session gets them
     * without one too, and with headers a CDN may cache: no cookie in the
     * answer, nothing personal on the page. Someone who already has a session
     * (a signed in admin, a visitor who just subscribed) keeps it, and with it
     * the admin bar and the reply to the form.
     */
    public const VISITOR_PAGES = ['status', 'public.incident', 'public.reports'];

    public const SHARED_MAX_AGE = 30;

    public const STALE_WHILE_REVALIDATE = 300;

    /** True when this request is one of the visitor pages, read by someone without a session. */
    public static function servesVisitor(Request $request): bool
    {
        $name = preg_replace('/^page\./', '', (string) $request->route()?->getName());
        if (! in_array($name, self::VISITOR_PAGES, true) || ! $request->isMethodCacheable()) {
            return false;
        }

        foreach (array_keys($request->cookies->all()) as $cookie) {
            // The session, or "remember me", which signs someone back in without one.
            if ($cookie === config('session.cookie') || str_starts_with((string) $cookie, 'remember_')) {
                return false;
            }
        }

        return true;
    }

    /** The same for everyone without a session, so any cache in between may keep it for a moment. */
    public static function shareable(Response $response): Response
    {
        if ($response->getStatusCode() !== 200 || $response->headers->hasCacheControlDirective('no-store')) {
            return $response;
        }

        $response->setPublic();
        $response->setMaxAge(self::SHARED_MAX_AGE);
        $response->setStaleWhileRevalidate(self::STALE_WHILE_REVALIDATE);
        $response->setVary('Cookie', false);

        return $response;
    }
}
