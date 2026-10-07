<?php

namespace App\Http\Middleware;

use App\Services\Localization;
use Closure;
use Illuminate\Http\Request;

class UseLocale
{
    public function handle(Request $request, Closure $next)
    {
        $locale = $request->is('admin', 'admin/*') ? ($request->user()->locale ?? 'en') : 'en';

        return Localization::run($locale, fn () => $next($request));
    }
}
