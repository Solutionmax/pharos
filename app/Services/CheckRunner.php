<?php

namespace App\Services;

use App\Enums\CheckType;
use App\Enums\ComponentStatus;
use App\Enums\IncidentStatus;
use App\Models\Check;
use App\Models\CheckResult;
use App\Models\Incident;
use App\Models\IncidentUpdate;
use App\Models\Maintenance;
use App\Models\UptimeDay;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Turns probe results into component status and, where asked for, into incidents.
 * This is the part Cachet does not have: nobody has to press a button.
 */
class CheckRunner
{
    /** Consecutive healthy results before an auto-opened incident closes itself. */
    public const RECOVERY_STREAK = 3;

    public function __construct(protected Probe $probe, protected OutgoingWebhook $webhook) {}

    /** @return int number of checks executed */
    public function runDue(?\DateTimeInterface $now = null): int
    {
        $now ??= now();
        $ran = 0;

        foreach (Check::with('component')->whereHas('component')->where('enabled', true)->get() as $check) {
            if (! $check->isDue($now) || ! $check->component?->enabled) {
                continue;
            }
            $this->runOne($check, $now);
            $ran++;
        }

        return $ran;
    }

    public function runOne(Check $check, ?\DateTimeInterface $now = null): ProbeResult
    {
        $now ??= now();
        $result = app(ProbeQuorum::class)->combine($check, $this->probe->run($check));

        if ($result->inconclusive) {
            if ($check->component->status !== ComponentStatus::UnderMaintenance) {
                $check->component->update(['status' => ComponentStatus::PerformanceIssues]);
            }
            if ($check->type !== CheckType::Heartbeat) {
                $check->update(['last_run_at' => $now]);
            }

            return $result;
        }
        if ($check->type === CheckType::Http) {
            $check->tls_expires_at = $result->tlsExpiresAt;
            $check->tls_warning = $result->tlsExpiresAt && $result->tlsExpiresAt <= now()->addDays(14) ? $result->message : null;
        }

        // Read before this run's own result is written: it is the reference for how
        // much wall time this evaluation stands for.
        $previousRun = CheckResult::where('component_id', $check->component_id)->max('checked_at');

        CheckResult::create([
            'component_id' => $check->component_id,
            'ok' => $result->ok,
            'latency_ms' => $result->latencyMs,
            'message' => $result->message,
            'checked_at' => $now,
        ]);

        if ($result->ok) {
            $check->consecutive_successes++;
            $check->consecutive_failures = 0;
        } else {
            $check->consecutive_failures++;
            $check->consecutive_successes = 0;
        }
        // A heartbeat's last_run_at is owned by whoever pings in, not by the runner.
        if ($check->type !== CheckType::Heartbeat) {
            $check->last_run_at = $now;
        }
        $check->save();
        if ($check->type === CheckType::Http) {
            $this->webhook->certificateExpiring($check);
        }

        $this->recordUptime($check, $result, $now, $previousRun);
        $this->applyStatus($check, $result, $now);

        return $result;
    }

    protected function recordUptime(Check $check, ProbeResult $result, \DateTimeInterface $now, ?string $previousRun): void
    {
        $now = Carbon::instance($now)->utc();
        $dayStart = $now->copy()->startOfDay();
        // Preserve the existing observation cap and UTC midnight boundary.
        $since = $previousRun ? Carbon::parse($previousRun, 'UTC') : $now->copy()->subSeconds($check->interval_seconds);
        $since = $since->max($dayStart)->max($now->copy()->subSeconds($check->interval_seconds));
        $seconds = (int) max(0, $since->diffInSeconds($now));
        $windows = Maintenance::whereNotNull('started_at')->where('starts_at', '<', $now)->where('ends_at', '>', $since)
            ->whereHas('components', fn ($q) => $q->whereKey($check->component_id))
            ->with(['components' => fn ($q) => $q->whereKey($check->component_id)])->limit(2001)->get();
        if ($windows->count() > 2000) {
            return; // Too many windows to establish accurate eligible credit; keep raw evidence only.
        }
        $excluded = MaintenanceIntervals::excluded($windows, $check->component_id, $since, $now);
        // A manually held maintenance state has no reliable earlier boundary.
        if ($check->component->status === ComponentStatus::UnderMaintenance && $excluded === 0) {
            return;
        }
        $seconds = max(0, $seconds - $excluded);
        if ($seconds === 0) {
            return;
        }
        $day = UptimeDay::firstOrCreate(
            ['component_id' => $check->component_id, 'day' => $dayStart],
            ['up_seconds' => 0, 'down_seconds' => 0, 'worst_status' => ComponentStatus::Operational->value],
        );

        $column = $result->ok ? 'up_seconds' : 'down_seconds';
        $day->increment($column, $seconds);

        if (! $result->ok) {
            $day->worst_status = ComponentStatus::MajorOutage;
            $day->save();
        }
    }

    protected function applyStatus(Check $check, ProbeResult $result, \DateTimeInterface $now): void
    {
        $component = $check->component;

        // Planned work: a probe failing inside a running maintenance window is
        // expected, and must neither flip the component nor open an incident.
        if (! $result->ok && $component->status === ComponentStatus::UnderMaintenance && Maintenance::holds($component->id)) {
            return;
        }

        if (! $result->ok && $check->consecutive_failures >= $check->retries) {
            if ($component->status !== ComponentStatus::MajorOutage) {
                $component->update(['status' => ComponentStatus::MajorOutage]);
                $this->openIncident($check, $result, $now);
            }

            return;
        }

        if (! $result->ok) {
            return;
        }

        // A passing check owns the status — including a hand-set "Degraded", which
        // the form says it will overwrite. Under maintenance is the one deliberate
        // state a probe cannot judge, so that stays until someone clears it.
        if (! in_array($component->status, [ComponentStatus::Operational, ComponentStatus::UnderMaintenance], true)) {
            $component->update(['status' => $result->degraded ? ComponentStatus::PerformanceIssues : ComponentStatus::Operational]);
        }

        if ($result->degraded && $component->status === ComponentStatus::Operational) {
            $component->update(['status' => ComponentStatus::PerformanceIssues]);
        }

        // Checked independently of the component status: the first healthy result
        // already flipped the component back, so nesting this inside that branch
        // meant the incident never closed.
        if ($check->consecutive_successes >= $this->recoveryStreak($check)) {
            $this->resolveIncident($check, $now);
        }
    }

    /**
     * A heartbeat that calls in has proved it is alive; the streak is hysteresis
     * against a flapping poll, and applying it here would keep a nightly backup
     * red for two more days after it demonstrably ran.
     */
    protected function recoveryStreak(Check $check): int
    {
        return $check->type === CheckType::Heartbeat ? 1 : self::RECOVERY_STREAK;
    }

    /**
     * When the streak that this run completes began. An outage starts at the first
     * failed check, not at the one that made Pharos sure, and ends at the first good
     * one, not after the checks that confirmed it: opened on the second failure and
     * closed on the third success, a site that hung for two minutes read as four.
     * Looks back no further than the streak itself, so the record moves by a few
     * intervals at most.
     */
    protected function streakStart(Check $check, bool $ok, int $length, \DateTimeInterface $now): CarbonInterface
    {
        $start = Carbon::instance($now);
        foreach (CheckResult::recentFor($check->component_id, max(1, $length))->reverse() as $result) {
            if ($result->ok !== $ok) {
                break;
            }
            $start = $result->checked_at;
        }

        return $start;
    }

    protected function openIncident(Check $check, ProbeResult $result, \DateTimeInterface $now): Incident
    {
        $since = $this->streakStart($check, false, $check->retries, $now);

        $incident = DB::transaction(function () use ($check, $result, $since) {
            $incident = Incident::create([
                'name' => __(':component unreachable', ['component' => $check->component->name], Localization::page()),
                'status' => IncidentStatus::Investigating,
                'impact' => 'major',
                'source' => 'check',
                // A hidden or switched off service keeps its outage off the public page.
                'visibility' => PublicComponents::automaticVisibility([$check->component_id]),
                'auto_resolve' => true,
                'grouping_key' => 'check:'.$check->component_id,
                'occurred_at' => $since,
            ]);

            $incident->components()->attach($check->component_id, [
                'status' => ComponentStatus::MajorOutage->value,
            ]);

            IncidentUpdate::create([
                'incident_id' => $incident->id,
                'status' => IncidentStatus::Investigating,
                'message' => CheckMessages::opened($check, $result),
                'automatic' => true,
                // At the start as well, so the course on the page begins where the duration does.
                'created_at' => $since,
            ]);

            return $incident;
        });

        $this->webhook->incidentChanged($incident->fresh('components'), 'incident.created');

        return $incident;
    }

    protected function resolveIncident(Check $check, \DateTimeInterface $now): void
    {
        $incident = Incident::where('grouping_key', 'check:'.$check->component_id)
            ->where('auto_resolve', true)
            ->whereNull('resolved_at')
            ->latest('occurred_at')
            ->first();

        if (! $incident) {
            return;
        }

        $until = $this->streakStart($check, true, $this->recoveryStreak($check), $now);
        // Someone still wrote about it after the first good check, or it would end before
        // it began: then it ends now, so "resolved" never sits before what came after it.
        $lastWord = $incident->updates()->max('created_at');
        if ($until->lt($incident->occurred_at) || ($lastWord && $until->lt(Carbon::parse($lastWord, 'UTC')))) {
            $until = Carbon::instance($now);
        }

        DB::transaction(function () use ($incident, $until, $check) {
            $incident->update(['status' => IncidentStatus::Resolved, 'resolved_at' => $until]);

            IncidentUpdate::create([
                'incident_id' => $incident->id,
                'status' => IncidentStatus::Resolved,
                'message' => CheckMessages::resolved($check, $this->recoveryStreak($check)),
                'automatic' => true,
                'created_at' => $until,
            ]);
        });

        $this->webhook->incidentChanged($incident->fresh('components'), 'incident.updated');
    }
}
