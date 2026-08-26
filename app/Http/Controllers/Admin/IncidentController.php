<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ComponentStatus;
use App\Enums\IncidentStatus;
use App\Http\Controllers\Controller;
use App\Models\Component;
use App\Models\Incident;
use App\Models\IncidentTemplate;
use App\Models\IncidentUpdate;
use App\Services\OutgoingWebhook;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class IncidentController extends Controller
{
    public function __construct(protected OutgoingWebhook $webhook) {}

    public function index(Request $request)
    {
        $query = Incident::with('components', 'updates')->latest('occurred_at');

        if ($search = trim((string) $request->query('q'))) {
            $query->where('name', 'like', "%{$search}%");
        }

        if ($state = $request->query('state')) {
            match ($state) {
                'open' => $query->whereNull('resolved_at'),
                'resolved' => $query->whereNotNull('resolved_at'),
                default => null,
            };
        }

        $incidents = $query->paginate(25)->withQueryString();

        // Repeat outages on the same target are counted so a weekly failure reads
        // as a pattern instead of four unrelated rows.
        $repeats = Incident::selectRaw('grouping_key, count(*) as total')
            ->whereNotNull('grouping_key')
            ->where('occurred_at', '>=', now()->subDays(30))
            ->groupBy('grouping_key')
            ->having('total', '>', 1)
            ->pluck('total', 'grouping_key');

        $all = Incident::query();
        $resolved = (clone $all)->whereNotNull('resolved_at')
            ->where('occurred_at', '>=', now()->subDays(30))->get();

        $summary = [
            'open' => (clone $all)->whereNull('resolved_at')->count(),
            'month' => (clone $all)->where('occurred_at', '>=', now()->subDays(30))->count(),
            'automatic' => (clone $all)->where('source', 'check')
                ->where('occurred_at', '>=', now()->subDays(30))->count(),
            // Mean time to resolve, in minutes, over the last 30 days.
            'mttr' => $resolved->isEmpty() ? null : (int) round(
                $resolved->avg(fn ($i) => $i->occurred_at->diffInMinutes($i->resolved_at)),
            ),
        ];

        return view('admin.incidents', compact('incidents', 'repeats', 'search', 'state', 'summary'));
    }

    public function create()
    {
        return view('admin.incident-form', [
            'templates' => IncidentTemplate::orderBy('name')->get(),
            'components' => Component::with('group')->orderBy('position')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string'],
            'status' => ['required', 'integer', 'min:1', 'max:4'],
            'impact' => ['required', Rule::in(['minor', 'major', 'critical'])],
            'visibility' => ['required', Rule::in(['public', 'authenticated', 'internal'])],
            'pinned' => ['sometimes', 'boolean'],
            'auto_resolve' => ['sometimes', 'boolean'],
            'occurred_at' => ['nullable', 'date'],
            'components' => ['sometimes', 'array'],
            'components.*' => ['integer', 'min:1', 'max:5'],
        ]);

        $status = IncidentStatus::from((int) $data['status']);

        $incident = DB::transaction(function () use ($data, $status) {
            $incident = Incident::create([
                'name' => $data['name'],
                'status' => $status,
                'impact' => $data['impact'],
                'visibility' => $data['visibility'],
                'pinned' => (bool) ($data['pinned'] ?? false),
                'auto_resolve' => (bool) ($data['auto_resolve'] ?? false),
                'source' => 'manual',
                'occurred_at' => $data['occurred_at'] ?? now(),
                'resolved_at' => $status === IncidentStatus::Resolved ? now() : null,
            ]);

            IncidentUpdate::create([
                'incident_id' => $incident->id,
                'status' => $status,
                'message' => $data['message'],
            ]);

            foreach ($data['components'] ?? [] as $componentId => $componentStatus) {
                $incident->components()->attach($componentId, ['status' => (int) $componentStatus]);
                Component::whereKey($componentId)
                    ->update(['status' => ComponentStatus::from((int) $componentStatus)->value]);
            }

            return $incident;
        });

        $this->webhook->incidentChanged($incident->fresh('components'), 'incident.created');

        return redirect()->route('admin.incidents')
            ->with('status', "Incident \"{$incident->name}\" published.");
    }

    public function addUpdate(Request $request, Incident $incident)
    {
        $data = $request->validate([
            'status' => ['required', 'integer', 'min:1', 'max:4'],
            'message' => ['required', 'string'],
        ]);

        $status = IncidentStatus::from((int) $data['status']);

        DB::transaction(function () use ($incident, $status, $data) {
            IncidentUpdate::create([
                'incident_id' => $incident->id,
                'status' => $status,
                'message' => $data['message'],
            ]);

            $incident->update([
                'status' => $status,
                'resolved_at' => $status === IncidentStatus::Resolved ? now() : $incident->resolved_at,
            ]);

            // Closing an incident puts its components back; leaving them red is
            // the single most common way a status page starts lying.
            if ($status === IncidentStatus::Resolved) {
                foreach ($incident->components as $component) {
                    $component->update(['status' => ComponentStatus::Operational]);
                }
            }
        });

        $this->webhook->incidentChanged($incident->fresh('components'), 'incident.updated');

        return redirect()->route('admin.incidents')->with('status', 'Update posted.');
    }
}
