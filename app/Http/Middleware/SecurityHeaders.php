<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * The browser-side hardening every page gets, with a Content-Security-Policy
 * that only runs scripts from this site or carrying this request's nonce.
 * Every <script> in the views has @nonce; inline event handlers (onclick=)
 * and javascript: links do not run, so the views have none.
 *
 * Styles stay 'unsafe-inline': the views carry style blocks and attributes,
 * and CSS cannot run code. Responses that need their own rules (the badges,
 * uploaded files, the framed previews) set the header themselves; a header
 * that is already there is left alone, as is one from a proxy in front.
 */
class SecurityHeaders
{
    public const HSTS = 'max-age=31536000';

    private const HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'DENY',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        // Before the views render: they read it through @nonce.
        Vite::useCspNonce();

        $response = $next($request);

        $headers = self::HEADERS + ['Content-Security-Policy' => self::policy()];
        // HSTS only means something over TLS; behind a proxy this relies on TRUSTED_PROXIES.
        if ($request->isSecure()) {
            $headers['Strict-Transport-Security'] = self::HSTS;
        }

        foreach ($headers as $name => $value) {
            // A reverse proxy in front may carry its own policy; that one wins.
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }

    /**
     * The policy for a page. $frameAncestors is "'none'" for every page and
     * "'self'" for the two previews the admin shows in an iframe.
     */
    public static function policy(string $frameAncestors = "'none'"): string
    {
        $nonce = Vite::cspNonce();
        // Uploaded logos are linked on APP_URL, and the subscribe and preference
        // forms post there, also when the page itself is on a custom domain.
        $installation = self::installationOrigin();

        return implode('; ', [
            "default-src 'self'",
            "script-src 'self'".($nonce ? " 'nonce-{$nonce}'" : ''),
            "style-src 'self' 'unsafe-inline'",
            // blob: is the branding screen's preview of a file before it is uploaded.
            trim("img-src 'self' data: blob: {$installation}"),
            "font-src 'self' data:",
            "connect-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            trim("form-action 'self' {$installation}"),
            "frame-ancestors {$frameAncestors}",
        ]);
    }

    /** The nonce attribute for a <script> tag, as the @nonce directive prints it. */
    public static function nonceAttribute(): string
    {
        $nonce = Vite::cspNonce();

        return $nonce ? 'nonce="'.e($nonce).'"' : '';
    }

    /** scheme://host[:port] of APP_URL, or '' when it is not a plain http(s) origin. */
    private static function installationOrigin(): string
    {
        $url = parse_url((string) config('app.url'));
        $scheme = strtolower($url['scheme'] ?? '');
        $host = $url['host'] ?? '';
        if (! in_array($scheme, ['http', 'https'], true) || ! preg_match('/^(\[[0-9a-f:.]+\]|[a-z0-9.-]+)$/i', $host)) {
            return '';
        }

        return $scheme.'://'.strtolower($host).(isset($url['port']) ? ':'.(int) $url['port'] : '');
    }
}
