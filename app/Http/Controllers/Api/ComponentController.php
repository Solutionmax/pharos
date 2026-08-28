<?php

namespace App\Http\Controllers\Api;

use App\Enums\ComponentStatus;
use App\Http\Controllers\Controller;
use App\Models\Component;
use Illuminate\Http\Request;

class ComponentController extends Controller
{
    /** Cachet 2.x response envelope, so existing clients can read us unchanged. */
    public function index()
    {
        // The feed shows exactly what the public page shows: no disabled
        // components, nothing from a service the operator switched off.
        $components = Component::with('group')->where('enabled', true)
            ->where(fn ($q) => $q->whereNull('component_group_id')
                ->orWhereHas('group', fn ($g) => $g->where('visible', true)))
            ->orderBy('position')->get();

        return response()->json([
            'meta' => ['pagination' => [
                'total' => $components->count(),
                'count' => $components->count(),
                'per_page' => $components->count(),
                'current_page' => 1,
                'total_pages' => 1,
            ]],
            'data' => $components->map(fn (Component $c) => $this->present($c))->all(),
        ]);
    }

    public function show(Component $component)
    {
        abort_unless($component->enabled && ($component->group === null || $component->group->visible), 404);

        return response()->json(['data' => $this->present($component)]);
    }

    public function update(Request $request, Component $component)
    {
        $data = $request->validate([
            'status' => ['required', 'integer', 'min:1', 'max:5'],
            'description' => ['sometimes', 'string', 'max:255'],
        ]);

        $component->update([
            'status' => ComponentStatus::from($data['status']),
            'description' => $data['description'] ?? $component->description,
        ]);

        return response()->json(['data' => $this->present($component->fresh())]);
    }

    protected function present(Component $c): array
    {
        return [
            'id' => $c->id,
            'name' => $c->name,
            'description' => $c->description,
            'link' => $c->link,
            'status' => $c->status->value,
            'status_name' => $c->status->label(),
            'enabled' => $c->enabled,
            'group_id' => $c->component_group_id,
            'tags' => $c->tagList(),
            'source' => $c->source,
            'created_at' => $c->created_at?->toIso8601String(),
            'updated_at' => $c->updated_at?->toIso8601String(),
        ];
    }
}
