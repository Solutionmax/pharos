<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;

/**
 * A read without a session (a visitor page, StartSessionUnlessVisitor) has no
 * token to check and gets no XSRF-TOKEN cookie. Everything else, every write
 * above all, goes through the full check.
 */
class ValidateCsrfTokenIfSession extends ValidateCsrfToken
{
    public function handle($request, Closure $next)
    {
        if (! $request->hasSession() && $this->isReading($request)) {
            return $next($request);
        }

        return parent::handle($request, $next);
    }
}
