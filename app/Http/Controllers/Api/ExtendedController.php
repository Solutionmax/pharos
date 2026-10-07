<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Component;
use App\Models\ComponentGroup;
use App\Models\Incident;
use App\Models\IncidentUpdate;
use App\Models\Maintenance;
use App\Models\Subscriber;
use App\Services\MaintenanceScheduler;
use App\Services\PageContext;
use App\Services\SubscriberPreferences;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ExtendedController extends Controller
{
    public function ping()
    {
        return response()->json(['data' => 'Pong!']);
    }

    public function groups()
    {
        return response()->json(['data' => ComponentGroup::orderBy('position')->limit(500)->get()->map($this->groupData(...))]);
    }

    public function group(ComponentGroup $group)
    {
        return response()->json(['data' => $this->groupData($group)]);
    }

    public function storeGroup(Request $request)
    {
        $group = ComponentGroup::create($this->groupValidation($request, true));

        return response()->json(['data' => $this->groupData($group)], 201);
    }

    public function updateGroup(Request $request, ComponentGroup $group)
    {
        $group->update($this->groupValidation($request, false));

        return response()->json(['data' => $this->groupData($group->fresh())]);
    }

    public function deleteGroup(ComponentGroup $group)
    {
        $group->delete();

        return response()->noContent();
    }

    private function groupValidation(Request $request, bool $creating): array
    {
        return $request->validate(['name' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'], 'visible' => ['sometimes', 'boolean'], 'collapsed' => ['sometimes', 'boolean'], 'position' => ['sometimes', 'integer', 'min:0', 'max:100000']]);
    }

    private function groupData(ComponentGroup $group): array
    {
        return ['id' => $group->id, 'name' => $group->name, 'visible' => $group->getAttribute('visible'), 'collapsed' => $group->collapsed, 'position' => $group->position];
    }

    public function storeComponent(Request $request)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:255'], 'status' => ['sometimes', 'integer', 'between:1,5'], 'group_id' => ['nullable', 'integer', Rule::exists('component_groups', 'id')->where('status_page_id', app(PageContext::class)->id())], 'enabled' => ['sometimes', 'boolean'], 'show_uptime' => ['sometimes', 'boolean'], 'position' => ['sometimes', 'integer', 'between:0,100000']]);
        $component = Component::create(collect($data)->except('group_id')->all() + ['component_group_id' => $data['group_id'] ?? null, 'source' => 'webhook']);

        return response()->json(['data' => ['id' => $component->id, 'name' => $component->name, 'status' => $component->status->value, 'group_id' => $component->component_group_id]], 201);
    }

    public function deleteComponent(Component $component)
    {
        DB::transaction(function () use ($component) {
            foreach (Incident::where('grouping_key', 'check:'.$component->id)->where('auto_resolve', true)->whereNull('resolved_at')->get() as $incident) {
                $incident->update(['status' => 4, 'resolved_at' => now()]);
                IncidentUpdate::create(['incident_id' => $incident->id, 'status' => 4, 'message' => __('Closed because the component was removed. This incident could not resolve itself any more.'), 'automatic' => true]);
            }
            $component->delete();
        });

        return response()->noContent();
    }

    public function deleteIncident(Incident $incident)
    {
        $incident->delete();

        return response()->noContent();
    }

    private function subscriberAuthority(Request $request): void
    {
        $owner = $request->attributes->get('api_token')?->user;
        abort_unless($owner?->canAdministerPage(app(PageContext::class)->id()), 403);
    }

    public function subscribers(Request $request)
    {
        $this->subscriberAuthority($request);
        $data = $request->validate(['per_page' => ['sometimes', 'integer', 'between:1,100'], 'page' => ['sometimes', 'integer', 'between:1,100000']]);
        $rows = Subscriber::orderBy('id')->paginate($data['per_page'] ?? 50);

        return response()->json(['data' => $rows->map($this->subscriberData(...)), 'meta' => ['pagination' => ['total' => $rows->total(), 'per_page' => $rows->perPage(), 'current_page' => $rows->currentPage()]]]);
    }

    public function subscriber(Request $request, Subscriber $subscriber)
    {
        $this->subscriberAuthority($request);

        return response()->json(['data' => $this->subscriberData($subscriber)]);
    }

    public function storeSubscriber(Request $request)
    {
        $this->subscriberAuthority($request);
        if (is_string($request->input('email'))) {
            $request->merge(['email' => mb_strtolower(trim($request->input('email')))]);
        }
        $data = $request->validate(['email' => ['required', 'string', 'email:rfc', 'max:254', Rule::unique('subscribers')->where('status_page_id', app(PageContext::class)->id())]]);
        $preferences = SubscriberPreferences::validate($request);
        $subscriber = DB::transaction(function () use ($data, $preferences) {
            $subscriber = Subscriber::create(['email' => mb_strtolower(trim($data['email'])), 'token' => Subscriber::freshToken()]);
            SubscriberPreferences::save($subscriber, $preferences);

            return $subscriber;
        });

        return response()->json(['data' => $this->subscriberData($subscriber->fresh())], 201);
    }

    public function updateSubscriber(Request $request, Subscriber $subscriber)
    {
        $this->subscriberAuthority($request);
        SubscriberPreferences::save($subscriber, SubscriberPreferences::validate($request));

        return response()->json(['data' => $this->subscriberData($subscriber->fresh())]);
    }

    public function deleteSubscriber(Request $request, Subscriber $subscriber)
    {
        $this->subscriberAuthority($request);
        $subscriber->delete();

        return response()->noContent();
    }

    private function subscriberData(Subscriber $subscriber): array
    {
        return ['id' => $subscriber->id, 'email' => $subscriber->email, 'verified' => $subscriber->verified_at !== null, 'active' => $subscriber->isActive(), 'all_services' => $subscriber->all_services, 'component_ids' => $subscriber->components()->pluck('components.id')->all()];
    }

    public function maintenanceIndex()
    {
        return response()->json(['data' => Maintenance::with('components')->latest('starts_at')->limit(100)->get()->map($this->maintenanceData(...))]);
    }

    public function maintenance(Maintenance $maintenance)
    {
        return response()->json(['data' => $this->maintenanceData($maintenance)]);
    }

    public function storeMaintenance(Request $request)
    {
        $data = $this->maintenanceValidation($request, true);
        $maintenance = DB::transaction(function () use ($data) {
            $maintenance = Maintenance::create(collect($data)->except('components')->all());
            $maintenance->components()->sync($data['components'] ?? []);

            return $maintenance;
        });

        return response()->json(['data' => $this->maintenanceData($maintenance)], 201);
    }

    public function updateMaintenance(Request $request, Maintenance $maintenance)
    {
        abort_unless($maintenance->isOpen() && $maintenance->started_at === null, 409);
        $data = $this->maintenanceValidation($request, false);
        $start = isset($data['starts_at']) ? CarbonImmutable::parse($data['starts_at']) : $maintenance->starts_at;
        $end = isset($data['ends_at']) ? CarbonImmutable::parse($data['ends_at']) : $maintenance->ends_at;
        if ($end->lte($start) || $end->lte(now())) {
            throw ValidationException::withMessages(['ends_at' => __('The end must be after the start and in the future.')]);
        }
        DB::transaction(function () use ($maintenance, $data) {
            $maintenance->update(collect($data)->except('components')->all() + ['announced_at' => null]);
            if (array_key_exists('components', $data)) {
                $maintenance->components()->sync($data['components']);
            }
        });

        return response()->json(['data' => $this->maintenanceData($maintenance->fresh())]);
    }

    public function deleteMaintenance(Maintenance $maintenance, MaintenanceScheduler $scheduler)
    {
        DB::transaction(function () use ($maintenance, $scheduler) {
            if ($maintenance->isOpen()) {
                $scheduler->cancel($maintenance);
            } elseif ($maintenance->started_at !== null && $maintenance->completed_at === null && $maintenance->cancelled_at === null) {
                $scheduler->complete($maintenance);
            }
            $maintenance->delete();
        });

        return response()->noContent();
    }

    private function maintenanceValidation(Request $request, bool $creating): array
    {
        return $request->validate(['title' => [$creating ? 'required' : 'sometimes', 'string', 'max:160'], 'message' => ['nullable', 'string', 'max:5000'], 'starts_at' => [$creating ? 'required' : 'sometimes', 'date_format:Y-m-d\TH:i:sP'], 'ends_at' => [$creating ? 'required' : 'sometimes', 'date_format:Y-m-d\TH:i:sP', 'after:now', ...($creating ? ['after:starts_at'] : [])], 'announce_minutes' => ['sometimes', 'integer', Rule::in(array_keys(Maintenance::LEAD_TIMES))], 'components' => ['sometimes', 'array', 'max:500'], 'components.*' => ['integer', 'distinct', Rule::exists('components', 'id')->where('status_page_id', app(PageContext::class)->id())]]);
    }

    private function maintenanceData(Maintenance $maintenance): array
    {
        return ['id' => $maintenance->id, 'title' => $maintenance->title, 'message' => $maintenance->message, 'state' => $maintenance->state(), 'starts_at' => $maintenance->starts_at->toIso8601String(), 'ends_at' => $maintenance->ends_at->toIso8601String(), 'announce_minutes' => $maintenance->announce_minutes, 'component_ids' => $maintenance->components()->pluck('components.id')->all()];
    }
}
