<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProbeJob;
use App\Models\ProbeLocation;
use App\Models\ProbeSample;
use App\Services\PageContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProbeController extends Controller
{
    private function location(Request $request): ProbeLocation
    {
        $token = $request->bearerToken();
        abort_unless(is_string($token) && strlen($token) <= 200, 401);
        $location = ProbeLocation::withoutGlobalScopes()->where('token_hash', hash('sha256', $token))->where('enabled', true)->first();
        abort_unless($location !== null, 401);
        abort_unless($location->canProbe(), 403);
        $location->update(['last_seen_at' => now()]);

        return $location;
    }

    public function jobs(Request $request)
    {
        $location = $this->location($request);

        return app(PageContext::class)->run($location->status_page_id, fn () => DB::transaction(function () use ($location) {
            // Serialize claims per credential. Issue only when a serial worker is ready.
            ProbeLocation::withoutGlobalScopes()->whereKey($location->id)->lockForUpdate()->firstOrFail();
            $last = ProbeJob::where('probe_location_id', $location->id)->select('check_id')->selectRaw('MAX(created_at) AS last_issued_at')->groupBy('check_id');
            $checks = $location->checks()->where('checks.enabled', true)->whereHas('component', fn ($q) => $q->where('enabled', true))->where('type', '!=', 'heartbeat')
                ->leftJoinSub($last, 'last_job', fn ($join) => $join->on('checks.id', '=', 'last_job.check_id'))
                ->orderBy('last_job.last_issued_at')->orderBy('checks.id')->limit(100)->get(['checks.*']);
            foreach ($checks as $check) {
                $recent = ProbeJob::where('check_id', $check->id)->where('probe_location_id', $location->id)
                    ->where(fn ($q) => $q->where('created_at', '>', now()->subSeconds($check->interval_seconds))
                        ->orWhere(fn ($pending) => $pending->whereNull('consumed_at')->where('expires_at', '>', now())))->exists();
                if ($recent) {
                    continue;
                }
                $job = ProbeJob::create(['id' => (string) Str::uuid(), 'check_id' => $check->id, 'probe_location_id' => $location->id, 'expires_at' => now()->addMinutes(2)]);

                return response()->json(['jobs' => [['id' => $job->id, 'type' => $check->type->value, 'target' => $check->target, 'timeout_seconds' => min(30, $check->timeout_seconds), 'expected_keyword' => $check->expected_keyword, 'dns_type' => $check->dns_type, 'dns_expected' => $check->dns_expected]]]);
            }

            return response()->json(['jobs' => []]);
        }));
    }

    public function results(Request $request)
    {
        $location = $this->location($request);
        $data = $request->validate(['job' => ['required', 'uuid'], 'ok' => ['required', 'boolean'], 'latency_ms' => ['nullable', 'integer', 'min:0', 'max:300000']]);

        return app(PageContext::class)->run($location->status_page_id, fn () => DB::transaction(function () use ($location, $data) {
            $job = ProbeJob::whereKey($data['job'])->where('probe_location_id', $location->id)->lockForUpdate()->first();
            abort_unless($job !== null, 404);
            abort_if($job->consumed_at || $job->expires_at->isPast(), 409);
            $check = $location->checks()->where('checks.id', $job->check_id)->where('checks.enabled', true)->whereHas('component', fn ($query) => $query->where('enabled', true))->first();
            abort_unless($check !== null, 404);
            $job->update(['consumed_at' => now()]);
            ProbeSample::create(['check_id' => $check->id, 'probe_location_id' => $location->id, 'ok' => $data['ok'], 'latency_ms' => $data['latency_ms'] ?? null, 'checked_at' => now()]);

            return response()->json(['ok' => true]);
        }));
    }
}
