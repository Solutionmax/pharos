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
        $components = Component::with('group')->orderBy('position')->get();

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
