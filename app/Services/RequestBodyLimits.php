<?php

namespace App\Services;

use Symfony\Component\HttpFoundation\Request;

/** Raw bounds before Illuminate's native capture eagerly decodes JSON. */
class RequestBodyLimits
{
    public static function limit(Request $request): ?int
    {
        // Match Laravel's URI validator: trim literal trailing slashes before decoding.
        $path = rawurldecode(trim($request->getPathInfo(), '/'));
        if (preg_match('~^api/v1/probe/(?:jobs|results)$~D', $path)) {
            return 16384;
        }
        $path = preg_replace('~^api/v1/(?:pages/[^/]+/)?~', '', $path, 1, $matched);
        // Illuminate can turn a JSON POST into DELETE via body _method only
        // after capture. Bound potential overrides before decoding that body.
        $type = (string) $request->headers->get('Content-Type', '');
        $jsonOverride = $request->isMethod('POST') && (str_contains($type, '/json') || str_contains($type, '+json'));
        $owned = $matched && (preg_match('~^(?:groups|subscribers|maintenance)(?:/[^/]+)?$|^metrics$~D', $path)
            || ($request->isMethod('POST') && $path === 'components')
            || (($request->isMethod('DELETE') || $jsonOverride) && preg_match('~^(?:components|incidents)/[^/]+$~D', $path)));

        return $owned ? 262144 : null;
    }

    public static function tooLarge(Request $request): bool
    {
        $limit = self::limit($request);
        if ($limit === null) {
            return false;
        }
        if ((int) $request->headers->get('Content-Length', '0') > $limit) {
            return true;
        }
        $stream = $request->getContent(true);
        $body = stream_get_contents($stream, $limit + 1);
        rewind($stream);

        return $body === false || strlen($body) > $limit;
    }
}
