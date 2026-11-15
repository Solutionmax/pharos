<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use App\Services\PageContext;
use App\Services\TokenAccess;
use Closure;
use Illuminate\Http\Request;

/**
 * Subscriber addresses are personal data. Every method, GET included, needs a
 * write token whose owner administers the page, checked before any ID is bound.
 */
class SubscriberApiTokenAuth extends ApiTokenAuth
{
    public function handle(Request $request, Closure $next)
    {
        return parent::handle($request, function (Request $request) use ($next) {
            $token = $request->attributes->get('api_token');
            $pageId = app(PageContext::class)->id();

            if (! $token instanceof ApiToken || ! TokenAccess::allows($token, $pageId, write: true)) {
                return response()->json(['error' => 'Subscribers need a token with write access'], 403);
            }

            abort_unless($token->user?->canAdministerPage($pageId), 403);

            return $next($request);
        });
    }
}
