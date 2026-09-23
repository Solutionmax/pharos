<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The browser-side hardening every page gets. frame-ancestors is the only CSP
 * directive on purpose: a full policy would break the inline scripts and
 * styles the Blade views carry, and being framed is the one thing a status
 * page has to rule out. Nobody embeds it, so DENY costs nothing.
 */
class SecurityHeaders
{
    private const HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'DENY',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
        'Content-Security-Policy' => "frame-ancestors 'none'",
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // HSTS only means something over TLS; behind a proxy this relies on TRUSTED_PROXIES.
        $headers = $request->isSecure()
            ? self::HEADERS + ['Strict-Transport-Security' => 'max-age=31536000']
            : self::HEADERS;

        foreach ($headers as $name => $value) {
            // A reverse proxy in front may carry its own policy; that one wins.
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }
}
