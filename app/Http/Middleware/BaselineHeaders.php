<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The few headers every response gets, also the ones outside the web group:
 * the API, the health check at /up and the answer to an unknown address.
 * Pages get their fuller set from SecurityHeaders; this only fills gaps, so
 * whatever a route or a proxy set first wins.
 */
class BaselineHeaders
{
    private const HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'DENY',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
    ];

    /** JSON is data, never a document: it loads nothing and is never framed. */
    private const API_POLICY = "default-src 'none'; frame-ancestors 'none'";

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = self::HEADERS;
        if ($request->is('api/*')) {
            $headers['Content-Security-Policy'] = self::API_POLICY;
        }
        // HSTS only means something over TLS; behind a proxy this relies on TRUSTED_PROXIES.
        if ($request->isSecure()) {
            $headers['Strict-Transport-Security'] = SecurityHeaders::HSTS;
        }

        foreach ($headers as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }
}
