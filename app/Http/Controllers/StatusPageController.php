<?php

namespace App\Http\Controllers;

use App\Enums\ComponentStatus;
use App\Models\Component;
use App\Models\ComponentGroup;
use App\Models\Incident;
use App\Models\Setting;
use App\Models\User;
use App\Services\Branding;
use App\Services\Clock;
use App\Services\Uptime;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

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

        $page = $this->render(
            modules: $modules,
            theme: in_array($theme, ['system', 'light', 'dark'], true) ? $theme : 'system',
            days: max(1, min(30, (int) $request->query('days', 5))),
            chrome: false,
            hiddenGroups: array_map('strval', $hidden),
        );

        // Every other page refuses to be framed (SecurityHeaders). This one is
        // built to sit in the Status page screen's iframe, so it alone allows its own
        // origin; the middleware leaves headers that are already set untouched.
        return response($page)->withHeaders([
            'X-Frame-Options' => 'SAMEORIGIN',
            'Content-Security-Policy' => "frame-ancestors 'self'",
        ]);
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

        $overall = $percentages === [] ? 100.0 : round(array_sum($percentages) / count($percentages), 2);

        // max('status') compared the enum *objects*, which PHP cannot order, so
        // the headline followed whichever component happened to come first.
        $worst = ComponentStatus::from((int) ($all->max(fn (Component $c) => $c->status->value) ?? 1));

        return view('status', [
            'groups' => $groups,
            'loose' => $loose,
            'bars' => $bars,
            'percentages' => $percentages,
            'overall' => $overall,
            'worst' => $worst,
            // An incident that is still open when it falls out of the history window
            // is pinned above the days: a component can stay red for longer than the
            // window, and the page must never show a red service without the
            // incident that explains it. Recent ones simply sit under their day.
            // Only on the first page: on an older page the same incident may sit
            // under its own day, and it must not appear twice.
            'ongoing' => $modules['page.show_incidents'] && $page === 1
                ? Incident::public()->whereNull('resolved_at')
                    ->where('occurred_at', '<', $this->windowStart($days, 1))
                    ->with('updates', 'components')->orderByDesc('occurred_at')->get()
                : collect(),
            'days' => $modules['page.show_incidents'] ? $this->incidentDays($days, $modules, $page) : [],
            'page' => $page,
            // The "Older" link is offered only when a public incident exists before
            // the oldest day on this page; otherwise it would lead to empty pages.
            'hasOlder' => $chrome && $modules['page.show_incidents']
                && Incident::public()->where('occurred_at', '<', $this->windowStart($days, $page))->exists(),
            'modules' => $modules,
            'theme' => $theme,
            'chrome' => $chrome,
        ]);
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
            ->with('updates', 'components')
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
