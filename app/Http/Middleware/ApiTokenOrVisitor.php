<?php

namespace App\Http\Middleware;

use App\Services\PageContext;
use Closure;
use Illuminate\Http\Request;

/**
 * A read that a visitor may make too, like /components: without a token it goes
 * through on a page visitors can open, and the controller answers with what that
 * page shows. A token that is sent is checked as always.
 */
class ApiTokenOrVisitor extends ApiTokenAuth
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->bearerToken() || $request->header('X-Cachet-Token')) {
            return parent::handle($request, $next);
        }

        $page = app(PageContext::class)->page();
        abort_if(! $page->is_published || $page->archived_at, 404);

        return $next($request);
    }
}
