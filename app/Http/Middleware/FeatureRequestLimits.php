<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class FeatureRequestLimits
{
    public function handle(Request $request, Closure $next)
    {
        abort_if((int) $request->header('Content-Length', 0) > 262144 || strlen($request->getContent()) > 262144, 413);

        return $next($request);
    }
}
