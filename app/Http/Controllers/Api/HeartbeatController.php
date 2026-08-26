<?php

namespace App\Http\Controllers\Api;

use App\Enums\CheckType;
use App\Http\Controllers\Controller;
use App\Models\Check;

class HeartbeatController extends Controller
{
    /**
     * Inverted check: a cron job calls in to say it is alive. Silence is the alarm.
     * Deliberately unauthenticated but unguessable, so a backup script needs no token.
     */
    public function ping(string $token)
    {
        $check = Check::where('type', CheckType::Heartbeat)->where('target', $token)->first();

        if (! $check) {
            return response()->json(['error' => 'Unknown heartbeat'], 404);
        }

        $check->forceFill([
            'last_run_at' => now(),
            'consecutive_failures' => 0,
            'consecutive_successes' => $check->consecutive_successes + 1,
        ])->save();

        return response()->json(['ok' => true]);
    }
}
