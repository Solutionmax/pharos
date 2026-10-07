<?php

namespace App\Http\Middleware;

use App\Services\RequestBodyLimits;
use Closure;
use Illuminate\Http\Request;

class FeatureRequestLimits
{
    public function handle(Request $request, Closure $next)
    {
        // Global execution must precede Laravel's JSON input transformers. Only
        // the new feature endpoints have this contract; legacy writes retain theirs.
        if (RequestBodyLimits::limit($request) !== 262144) {
            return $next($request);
        }
        abort_if(RequestBodyLimits::tooLarge($request), 413);

        return $next($request);
    }
}
