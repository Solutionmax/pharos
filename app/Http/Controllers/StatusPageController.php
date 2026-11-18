<?php

namespace App\Http\Controllers;

use App\Enums\ComponentStatus;
use App\Http\Middleware\SecurityHeaders;
use App\Models\Component;
use App\Models\ComponentGroup;
use App\Models\Incident;
use App\Models\Maintenance;
use App\Models\Setting;
use App\Models\User;
use App\Services\Branding;
use App\Services\Clock;
use App\Services\PublicComponents;
use App\Services\StatusTimeline;
use App\Services\Uptime;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class StatusPageController extends Controller
{
    /** Far beyond any real history, and small enough that page × span never overflows. */
    public const MAX_PAGE = 10000;

    public function __construct(protected Uptime $uptime, protected Branding $branding) {}

    public function show(Request $request)
    {
        // Nobody has finished installing yet, and the public page carries no link
        // to the admin — so the domain they just pointed here is the only clue
        // they have. Once an account exists this never fires again.
        if (! User::exists()) {
            return redirect()->route('admin.install');
        }

        // ?page=2 is the previous span of days, ?page=3 the one before that. Only
        // whole numbers from 1: anything else is a mistyped link, not a page.
        $page = $request->query('page', '1');
        abort_unless(is_string($page) && ctype_digit($page) && (int) $page >= 1 && (int) $page <= self::MAX_PAGE, 404);

        return $this->render(
            modules: $this->branding->modules(),
            theme: $this->branding->theme(),
            days: (int) Setting::get('page.incident_days', 5),
            chrome: true,
            page: (int) $page,
        );
    }

    /**
     * The same page rendered from unsaved form values, for the preview beside the
     * form on the Status page screen. Behind auth, and it writes nothing: what you
     * see here is the real template, not a mock-up of it.
     */
    public function preview(Request $request)
    {
        // Read the array directly rather than through dotted keys: PHP turns a dot
        // in a query parameter name into an underscore, and Laravel reads a dot as
        // nesting. Neither survives "page.show_overall".
        $flags = $request->query('m', []);
        $flags = is_array($flags) ? $flags : [];

        $modules = [];
        foreach (array_keys(Branding::MODULES) as $key) {
            $modules[$key] = ($flags[$key] ?? '0') === '1';
        }

        $theme = $request->query('theme', 'system');

        $groupFlags = $request->query('g', []);
        $groupFlags = is_array($groupFlags) ? $groupFlags : [];
        $hidden = array_keys(array_filter($groupFlags, fn ($on) => $on !== '1'));

        // What customers will see, so in the installation zone even when the admin
        // looking at the preview reads the rest of the admin in a zone of their own.
        $page = Clock::withInstallationZone(fn () => (string) $this->render(
            modules: $modules,
            theme: in_array($theme, ['system', 'light', 'dark'], true) ? $theme : 'system',
            days: max(1, min(30, (int) $request->query('days', 5))),
            chrome: false,
            hiddenGroups: array_map('strval', $hidden),
        ));

        // Every other page refuses to be framed (SecurityHeaders). This one is
        // built to sit in the Status page screen's iframe, so it alone allows its own
        // origin; the middleware leaves headers that are already set untouched.
        return response($page)->withHeaders([
            'X-Frame-Options' => 'SAMEORIGIN',
            'Content-Security-Policy' => SecurityHeaders::policy("'self'"),
        ]);
    }

    /**
     * The saved page for the fallback copy (FallbackCopy): the real template
     * without the parts that need this server, the subscribe form, the admin bar
     * and the pager. StaticPage then removes the scripts and inlines the rest.
     */
    public function snapshot(): string
    {
        return (string) $this->render(
            modules: array_merge($this->branding->modules(), ['page.show_subscribe' => false]),
            theme: $this->branding->theme(),
            days: (int) Setting::get('page.incident_days', 5),
            chrome: false,
        );
    }

    protected function render(array $modules, string $theme, int $days, bool $chrome, ?array $hiddenGroups = null, int $page = 1)
    {
        $groups = $modules['page.show_services']
            ? ComponentGroup::with(['components' => fn ($q) => $q->where('enabled', true)])
                ->where('visible', true)
                ->orderBy('position')->get()
            : collect();

        // Per-service overrides, used by the preview so a single service can be
        // switched off without saving anything.
        if ($hiddenGroups !== null) {
            $groups = $groups->reject(fn ($group) => in_array((string) $group->id, $hiddenGroups, true));
        }

        // Components that are in no service at all. They are still published —
        // "Ungrouped" is the default on the component form, and deleting a
        // service drops its components here on purpose.
        $loose = $modules['page.show_services']
            ? Component::whereNull('component_group_id')->where('enabled', true)
                ->orderBy('position')->get()
            : collect();

        // Uptime is needed for the headline percentage even when the per-component
        // bars are switched off, so it comes from every enabled component —
        // including the ungrouped ones, which used to be missing from the maths.
        // Only what the visitor can see: a red component in a hidden service
        // must not turn the headline red without anything on the page to explain it.
        $all = Component::where('enabled', true)
            ->where(fn ($q) => $q->whereNull('component_group_id')
                ->orWhereHas('group', fn ($g) => $g->where('visible', true)))
            ->get();

        if ($hiddenGroups !== null) {
            $all = $all->reject(fn ($c) => in_array((string) $c->component_group_id, $hiddenGroups, true));
        }

        // One query for all of them, and the percentage from the bar in hand:
        // this used to be two queries per component on the busiest page.
        $bars = $this->uptime->barsFor($all);
        $percentages = array_map($this->uptime->percentageOf(...), $bars);

        $overall = Uptime::average($percentages);

        // max('status') compared the enum *objects*, which PHP cannot order, so
        // the headline followed whichever component happened to come first.
        $worst = ComponentStatus::from((int) ($all->max(fn (Component $c) => $c->status->value) ?? 1));

        $now = Clock::now();
        $publicComponents = fn ($q) => $q->whereIn('components.id', PublicComponents::query()->select('id'));

        // Every open incident leads the log under Ongoing, however old: a component
        // can stay red for longer than the window, and the page must never show a
        // red service without the incident that explains it. The days below hold
        // only what is over, so nothing appears twice.
        $open = $modules['page.show_incidents']
            ? Incident::public()->whereNull('resolved_at')
                ->with(['updates', 'components' => $publicComponents])->orderByDesc('occurred_at')->get()
            : collect();

        // Planned work of this page that is still to come or under way.
        $maintenances = Maintenance::visible()->with(['components' => $publicComponents])->orderBy('starts_at')->get();
        $live = $maintenances->filter(fn (Maintenance $m) => $m->starts_at->lte(now()))->values();
        $last = $modules['page.show_incidents']
            ? Incident::public()->whereNotNull('resolved_at')->with(['components' => $publicComponents])->latest('resolved_at')->first()
            : null;
        $incidentDays = $modules['page.show_incidents'] ? $this->incidentDays($days, $modules, $page) : [];
        // A quiet span says so once, in the head of the log, instead of a row per empty day.
        $quiet = $page === 1 && $modules['page.show_incidents'] && $open->isEmpty()
            && collect($incidentDays)->every(fn (Collection $day) => $day->isEmpty());

        $overallBar = StatusTimeline::overall($bars);
        // Every public incident that ran on a day of the chart, also one that began before it.
        $windowIncidents = $modules['page.show_incidents']
            ? Incident::public()->with(['components' => $publicComponents])
                ->where(fn ($q) => $q->whereNull('resolved_at')
                    ->orWhere('resolved_at', '>=', Carbon::today()->subDays(Uptime::WINDOW_DAYS - 1)))
                ->get()
            : collect();

        return view('status', [
            'groups' => $groups,
            'loose' => $loose,
            'bars' => $bars,
            'percentages' => $percentages,
            'overall' => $overall,
            'overallBar' => $overallBar,
            'dayNotes' => StatusTimeline::dayNotes($overallBar, $windowIncidents, $now, $bars, $all->pluck('name', 'id')->all()),
            'worst' => $worst,
            'now' => $now,
            'hero' => StatusTimeline::hero(
                $worst, $open, $live, $last,
                $all->reject(fn (Component $c) => $c->status === ComponentStatus::Operational)->pluck('id'),
                $now,
            ),
            'live' => $live,
            'planned' => $maintenances->diff($live)->values(),
            'ongoing' => $page === 1 ? $open : collect(),
            'days' => $incidentDays,
            'quiet' => $quiet,
            'quietDays' => max($days, $last?->resolved_at ? (int) $last->resolved_at->startOfDay()->diffInDays(Carbon::today(Clock::timezone())) : 0),
            // The page of the log that holds the last incident, for "Older incidents" on a quiet page.
            'lastPage' => $quiet && $last ? min(self::MAX_PAGE, intdiv((int) $last->occurred_at->startOfDay()->diffInDays(Carbon::today(Clock::timezone())), $days) + 1) : null,
            'description' => $this->description($groups->filter(fn (ComponentGroup $g) => $g->components->isNotEmpty())->pluck('name')->merge($loose->pluck('name'))),
            'page' => $page,
            // The "Older" link is offered only when a public incident exists before
            // the oldest day on this page; otherwise it would lead to empty pages.
            'hasOlder' => $chrome && $modules['page.show_incidents']
                && Incident::public()->whereNotNull('resolved_at')->where('occurred_at', '<', $this->windowStart($days, $page))->exists(),
            'modules' => $modules,
            'theme' => $theme,
            'chrome' => $chrome,
        ]);
    }

    /** What a search result or a link preview says under the title: the services this page watches. */
    protected function description(Collection $names): string
    {
        $names = $names->filter()->unique()->values();
        $text = __('Live status of :services.', ['services' => $names->isEmpty() ? $this->branding->name() : $names->join(', ', __(' and '))]);

        return Str::limit($text, 155, '…');
    }

    /**
     * Midnight, in the customer's zone, of the oldest day on the given page.
     * Page 1 covers today and the span-1 days before it; page 2 the span before that.
     */
    protected function windowStart(int $span, int $page): Carbon
    {
        return Carbon::today(Clock::timezone())->subDays($span * $page - 1);
    }

    /** @return array<string, Collection> */
    protected function incidentDays(int $span, array $modules, int $page = 1): array
    {
        $start = $this->windowStart($span, $page);
        $offset = $span * ($page - 1);

        $incidents = Incident::public()
            ->with(['updates', 'components' => fn ($q) => $q->whereIn('components.id', PublicComponents::query()->select('id'))])
            ->whereNotNull('resolved_at')
            ->where('occurred_at', '>=', $start)
            ->where('occurred_at', '<', $start->copy()->addDays($span))
            ->orderByDesc('occurred_at')
            ->get()
            ->groupBy(fn (Incident $i) => $i->occurred_at->format('Y-m-d'));

        $days = [];
        for ($i = $offset; $i < $offset + $span; $i++) {
            // Days are the customer's days: occurred_at is read in their zone above.
            $key = Carbon::today(Clock::timezone())->subDays($i)->format('Y-m-d');
            $day = $incidents->get($key, collect());

            // With empty days switched off, a quiet week collapses instead of
            // filling the page with "No incidents".
            if ($day->isEmpty() && ! $modules['page.show_empty_days']) {
                continue;
            }

            $days[$key] = $day;
        }

        return $days;
    }
}
