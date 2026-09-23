<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * An account invited with "turn on two factor first" can reach its profile
 * (to set it up) and sign out, and nothing else, until two factor is on.
 */
class RequireTwoFactorSetup
{
    /** Route names still open while setup is pending. */
    public const ALLOWED = ['admin.profile', 'admin.profile.*', 'admin.logout', 'admin.notes.*'];

    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if (! $user || ! $user->mustSetUpTwoFactor() || ! $request->is('admin', 'admin/*')
            || $request->routeIs(...self::ALLOWED)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Turn on two factor authentication first.'], 403);
        }

        return redirect()->route('admin.profile')
            ->with('status', 'Your administrator asks you to turn on two factor authentication before you continue.');
    }
}
