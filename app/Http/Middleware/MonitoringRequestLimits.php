<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class MonitoringRequestLimits
{
    public function handle(Request $request, Closure $next)
    {
        if (! $request->is('api/v1/probe/*')) {
            return $next($request);
        }

        $limit = 16384;
        abort_if((int) $request->header('Content-Length', '0') > $limit, 413);
        $stream = $request->getContent(true);
        $body = stream_get_contents($stream, $limit + 1);
        rewind($stream);
        abort_if($body === false || strlen($body) > $limit, 413);

        return $next($request);
    }
}
