<?php

namespace App\Http\Controllers\Api;

use App\Enums\ComponentStatus;
use App\Http\Controllers\Controller;
use App\Models\Component;
use App\Services\ReportedStatus;
use Illuminate\Http\Request;

/**
 * Prometheus Alertmanager (webhook v4) and Grafana unified alerting post the
 * same envelope: a list of alerts, each with a status and labels. The label
 * pharos_component names the component, by id or exact name, on the page of
 * the token. The status is saved the way the Uptime Kuma receiver saves it.
 */
class AlertController extends Controller
{
    public const COMPONENT_LABEL = 'pharos_component';

    /** One notification is one alert group; this is far above any real one. */
    public const MAX_ALERTS = 500;

    public function __invoke(Request $request)
    {
        $data = $request->validate([
            'alerts' => ['required', 'array', 'max:'.self::MAX_ALERTS],
            'alerts.*' => ['required', 'array'],
            'alerts.*.status' => ['required', 'string', 'in:firing,resolved'],
            'alerts.*.labels' => ['present', 'array'],
            'alerts.*.labels.'.self::COMPONENT_LABEL => ['nullable', 'string', 'max:255'],
            'alerts.*.labels.severity' => ['nullable', 'string', 'max:64'],
            'alerts.*.labels.alertname' => ['nullable', 'string', 'max:255'],
            'alerts.*.fingerprint' => ['nullable', 'string', 'max:128'],
        ]);

        /** @var array<int, array{component: Component, status: ComponentStatus}> $wanted */
        $wanted = [];
        $ignored = [];
        foreach ($data['alerts'] as $i => $alert) {
            $labels = $alert['labels'];
            $name = $labels['alertname'] ?? $alert['fingerprint'] ?? '#'.$i;
            $key = trim((string) ($labels[self::COMPONENT_LABEL] ?? ''));
            $component = $key === '' ? null : $this->component($key);
            $reason = match (true) {
                $key === '' => 'no_component_label',
                $component === null => 'unknown_component',
                $component->isChecked() => 'checked_component',
                default => null,
            };
            if ($reason !== null || $component === null) {
                $ignored[] = ['alert' => $name, 'component' => $key === '' ? null : $key, 'reason' => $reason];

                continue;
            }

            // Several alerts for one component in one notification: the worst one wins.
            $status = self::status($alert);
            $previous = $wanted[$component->id]['status'] ?? null;
            if ($previous === null || $status->value > $previous->value) {
                $wanted[$component->id] = ['component' => $component, 'status' => $status];
            }
        }

        $token = $request->attributes->get('api_token');
        $applied = [];
        foreach ($wanted as $id => $entry) {
            $changed = ReportedStatus::apply($entry['component'], $entry['status'], $token, 'webhook');
            $applied[] = ['component' => $id, 'name' => $entry['component']->name, 'status' => $entry['status']->value, 'changed' => $changed];
        }

        return response()->json(['ok' => true, 'applied' => $applied, 'ignored' => $ignored]);
    }

    /**
     * Resolved is operational. Firing follows the severity label: warning is a
     * partial outage, info is slow, and critical, or no severity at all, is down.
     *
     * @param  array{status: string, labels: array<string, mixed>}  $alert
     */
    public static function status(array $alert): ComponentStatus
    {
        if ($alert['status'] === 'resolved') {
            return ComponentStatus::Operational;
        }

        return match (strtolower(trim((string) ($alert['labels']['severity'] ?? '')))) {
            'warning' => ComponentStatus::PartialOutage,
            'info' => ComponentStatus::PerformanceIssues,
            default => ComponentStatus::MajorOutage,
        };
    }

    /** By id first, then by exact name; the page scope keeps other pages out. */
    protected function component(string $key): ?Component
    {
        if (ctype_digit($key) && $byId = Component::find((int) $key)) {
            return $byId;
        }

        return Component::where('name', $key)->orderBy('id')->get()
            ->first(fn (Component $component) => $component->name === $key);
    }
}
