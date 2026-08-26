<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use Closure;
use Illuminate\Http\Request;

class ApiTokenAuth
{
    public function handle(Request $request, Closure $next)
    {
        // Accept both forms: Bearer for new clients, X-Cachet-Token so existing
        // Cachet 2.x scripts and n8n workflows keep working unchanged.
        $plain = $request->bearerToken() ?: $request->header('X-Cachet-Token');

        if (! $plain) {
            return response()->json(['error' => 'Missing API token'], 401);
        }

        $token = ApiToken::findByPlaintext($plain);

        if (! $token) {
            return response()->json(['error' => 'Invalid API token'], 401);
        }

        $token->forceFill(['last_used_at' => now()])->save();
        $request->attributes->set('api_token', $token);

        return $next($request);
    }
}
