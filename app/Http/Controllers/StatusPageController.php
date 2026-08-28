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

class StatusPageController extends Controller
{
    public function __construct(protected Uptime $uptime, protected Branding $branding) {}

    public function show(Request $request)
    {
        // Nobody has finished installing yet, and the public page carries no link
        // to the admin — so the domain they just pointed here is the only clue
        // they have. Once an account exists this never fires again.
        if (! User::exists()) {
            return redirect()->route('admin.install');
        }

        return $this->render(
            modules: $this->branding->modules(),
            theme: $this->branding->theme(),
            days: (int) Setting::get('page.incident_days', 5),
            chrome: true,
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

    protected function render(array $modules, string $theme, int $days, bool $chrome, ?array $hiddenGroups = null)
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
        $all = Component::where('enabled', true)->get();

        $bars = [];
        $percentages = [];
        foreach ($all as $component) {
            $bars[$component->id] = $this->uptime->bar($component);
            $percentages[$component->id] = $this->uptime->percentage($component);
        }

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
            'days' => $modules['page.show_incidents'] ? $this->incidentDays($days, $modules) : [],
            'modules' => $modules,
            'theme' => $theme,
            'chrome' => $chrome,
        ]);
    }

    /** @return array<string, \Illuminate\Support\Collection> */
    protected function incidentDays(int $span, array $modules): array
    {
        $incidents = Incident::public()
            ->with('updates', 'components')
            ->where('occurred_at', '>=', now()->subDays($span))
            ->orderByDesc('occurred_at')
            ->get()
            ->groupBy(fn (Incident $i) => $i->occurred_at->format('Y-m-d'));

        $days = [];
        for ($i = 0; $i < $span; $i++) {
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
