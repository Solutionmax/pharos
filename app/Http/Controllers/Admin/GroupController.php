<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ComponentGroup;
use Illuminate\Http\Request;

/**
 * Services are the top level of the status page. Cachet calls them component
 * groups; customers read them as "the thing I bought".
 */
class GroupController extends Controller
{
    /**
     * A screen is only top level when the sidebar sent you. Arriving from
     * Settings makes it a detour, and a detour needs a way back.
     */
    protected function origin(Request $request): ?array
    {
        return $request->query('from') === 'settings'
            ? ['url' => route('admin.settings'), 'label' => 'Settings']
            : null;
    }

    /** Keeps ?from= alive across redirects so the trail does not break on save. */
    protected function back(Request $request): string
    {
        return route('admin.groups', $request->query('from') ? ['from' => $request->query('from')] : []);
    }

    public function index(Request $request)
    {
        return view('admin.groups', [
            'groups' => ComponentGroup::withCount('components')->orderBy('position')->get(),
            'origin' => $this->origin($request),
            'from' => $request->query('from'),
        ]);
    }

    public function create(Request $request)
    {
        return view('admin.group-form', [
            'group' => new ComponentGroup,
            'from' => $request->query('from'),
            'listUrl' => $this->back($request),
        ]);
    }

    public function edit(Request $request, ComponentGroup $group)
    {
        return view('admin.group-form', [
            'group' => $group->loadCount('components'),
            'from' => $request->query('from'),
            'listUrl' => $this->back($request),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        ComponentGroup::create($data + [
            'position' => (ComponentGroup::max('position') ?? 0) + 1,
        ]);

        return redirect()->to($this->back($request))->with('status', "Service \"{$data['name']}\" added.");
    }

    public function update(Request $request, ComponentGroup $group)
    {
        $group->update($this->validated($request));

        return redirect()->to($this->back($request))->with('status', "Service \"{$group->name}\" saved.");
    }

    public function destroy(Request $request, ComponentGroup $group)
    {
        // Components outlive their group: deleting a service must not silently
        // take a customer's uptime history with it.
        $orphans = $group->components()->count();
        $group->components()->update(['component_group_id' => null]);

        $name = $group->name;
        $group->delete();

        return redirect()->to($this->back($request))->with(
            'status',
            $orphans > 0
                ? "Service \"{$name}\" deleted. {$orphans} ".str('component')->plural($orphans)." kept, now ungrouped."
                : "Service \"{$name}\" deleted.",
        );
    }

    public function move(Request $request, ComponentGroup $group)
    {
        $direction = $request->input('direction') === 'up' ? -1 : 1;
        $neighbour = ComponentGroup::orderBy('position')
            ->where('position', $direction < 0 ? '<' : '>', $group->position)
            ->orderBy('position', $direction < 0 ? 'desc' : 'asc')
            ->first();

        if ($neighbour) {
            [$group->position, $neighbour->position] = [$neighbour->position, $group->position];
            $group->save();
            $neighbour->save();
        }

        return redirect()->to($this->back($request));
    }

    protected function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'collapsed' => ['sometimes', 'boolean'],
            'visible' => ['sometimes', 'boolean'],
        ]);

        return [
            'name' => $data['name'],
            'collapsed' => (bool) ($data['collapsed'] ?? false),
            'visible' => (bool) ($data['visible'] ?? false),
        ];
    }
}
