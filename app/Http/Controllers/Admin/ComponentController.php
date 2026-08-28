<?php

namespace App\Http\Controllers\Admin;

use App\Enums\CheckType;
use App\Enums\ComponentStatus;
use App\Enums\IncidentStatus;
use App\Http\Controllers\Controller;
use App\Models\Check;
use App\Models\Component;
use App\Models\ComponentGroup;
use App\Models\Incident;
use App\Models\IncidentUpdate;
use App\Services\CheckHistory;
use App\Services\Uptime;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ComponentController extends Controller
{
    public function __construct(protected Uptime $uptime) {}

    public function index()
    {
        $components = Component::with('group', 'check')->orderBy('position')->get();
        $enabled = $components->where('enabled', true);

        return view('admin.components', [
            'components' => $components,
            'uptime' => $components->mapWithKeys(fn ($c) => [$c->id => $this->uptime->percentage($c)]),
            // Last 30 days only: at 132px a 90-day strip gives each day 1.4px,
            // which is decoration rather than information.
            'strips' => $components->mapWithKeys(
                fn ($c) => [$c->id => array_slice($this->uptime->bar($c), -30)],
            ),
            'summary' => [
                'total' => $components->count(),
                'down' => $enabled->filter(fn ($c) => $c->status->isDown())->count(),
                'degraded' => $enabled->where('status', ComponentStatus::PerformanceIssues)->count(),
                'checked' => $components->filter(fn ($c) => $c->check?->enabled)->count(),
                'uptime' => $enabled->isEmpty() ? 100.0 : round(
                    $enabled->avg(fn ($c) => $this->uptime->percentage($c)), 2,
                ),
            ],
        ]);
    }

    public function create()
    {
        return view('admin.component-form', [
            'component' => new Component,
            'groups' => ComponentGroup::orderBy('position')->get(),
            'knownTags' => $this->knownTags(),
        ]);
    }

    public function edit(Component $component)
    {
        return view('admin.component-form', [
            'component' => $component->load('check'),
            'groups' => ComponentGroup::orderBy('position')->get(),
            'knownTags' => $this->knownTags(),
            // Only a component with a check has runs to show; a manual one gets no panel.
            'recent' => $component->check ? CheckHistory::strip($component) : null,
        ]);
    }

    /**
     * Every tag already in use, so the form can offer them instead of asking
     * people to remember how they spelled it last time.
     *
     * @return list<string>
     */
    protected function knownTags(): array
    {
        return Component::whereNotNull('tags')->pluck('tags')
            ->flatMap(fn ($raw) => array_map('trim', explode(',', (string) $raw)))
            ->filter()->unique()->sort(SORT_NATURAL | SORT_FLAG_CASE)->values()->all();
    }

    /**
     * Tags have no table. A tag exists only as text inside some component's
     * comma-separated column, so removing one everywhere means rewriting that
     * column on each component that carries it.
     */
    public function destroyTag(string $tag)
    {
        $wanted = mb_strtolower(trim($tag));
        $touched = 0;

        foreach (Component::whereNotNull('tags')->get() as $component) {
            $kept = array_values(array_filter(
                array_map('trim', explode(',', (string) $component->tags)),
                fn ($t) => $t !== '' && mb_strtolower($t) !== $wanted,
            ));

            if (count($kept) === count($component->tagList())) {
                continue;
            }

            $component->update(['tags' => $kept === [] ? null : implode(', ', $kept)]);
            $touched++;
        }

        return response()->json(['removed' => $tag, 'components' => $touched]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        $component = DB::transaction(function () use ($data) {
            $component = Component::create($this->componentAttributes($data));
            $this->syncCheck($component, $data);

            return $component;
        });

        return redirect()->route('admin.components')
            ->with('status', "Component {$component->name} added.");
    }

    public function update(Request $request, Component $component)
    {
        $data = $this->validated($request);

        DB::transaction(function () use ($component, $data) {
            $component->update($this->componentAttributes($data));
            $this->syncCheck($component, $data);
        });

        return redirect()->route('admin.components')
            ->with('status', "Component {$component->name} saved.");
    }

    public function destroy(Component $component)
    {
        $name = $component->name;
        $this->closeAutoIncidents($component);
        $component->delete();

        return redirect()->route('admin.components')
            ->with('status', "Component {$name} deleted, along with its history.");
    }

    protected function validated(Request $request): array
    {
        return $request->validate(
            $this->rules($request),
            [
                'check_target.required' => 'A built-in check needs something to contact.',
                'check_target.url' => 'An HTTP check needs a full URL, starting with http:// or https://.',
                'check_target.regex' => 'A TCP check needs host:port, for example mail.example.net:993.',
            ],
        );
    }

    /**
     * The target's shape depends on the kind of check. Without this the form
     * happily saves "192.168.1.8:5000" as an HTTP check — which can never
     * succeed, and reports it as an outage of a service that is running.
     */
    protected function rules(Request $request): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:255'],
            // Schemes pinned: this is rendered as an href on the public page, and
            // a bare "url" rule lets a javascript: URL through on some inputs.
            'link' => ['nullable', 'url:http,https', 'max:255'],
            'tags' => ['nullable', 'string', 'max:255'],
            'component_group_id' => ['nullable', 'exists:component_groups,id'],
            'status' => ['required', 'integer', 'min:1', 'max:5'],
            'enabled' => ['sometimes', 'boolean'],
            'show_uptime' => ['sometimes', 'boolean'],
            'source' => ['required', Rule::in(['manual', 'check', 'kuma', 'webhook', 'heartbeat', 'upstream'])],
            'check_type' => ['nullable', Rule::in(array_column(CheckType::cases(), 'value'))],
            'check_target' => ['nullable', 'string', 'max:255'],
            'check_interval' => ['nullable', 'integer', 'min:30', 'max:86400'],
        ];

        if ($request->input('source') === 'check') {
            $rules['check_target'] = $request->input('check_type') === 'tcp'
                ? ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9._-]+:\d{1,5}$/']
                : ['required', 'url:http,https', 'max:255'];
        }

        return $rules;
    }

    protected function componentAttributes(array $data): array
    {
        return [
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'link' => $data['link'] ?? null,
            'tags' => $data['tags'] ?? null,
            'component_group_id' => $data['component_group_id'] ?? null,
            'status' => ComponentStatus::from((int) $data['status']),
            'enabled' => (bool) ($data['enabled'] ?? false),
            'show_uptime' => (bool) ($data['show_uptime'] ?? false),
            'source' => $data['source'],
        ];
    }

    /** A component whose source is not a check must not keep a stale one around. */
    protected function syncCheck(Component $component, array $data): void
    {
        $wantsCheck = in_array($data['source'], ['check', 'heartbeat'], true);

        if (! $wantsCheck) {
            $component->check?->delete();

            return;
        }

        $isHeartbeat = $data['source'] === 'heartbeat';

        $target = $isHeartbeat
            ? ($component->check?->target ?: 'hb_'.Str::random(24))
            : ($data['check_target'] ?? '');

        if (! $isHeartbeat && $target === '') {
            $component->check?->delete();

            return;
        }

        Check::updateOrCreate(
            ['component_id' => $component->id],
            [
                'type' => $isHeartbeat ? CheckType::Heartbeat : CheckType::from($data['check_type'] ?? 'http'),
                'target' => $target,
                'interval_seconds' => $data['check_interval'] ?? 60,
                'enabled' => true,
            ],
        );
    }

    /**
     * An incident opened by a check resolves itself when that check recovers.
     * Delete the component and there is nothing left to recover, so the incident
     * would sit on the public page as "Investigating" forever. Close it with a
     * reason rather than leaving it, or deleting history nobody asked to lose.
     */
    protected function closeAutoIncidents(Component $component): void
    {
        $open = Incident::where('grouping_key', 'check:'.$component->id)
            ->where('auto_resolve', true)
            ->whereNull('resolved_at')
            ->get();

        foreach ($open as $incident) {
            $incident->update(['status' => IncidentStatus::Resolved, 'resolved_at' => now()]);

            IncidentUpdate::create([
                'incident_id' => $incident->id,
                'status' => IncidentStatus::Resolved,
                'message' => "Closed because the component was removed. This incident could not resolve itself any more.",
                'automatic' => true,
            ]);
        }
    }
}
