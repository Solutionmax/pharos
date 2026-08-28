<?php

namespace App\Services;

use App\Enums\ComponentStatus;
use App\Enums\IncidentStatus;
use App\Models\Check;
use App\Models\CheckResult;
use App\Models\Incident;
use App\Models\IncidentUpdate;
use App\Models\UptimeDay;
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

        foreach (Check::with('component')->where('enabled', true)->get() as $check) {
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
        $result = $this->probe->run($check);

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
        if ($check->type !== \App\Enums\CheckType::Heartbeat) {
            $check->last_run_at = $now;
        }
        $check->save();

        $this->recordUptime($check, $result, $now);
        $this->applyStatus($check, $result, $now);

        return $result;
    }

    protected function recordUptime(Check $check, ProbeResult $result, \DateTimeInterface $now): void
    {
        $day = UptimeDay::firstOrCreate(
            [
                'component_id' => $check->component_id,
                // Carbon, not a string: the date cast stores "Y-m-d 00:00:00",
                // so a bare "Y-m-d" never matches the row that is already there.
                'day' => \Illuminate\Support\Carbon::instance(
                    $now instanceof \DateTimeImmutable ? \DateTime::createFromImmutable($now) : $now
                )->startOfDay(),
            ],
            ['up_seconds' => 0, 'down_seconds' => 0, 'worst_status' => ComponentStatus::Operational->value],
        );

        $column = $result->ok ? 'up_seconds' : 'down_seconds';
        $day->increment($column, $check->interval_seconds);

        if (! $result->ok) {
            $day->worst_status = ComponentStatus::MajorOutage;
            $day->save();
        }
    }

    protected function applyStatus(Check $check, ProbeResult $result, \DateTimeInterface $now): void
    {
        $component = $check->component;

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

        if ($component->status->isDown()) {
            $component->update(['status' => ComponentStatus::Operational]);
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
        return $check->type === \App\Enums\CheckType::Heartbeat ? 1 : self::RECOVERY_STREAK;
    }

    protected function openIncident(Check $check, ProbeResult $result, \DateTimeInterface $now): Incident
    {
        $incident = DB::transaction(function () use ($check, $result, $now) {
            $incident = Incident::create([
                'name' => "{$check->component->name} unreachable",
                'status' => IncidentStatus::Investigating,
                'impact' => 'major',
                'source' => 'check',
                'auto_resolve' => true,
                'grouping_key' => 'check:'.$check->component_id,
                'occurred_at' => $now,
            ]);

            $incident->components()->attach($check->component_id, [
                'status' => ComponentStatus::MajorOutage->value,
            ]);

            IncidentUpdate::create([
                'incident_id' => $incident->id,
                'status' => IncidentStatus::Investigating,
                'message' => $result->message
                    ? "Automatic check failed: {$result->message}."
                    : 'Automatic check failed.',
                'automatic' => true,
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

        DB::transaction(function () use ($incident, $now) {
            $incident->update(['status' => IncidentStatus::Resolved, 'resolved_at' => $now]);

            IncidentUpdate::create([
                'incident_id' => $incident->id,
                'status' => IncidentStatus::Resolved,
                'message' => 'The component responded normally again for '
                    .self::RECOVERY_STREAK.' consecutive checks.',
                'automatic' => true,
            ]);
        });

        $this->webhook->incidentChanged($incident->fresh('components'), 'incident.updated');
    }
}
