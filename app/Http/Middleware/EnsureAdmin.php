<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Guards what touches the installation itself: accounts, the audit log, updates,
 * API tokens and branding. Everything operational stays open to every account.
 */
class EnsureAdmin
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless($request->user()?->isAdmin(), 403);

        return $next($request);
    }
}
