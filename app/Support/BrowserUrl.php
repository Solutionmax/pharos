<?php

namespace App\Support;

/** Internal browser requests use the public origin, including behind a TLS proxy. */
class BrowserUrl
{
    public static function route(string $name, mixed $parameters = []): string
    {
        // Unlike route(..., absolute: false), retain the installation's base
        // directory. Only discard the scheme and host reported by the backend.
        $url = route($name, $parameters);
        $path = parse_url($url, PHP_URL_PATH) ?: '/';
        $query = parse_url($url, PHP_URL_QUERY);

        return $path.($query !== null && $query !== false ? '?'.$query : '');
    }
}
