<?php

namespace App\Http\Controllers\Admin;

use App\Enums\CheckType;
use App\Enums\ComponentStatus;
use App\Http\Controllers\Controller;
use App\Models\Check;
use App\Models\Component;
use App\Models\ComponentGroup;
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
        ]);
    }

    public function edit(Component $component)
    {
        return view('admin.component-form', [
            'component' => $component->load('check'),
            'groups' => ComponentGroup::orderBy('position')->get(),
        ]);
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
        $component->delete();

        return redirect()->route('admin.components')
            ->with('status', "Component {$name} deleted, along with its history.");
    }

    protected function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:255'],
            'link' => ['nullable', 'url', 'max:255'],
            'tags' => ['nullable', 'string', 'max:255'],
            'component_group_id' => ['nullable', 'exists:component_groups,id'],
            'status' => ['required', 'integer', 'min:1', 'max:5'],
            'enabled' => ['sometimes', 'boolean'],
            'show_uptime' => ['sometimes', 'boolean'],
            'source' => ['required', Rule::in(['manual', 'check', 'kuma', 'webhook', 'heartbeat', 'upstream'])],
            'check_type' => ['nullable', Rule::in(array_column(CheckType::cases(), 'value'))],
            'check_target' => ['nullable', 'string', 'max:255'],
            'check_interval' => ['nullable', 'integer', 'min:30', 'max:86400'],
        ]);
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
}
