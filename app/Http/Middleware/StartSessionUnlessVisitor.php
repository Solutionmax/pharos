<?php

namespace App\Http\Middleware;

use App\Support\StatelessRoutes;
use Closure;
use Illuminate\Session\Middleware\StartSession;

/**
 * StartSession, except on the public pages for a visitor who has no session
 * yet: they get the page without a session row and without a cookie, and
 * the answer may be cached (see StatelessRoutes::VISITOR_PAGES).
 */
class StartSessionUnlessVisitor extends StartSession
{
    public function handle($request, Closure $next)
    {
        if (! StatelessRoutes::servesVisitor($request)) {
            return parent::handle($request, $next);
        }

        return StatelessRoutes::shareable($next($request));
    }
}
