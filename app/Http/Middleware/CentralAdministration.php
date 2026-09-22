<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CentralAdministration
{
    public function handle(Request $request, Closure $next)
    {
        $central = strtolower((string) parse_url(config('app.url'), PHP_URL_HOST));
        if (($request->is('admin') || $request->is('admin/*')) && strtolower($request->getHost()) !== $central) {
            abort_unless($request->isMethod('GET'), 404);

            return redirect()->away(rtrim(config('app.url'), '/').'/'.$request->path());
        }

        return $next($request);
    }
}
