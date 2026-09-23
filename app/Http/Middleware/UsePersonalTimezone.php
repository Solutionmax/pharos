<?php

namespace App\Http\Middleware;

use App\Services\Clock;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin screens only: show and read times in the signed in user's own zone.
 *
 * The zone is set for the length of this request's pipeline, which includes
 * rendering the view, and put back afterwards, so the next request handled by
 * the same process (a test, a long running worker) starts clean. Public pages,
 * the API, feeds, the subscribe routes and the console never pass through here.
 */
class UsePersonalTimezone
{
    public function handle(Request $request, Closure $next): Response
    {
        $zone = $request->user()?->timezone;

        return Clock::withZone(is_string($zone) ? $zone : null, fn () => $next($request));
    }
}
