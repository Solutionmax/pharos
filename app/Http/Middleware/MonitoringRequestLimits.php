<?php

namespace App\Http\Middleware;

use App\Services\RequestBodyLimits;
use Closure;
use Illuminate\Http\Request;

class MonitoringRequestLimits
{
    public function handle(Request $request, Closure $next)
    {
        if (RequestBodyLimits::limit($request) !== 16384) {
            return $next($request);
        }
        abort_if(RequestBodyLimits::tooLarge($request), 413);

        return $next($request);
    }
}
