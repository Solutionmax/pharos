<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Laravel's default "no-cache, private" still lets a browser keep the page in
 * its back/forward cache, so pressing Back shows a stale admin screen the
 * server never saw. An admin page reflects data that changed a second ago;
 * "no-store" is the honest instruction.
 */
class NoStore
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }
}
