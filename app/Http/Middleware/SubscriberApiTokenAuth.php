<?php

namespace App\Http\Middleware;

use App\Services\PageContext;
use Closure;
use Illuminate\Http\Request;

/** Subscriber addresses need page-admin authority before any ID is bound. */
class SubscriberApiTokenAuth extends ApiTokenAuth
{
    public function handle(Request $request, Closure $next)
    {
        return parent::handle($request, function (Request $request) use ($next) {
            abort_unless($request->attributes->get('api_token')?->user?->canAdministerPage(app(PageContext::class)->id()), 403);

            return $next($request);
        });
    }
}
