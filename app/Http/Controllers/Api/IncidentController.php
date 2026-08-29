<?php

namespace App\Http\Controllers\Api;

use App\Enums\ComponentStatus;
use App\Enums\IncidentStatus;
use App\Http\Controllers\Controller;
use App\Models\Component;
use App\Models\ApiToken;
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
        // A valid token also sees internal incidents: an integration that opened
        // one for a hidden service has to be able to find it again to resolve it.
        $plain = $request->bearerToken() ?: $request->header('X-Cachet-Token');
        $trusted = $plain && ApiToken::findByPlaintext($plain) !== null;

        $incidents = Incident::query()->when(! $trusted, fn ($q) => $q->public())
            ->with('updates', 'components')
            ->latest('occurred_at')->limit(50)->get();

        return response()->json([
            'meta' => ['pagination' => [
                'total' => $incidents->count(),
                'count' => $incidents->count(),
                'per_page' => $incidents->count(),
                'current_page' => 1,
                'total_pages' => 1,
            ]],
            'data' => $incidents->map(fn ($i) => $this->present($i))->all(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required_without:template', 'string', 'max:255'],
            'template' => ['sometimes', 'string', 'exists:incident_templates,slug'],
            'vars' => ['sometimes', 'array'],
            'vars.*' => ['string', 'max:255'],
            'status' => ['required'],
            'message' => ['required_without:template', 'string'],
            'component_id' => ['sometimes', 'integer'],
            'component_status' => ['sometimes', 'integer', 'min:1', 'max:5'],
            'impact' => ['sometimes', Rule::in(['minor', 'major', 'critical'])],
            'visibility' => ['sometimes', Rule::in(['public', 'authenticated', 'internal'])],
            'components' => ['sometimes', 'array'],
            'auto_resolve' => ['sometimes', 'boolean'],
            'notify' => ['sometimes', 'boolean'],
            'occurred_at' => ['sometimes', 'date'],
        ]);

        $status = $this->parseStatus($data['status']);

        if (! $status) {
            return response()->json([
                'error' => 'Unknown status. Use investigating, identified, watching or resolved, or 1-4.',
            ], 422);
        }

        $name = $data['name'] ?? null;
        $message = $data['message'] ?? null;

        if (isset($data['template'])) {
            $template = IncidentTemplate::where('slug', $data['template'])->firstOrFail();
            $vars = $data['vars'] ?? [];
            $name ??= $template->render('title_template', $vars);
            $message ??= $template->render('body_template', $vars);
        }

        $incident = DB::transaction(function () use ($data, $name, $message, $status) {
            $incident = Incident::create([
                'name' => $name,
                'status' => $status,
                'impact' => $data['impact'] ?? 'minor',
                'visibility' => $data['visibility'] ?? 'public',
                'auto_resolve' => $data['auto_resolve'] ?? false,
                'source' => 'api',
                'occurred_at' => $data['occurred_at'] ?? now(),
                'resolved_at' => $status === IncidentStatus::Resolved ? now() : null,
            ]);

            IncidentUpdate::create([
                'incident_id' => $incident->id,
                'status' => $status,
                'message' => $message,
                'automatic' => false,
            ]);

            $this->applyComponents($incident, $this->componentMap($data));

            return $incident;
        });

        $incident = $incident->fresh(['updates', 'components']);
        $this->webhook->incidentChanged($incident, 'incident.created');

        return response()->json(['data' => $this->present($incident)], 201);
    }

    /** Adds an update to an existing incident, which is how a timeline is built. */
    public function addUpdate(Request $request, Incident $incident)
    {
        $data = $request->validate([
            'status' => ['required'],
            'message' => ['required', 'string'],
            'components' => ['sometimes', 'array'],
        ]);

        $status = $this->parseStatus($data['status']);

        if (! $status) {
            return response()->json(['error' => 'Unknown status.'], 422);
        }

        DB::transaction(function () use ($incident, $status, $data) {
            IncidentUpdate::create([
                'incident_id' => $incident->id,
                'status' => $status,
                'message' => $data['message'],
                'automatic' => false,
            ]);

            $incident->update([
                'status' => $status,
                'resolved_at' => $status === IncidentStatus::Resolved ? now() : $incident->resolved_at,
            ]);

            $this->applyComponents($incident, $data['components'] ?? []);

            // Same rule as the admin: closing an incident puts its components
            // back, unless this request said otherwise explicitly.
            if ($status === IncidentStatus::Resolved) {
                foreach ($incident->components as $component) {
                    if (! array_key_exists($component->id, $data['components'] ?? [])) {
                        $component->update(['status' => ComponentStatus::Operational]);
                    }
                }
            }
        });

        $incident = $incident->fresh(['updates', 'components']);
        $this->webhook->incidentChanged($incident, 'incident.updated');

        return response()->json(['data' => $this->present($incident)]);
    }

    /**
     * Accepts {"4": "partial_outage"} or {"4": 3}. Cachet 2.x allows a single
     * component per incident; a hypervisor failure takes down more than one.
     */
    /**
     * Cachet 2.x sends the status as 1-4, newer clients send the name. Both are
     * the same four states, so accept either rather than break existing scripts.
     */
    protected function parseStatus(mixed $status): ?IncidentStatus
    {
        if (is_numeric($status)) {
            return IncidentStatus::tryFrom((int) $status);
        }

        try {
            return IncidentStatus::fromName((string) $status);
        } catch (\ValueError) {
            return null;
        }
    }

    /**
     * Cachet 2.x attaches exactly one component through component_id +
     * component_status; ours takes a map. Fold the flat pair into the map.
     *
     * @param  array<string, mixed>  $data
     * @return array<int|string, mixed>
     */
    protected function componentMap(array $data): array
    {
        $components = $data['components'] ?? [];

        if (isset($data['component_id'])) {
            $components[$data['component_id']] = $data['component_status'] ?? ComponentStatus::MajorOutage->value;
        }

        return $components;
    }

    protected function applyComponents(Incident $incident, array $components): void
    {
        foreach ($components as $id => $status) {
            $component = Component::find($id) ?? Component::where('name', $id)->first();

            if (! $component) {
                continue;
            }

            $value = is_numeric($status)
                ? ComponentStatus::from((int) $status)
                : $this->statusFromSlug((string) $status);

            $incident->components()->syncWithoutDetaching([
                $component->id => ['status' => $value->value],
            ]);

            $component->update(['status' => $value]);
        }
    }

    protected function statusFromSlug(string $slug): ComponentStatus
    {
        return match (strtolower($slug)) {
            'operational' => ComponentStatus::Operational,
            'degraded', 'performance', 'performance_issues' => ComponentStatus::PerformanceIssues,
            'partial', 'partial_outage' => ComponentStatus::PartialOutage,
            'major', 'major_outage' => ComponentStatus::MajorOutage,
            'maintenance', 'under_maintenance' => ComponentStatus::UnderMaintenance,
            default => ComponentStatus::Operational,
        };
    }

    protected function present(Incident $i): array
    {
        return [
            'id' => $i->id,
            'name' => $i->name,
            'status' => $i->status->value,
            'status_name' => $i->status->label(),
            'impact' => $i->impact->value,
            'visibility' => $i->visibility,
            'source' => $i->source,
            'occurred_at' => $i->occurred_at?->toIso8601String(),
            'resolved_at' => $i->resolved_at?->toIso8601String(),
            'components' => $i->components->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'status' => (int) $c->pivot->status,
            ])->all(),
            'updates' => $i->updates->map(fn ($u) => [
                'status' => $u->status->value,
                'status_name' => $u->status->label(),
                'message' => $u->message,
                'automatic' => $u->automatic,
                'created_at' => $u->created_at?->toIso8601String(),
            ])->all(),
        ];
    }
}
