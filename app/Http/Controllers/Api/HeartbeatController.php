<?php

namespace App\Http\Controllers\Api;

use App\Enums\CheckType;
use App\Http\Controllers\Controller;
use App\Models\Check;
use App\Services\CheckRunner;

class HeartbeatController extends Controller
{
    /**
     * Inverted check: a cron job calls in to say it is alive. Silence is the alarm.
     * Deliberately unauthenticated but unguessable, so a backup script needs no token.
     */
    public function ping(string $token, CheckRunner $runner)
    {
        $check = Check::with('component')
            ->where('type', CheckType::Heartbeat)->where('target', $token)->first();

        if (! $check) {
            return response()->json(['error' => 'Unknown heartbeat'], 404);
        }

        // Stamp the arrival, then let the runner draw the conclusion from it, so the
        // counters and the status live in one place instead of two that drift apart.
        $check->forceFill(['last_run_at' => now()])->save();
        $runner->runOne($check);

        return response()->json(['ok' => true]);
    }
}
