<?php

namespace App\Services;

use App\Enums\ComponentStatus;
use App\Models\Component;
use App\Models\Incident;
use App\Models\Maintenance;
use App\Models\Setting;
use App\Models\StatusPage;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * What the sign in page may say about the default status page, and which of
 * the two sign in layouts the installation uses.
 *
 * The rule is the public page itself: the sign in page shows a subset of what
 * that page shows to anyone, read the same way (public components, public
 * incidents, the page's own modules). While the default page is not published,
 * or hides its headline, there is nothing to say and the sign in page says nothing.
 */
class SignInStatus
{
    /** Installation setting, not a page setting: there is one sign in page. */
    public const SETTING = 'login.layout';

    /** One column with a single status line (the default). */
    public const SIMPLE = 'simple';

    /** The form beside a "Right now" panel on wide screens. */
    public const STATUS = 'status';

    public const LAYOUTS = [self::SIMPLE, self::STATUS];

    /** Names in the line under the headline before the rest is counted. */
    private const NAMES_SHOWN = 3;

    public function __construct(protected Uptime $uptime, protected Branding $branding) {}

    public static function layout(): string
    {
        $layout = Setting::get(self::SETTING, self::SIMPLE);

        return in_array($layout, self::LAYOUTS, true) ? $layout : self::SIMPLE;
    }

    /**
     * The headline of the default page, plus the strip, the latest incident and
     * the next planned work when $detailed. Null when the page is not public.
     *
     * @return array{tone: string, title: string, sub: string, url: string, strip: list<array{tone: string, h: ?int}>|null, uptime: ?string, days: int, incident: array{name: string, when: string}|null, maintenance: array{name: string, when: string}|null}|null
     */
    public function read(bool $detailed): ?array
    {
        $page = StatusPage::query()->find(StatusPage::defaultId());
        if ($page === null || ! $page->is_published || $page->archived_at !== null) {
            return null;
        }

        // Read as the public page reads itself: in its own context and its own zone.
        return app(PageContext::class)->run($page->id, fn () => Clock::withInstallationZone(
            fn () => $this->build($page, $detailed),
        ));
    }

    private function build(StatusPage $page, bool $detailed): ?array
    {
        $modules = $this->branding->modules();
        if (! $modules['page.show_overall']) {
            return null;
        }

        $now = Clock::now();
        $components = PublicComponents::query()->orderBy('position')->get();
        $troubled = $components->reject(fn (Component $c) => $c->status === ComponentStatus::Operational);
        $services = $modules['page.show_services'];
        $incidents = $modules['page.show_incidents'];
        $public = fn ($q) => $q->whereIn('components.id', PublicComponents::query()->select('id'));

        $open = $incidents
            ? Incident::public()->whereNull('resolved_at')->with(['updates', 'components' => $public])->orderByDesc('occurred_at')->get()
            : collect();
        $last = $incidents ? Incident::public()->whereNotNull('resolved_at')->latest('resolved_at')->first() : null;
        $maintenances = Maintenance::visible()->orderBy('starts_at')->get();
        $live = $maintenances->filter(fn (Maintenance $m) => $m->starts_at->lte(now()))->values();

        $worst = ComponentStatus::from((int) ($components->max(fn (Component $c) => $c->status->value) ?? 1));
        $hero = StatusTimeline::hero($worst, $open, $live, $last, $troubled->pluck('id'), $now);

        // With the services list on, the public page names every service, so the
        // headline may count them. Without it, it says exactly what the page says.
        [$title, $tone] = $services && $troubled->isNotEmpty()
            ? $this->countTitle($troubled)
            : [$hero['headline'], $hero['tone']];

        return [
            'tone' => $tone,
            'title' => $title,
            'sub' => $this->sub($services, $components, $troubled, $hero, $now),
            'url' => $page->publicUrl(),
            ...$this->strip($detailed && $modules['page.show_uptime'], $components),
            'incident' => $detailed ? $this->incident($open->first() ?? $last, $now) : null,
            'maintenance' => $detailed ? $this->maintenance($live->first() ?? $maintenances->first(), $now) : null,
        ];
    }

    /**
     * "2 services are down": the worst kind of trouble, counted.
     *
     * @param  Collection<int, Component>  $troubled
     * @return array{0: string, 1: string}
     */
    private function countTitle(Collection $troubled): array
    {
        $down = $troubled->filter(fn (Component $c) => $c->status->isDown());
        if ($down->contains(fn (Component $c) => $c->status === ComponentStatus::MajorOutage)) {
            return [$this->counted($down->count(), '1 service is down', ':n services are down'), 'b'];
        }
        if ($down->isNotEmpty()) {
            return [$this->counted($down->count(), '1 service is partly down', ':n services are partly down'), 'p'];
        }

        $slow = $troubled->filter(fn (Component $c) => $c->status === ComponentStatus::PerformanceIssues);
        if ($slow->isNotEmpty()) {
            return [$this->counted($slow->count(), '1 service is slow', ':n services are slow'), 'w'];
        }

        return [$this->counted($troubled->count(), '1 service is under maintenance', ':n services are under maintenance'), 'm'];
    }

    private function counted(int $n, string $one, string $many): string
    {
        return $n === 1 ? __($one) : __($many, ['n' => $n]);
    }

    /**
     * The line under the headline.
     *
     * @param  Collection<int, Component>  $components
     * @param  Collection<int, Component>  $troubled
     * @param  array{since: ?string, rest: ?string}  $hero
     */
    private function sub(bool $services, Collection $components, Collection $troubled, array $hero, CarbonInterface $now): string
    {
        if ($services && $troubled->isNotEmpty()) {
            $fine = $components->count() - $troubled->count();
            $line = $this->names($troubled).'.';

            return $fine === 0 ? $line : $line.' '.$this->counted($fine, '1 service is fine.', ':n services are fine.');
        }

        if ($hero['since'] !== null) {
            return $hero['since'].($hero['rest'] === '.' ? '.' : ', '.$hero['rest']);
        }

        $time = ['time' => $now->format('H:i')];

        return match (true) {
            ! $services || $components->isEmpty() => __('Checked at :time.', $time),
            $components->count() === 1 => __('1 service, checked at :time.', $time),
            default => __(':n services, checked at :time.', ['n' => $components->count(), ...$time]),
        };
    }

    /**
     * "Mail and Backups", worst first; past three, the rest is counted.
     *
     * @param  Collection<int, Component>  $troubled
     */
    private function names(Collection $troubled): string
    {
        $rank = fn (Component $c) => match (true) {
            $c->status === ComponentStatus::MajorOutage => 0,
            $c->status === ComponentStatus::PartialOutage => 1,
            $c->status === ComponentStatus::PerformanceIssues => 2,
            default => 3,
        };
        $names = $troubled->sortBy([fn ($a, $b) => $rank($a) <=> $rank($b), fn ($a, $b) => $a->position <=> $b->position])
            ->pluck('name')->values();

        if ($names->count() <= self::NAMES_SHOWN) {
            return $names->join(', ', __(' and '));
        }

        return __(':names and :n more', [
            'names' => $names->take(self::NAMES_SHOWN)->join(', '),
            'n' => $names->count() - self::NAMES_SHOWN,
        ]);
    }

    /**
     * The 90 days of every public component as one row, the way the public chart
     * draws "All services": the worst tone of the day, taller for longer losses.
     *
     * @param  Collection<int, Component>  $components
     * @return array{strip: list<array{tone: string, h: ?int}>|null, uptime: ?string, days: int}
     */
    private function strip(bool $show, Collection $components): array
    {
        if (! $show || $components->isEmpty()) {
            return ['strip' => null, 'uptime' => null, 'days' => Uptime::WINDOW_DAYS];
        }

        $bars = $this->uptime->barsFor($components);
        $overall = StatusTimeline::overall($bars);
        $percentage = Uptime::average(array_map($this->uptime->percentageOf(...), $bars));

        return [
            'strip' => array_map(fn (array $day) => [
                'tone' => $day['tone'] === 'unknown' ? 'none' : $day['tone'],
                'h' => StatusTimeline::height($day),
            ], $overall),
            'uptime' => $percentage === null ? null : Uptime::format($percentage),
            'days' => count($overall),
        ];
    }

    /** @return array{name: string, when: string}|null */
    private function incident(?Incident $incident, CarbonInterface $now): ?array
    {
        if ($incident === null) {
            return null;
        }

        $at = $incident->occurred_at;
        $when = $incident->resolved_at === null
            ? $this->day($at, $now, true).' · '.__($incident->status->label())
            : $this->day($at, $now, false).' · '.__('Solved in :duration', [
                'duration' => StatusTimeline::duration(StatusTimeline::incidentMinutes($incident, $now)),
            ]);

        return ['name' => $incident->name, 'when' => $when];
    }

    /** @return array{name: string, when: string}|null */
    private function maintenance(?Maintenance $work, CarbonInterface $now): ?array
    {
        if ($work === null) {
            return null;
        }

        $when = $work->starts_at->lte($now)
            ? __('Under way, until :time', ['time' => $work->ends_at->isSameDay($now) ? $work->ends_at->format('H:i') : $work->ends_at->translatedFormat('j M H:i')])
            : $this->day($work->starts_at, $now, true, ', ');

        return ['name' => $work->title, 'when' => $when];
    }

    /** "Today 14:02", "13 Nov", "18 Nov, 22:00", "4 Mar 2025". */
    private function day(CarbonInterface $at, CarbonInterface $now, bool $withTime, string $glue = ' '): string
    {
        $date = match (true) {
            $at->isSameDay($now) => __('Today'),
            $at->isSameYear($now) => $at->translatedFormat('j M'),
            default => $at->translatedFormat('j M Y'),
        };

        return $withTime ? $date.($at->isSameDay($now) ? ' ' : $glue).$at->format('H:i') : $date;
    }
}
