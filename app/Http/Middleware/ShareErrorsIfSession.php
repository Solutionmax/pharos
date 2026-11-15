<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\ViewErrorBag;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/** Without a session (a visitor page, StartSessionUnlessVisitor) there are no errors to share: an empty bag. */
class ShareErrorsIfSession extends ShareErrorsFromSession
{
    public function handle($request, Closure $next)
    {
        if ($request->hasSession()) {
            return parent::handle($request, $next);
        }

        $this->view->share('errors', new ViewErrorBag);

        return $next($request);
    }
}
