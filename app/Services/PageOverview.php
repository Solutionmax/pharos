<?php

namespace App\Services;

use App\Enums\ComponentStatus;
use App\Models\Component;
use App\Models\ComponentGroup;
use App\Models\Incident;
use App\Models\Subscriber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Everything the Overview screen shows for the selected page, gathered in one
 * place so the numbers can be tested without rendering.
 *
 * Every query goes through the page scope of the models, so nothing from
 * another page can end up here.
 */
class PageOverview
{
    /** Days in the per service strips; the page wide strip uses Uptime::WINDOW_DAYS. */
    public const SERVICE_DAYS = 30;

    public function __construct(protected Uptime $uptime) {}

    /** @return array<string, mixed> */
    public function build(?Carbon $today = null): array
    {
        $components = Component::query()->where('enabled', true)
            ->with('check:id,component_id,type')
            ->orderBy('position')->get();
        $bars = $this->uptime->barsFor($components, $today);
        $percentages = array_map($this->uptime->percentageOf(...), $bars);

        $counts = [];
        foreach (ComponentStatus::cases() as $case) {
            $counts[$case->value] = $components->filter(fn (Component $c) => $c->status === $case)->count();
        }
        $worst = ComponentStatus::from((int) ($components->max(fn (Component $c) => $c->status->value) ?? 1));

        $open = Incident::query()->whereNull('resolved_at')
            ->with('updates', 'components')->orderByDesc('occurred_at')->get();
        $recent = Incident::query()->where('occurred_at', '>=', now()->subDays(30))->get();
        $resolved = $recent->whereNotNull('resolved_at');

        return [
            'components' => $components,
            'counts' => $counts,
            'worst' => $worst,
            'affected' => $components->filter(fn (Component $c) => $c->status !== ComponentStatus::Operational)->values(),
            'operational' => $counts[ComponentStatus::Operational->value],
            'open' => $open,
            'lastResolved' => Incident::query()->whereNotNull('resolved_at')->orderByDesc('resolved_at')->first(),
            'days' => $this->uptime->aggregate($bars),
            'uptime' => Uptime::average($percentages),
            'incidents30' => $recent->count(),
            // Same figure as the Incidents screen: mean minutes from start to resolved, last 30 days.
            'mttr' => $resolved->isEmpty() ? null : (int) round(
                $resolved->avg(fn (Incident $i) => $i->occurred_at->diffInMinutes($i->resolved_at)),
            ),
            'subscribers' => Subscriber::query()->active()->count(),
            'subscriptions' => Subscriptions::enabled(),
            'services' => $this->services($components, $bars),
        ];
    }

    /** "34m", "5h 10m", "2d 3h": short enough for a card. */
    public static function duration(?int $minutes): string
    {
        if ($minutes === null) {
            return 'No data';
        }
        if ($minutes < 60) {
            return $minutes.'m';
        }
        if ($minutes < 1440) {
            return intdiv($minutes, 60).'h'.($minutes % 60 ? ' '.($minutes % 60).'m' : '');
        }

        return intdiv($minutes, 1440).'d'.(intdiv($minutes % 1440, 60) ? ' '.intdiv($minutes % 1440, 60).'h' : '');
    }

    /**
     * Components per service in page order, ungrouped ones last, each with the
     * last SERVICE_DAYS of its bar and the uptime over those days.
     *
     * @param  Collection<int, Component>  $components
     * @param  array<int, array<int, array{day: string, tone: string, pct: float, known: bool}>>  $bars
     * @return list<array{name: string, rows: list<array{component: Component, days: array, uptime: ?float}>}>
     */
    protected function services(Collection $components, array $bars): array
    {
        $sections = [];
        $section = function (string $name, ?int $groupId) use ($components, $bars, &$sections): void {
            $rows = [];
            foreach ($components->where('component_group_id', $groupId) as $component) {
                $days = array_slice($bars[$component->id], -self::SERVICE_DAYS);
                $rows[] = ['component' => $component, 'days' => $days, 'uptime' => $this->uptime->percentageOf($days)];
            }
            if ($rows !== []) {
                $sections[] = ['name' => $name, 'rows' => $rows];
            }
        };
        foreach (ComponentGroup::query()->orderBy('position')->get(['id', 'name']) as $group) {
            $section($group->name, $group->id);
        }
        $section('Not in a service', null);

        return $sections;
    }
}
