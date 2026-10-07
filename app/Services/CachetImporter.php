<?php

namespace App\Services;

use App\Models\Component;
use App\Models\ComponentGroup;
use App\Models\Incident;
use App\Models\IncidentUpdate;
use App\Models\Subscriber;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Local Cachet 2.x JSON import. Only explicitly mapped fields ever enter Pharos. */
class CachetImporter
{
    public const MAX_BYTES = 4194304;

    public const MAX_ROWS = 1000;

    public function decode(string $json): array
    {
        if (strlen($json) > self::MAX_BYTES) {
            $this->invalid('export', __('The export is larger than 4 MB.'));
        }
        try {
            $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->invalid('export', __('Upload a valid JSON export.'));
        }
        if (! is_array($data) || array_is_list($data)) {
            $this->invalid('export', __('The export must contain resource lists.'));
        }

        return $data;
    }

    public function preview(array $export): array
    {
        $data = $this->normalize($export);
        $counts = array_map(count(...), $data);

        return ['counts' => $counts, 'groups' => array_column($data['groups'], 'name'), 'components' => array_column($data['components'], 'name'), 'incidents' => array_column($data['incidents'], 'name'), 'existing_subscribers' => Subscriber::whereIn('email', array_column($data['subscribers'], 'email'))->count(), 'digest' => $this->digest($data)];
    }

    public function apply(array $export): array
    {
        $data = $this->normalize($export);
        $counts = array_map(count(...), $data);
        $digest = $this->digest($data);

        return DB::transaction(function () use ($data, $counts, $digest) {
            $pageId = app(PageContext::class)->id();
            // A database unique key also serializes identical concurrent imports.
            if (DB::table('cachet_import_batches')->where('status_page_id', $pageId)->where('digest', $digest)->exists()) {
                $this->invalid('export', __('This export has already been imported into this page.'));
            }
            DB::table('cachet_import_batches')->insert(['status_page_id' => $pageId, 'digest' => $digest, 'counts' => json_encode($counts), 'created_at' => now()]);
            $groups = [];
            $components = [];
            foreach ($data['groups'] as $row) {
                $group = ComponentGroup::create(['name' => $row['name'], 'visible' => (bool) ($row['visible'] ?? false), 'collapsed' => (bool) ($row['collapsed'] ?? false), 'position' => $row['order'] ?? 0]);
                $groups[$row['id']] = $group->id;
            }
            foreach ($data['components'] as $row) {
                $component = Component::create(['name' => $row['name'], 'description' => $row['description'] ?? null, 'link' => $row['link'] ?? null, 'status' => $row['status'], 'component_group_id' => $groups[$row['group_id'] ?? 0] ?? null, 'enabled' => (bool) ($row['enabled'] ?? true), 'position' => $row['order'] ?? 0, 'source' => 'manual']);
                $components[$row['id']] = $component->id;
            }
            foreach ($data['incidents'] as $row) {
                $occurred = CarbonImmutable::parse($row['occurred_at'] ?? $row['created_at'] ?? now(), 'UTC');
                $incident = Incident::create(['name' => $row['name'], 'status' => $row['status'], 'visibility' => ($row['visible'] ?? false) ? 'public' : 'internal', 'pinned' => (bool) ($row['stickied'] ?? false), 'source' => 'cachet', 'occurred_at' => $occurred, 'resolved_at' => $row['status'] === 4 ? CarbonImmutable::parse($row['resolved_at'] ?? $row['updated_at'] ?? $occurred, 'UTC') : null]);
                if (! empty($row['component_id'])) {
                    $incident->components()->attach($components[$row['component_id']], ['status' => $row['component_status'] ?? 4]);
                }
                // Imported history is historical evidence; model-created mail hooks must stay silent.
                IncidentUpdate::withoutEvents(function () use ($incident, $row, $occurred) {
                    IncidentUpdate::create(['incident_id' => $incident->id, 'status' => $row['status'], 'message' => $row['message'], 'created_at' => $occurred, 'updated_at' => $occurred, 'automatic' => false]);
                    foreach ($row['updates'] ?? [] as $update) {
                        $when = CarbonImmutable::parse($update['created_at'] ?? $occurred, 'UTC');
                        IncidentUpdate::create(['incident_id' => $incident->id, 'status' => $update['status'], 'message' => $update['message'], 'created_at' => $when, 'updated_at' => $when, 'automatic' => false]);
                    }
                });
            }
            foreach ($data['subscribers'] as $row) {
                // Existing consent and opt-outs win; importing never reactivates an address.
                if (Subscriber::where('email', $row['email'])->exists()) {
                    continue;
                }
                $subscriber = Subscriber::create(['email' => $row['email'], 'token' => Subscriber::freshToken(), 'verified_at' => empty($row['verified_at']) ? null : CarbonImmutable::parse($row['verified_at'], 'UTC'), 'unsubscribed_at' => empty($row['unsubscribed_at']) ? null : CarbonImmutable::parse($row['unsubscribed_at'], 'UTC'), 'all_services' => (bool) $row['global']]);
                $subscriber->components()->sync(array_map(fn ($subscription) => $components[$subscription['component_id']], $row['subscriptions']));
            }
            Audit::record('cachet.imported', null, $counts);

            return $counts;
        });
    }

    private function digest(array $data): string
    {
        $canonical = function (array $value) use (&$canonical): array {
            if (! array_is_list($value)) {
                ksort($value);
            }
            foreach ($value as &$item) {
                if (is_array($item)) {
                    $item = $canonical($item);
                }
            }

            return $value;
        };

        return hash('sha256', json_encode($canonical($data), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function normalize(array $export): array
    {
        if (strlen(json_encode($export, JSON_THROW_ON_ERROR)) > self::MAX_BYTES) {
            $this->invalid('export', __('The export is larger than 4 MB.'));
        }
        $data = [];
        foreach (['groups', 'components', 'incidents', 'subscribers'] as $resource) {
            $list = $export[$resource] ?? ($resource === 'groups' ? ($export['component_groups'] ?? []) : []);
            if (is_array($list) && array_key_exists('data', $list)) {
                $list = $list['data'];
            }
            if (! is_array($list) || ! array_is_list($list) || count($list) > self::MAX_ROWS) {
                $this->invalid($resource, __('Each resource must be a list of at most 1000 rows.'));
            }
            $data[$resource] = $list;
        }
        if (array_sum(array_map(count(...), $data)) === 0) {
            $this->invalid('export', __('The export contains no supported resources.'));
        }
        $rules = [
            'groups.*' => ['array'], 'groups.*.id' => ['required', 'integer', 'min:1', 'distinct'], 'groups.*.name' => ['required', 'string', 'max:255'], 'groups.*.visible' => ['sometimes', 'boolean'], 'groups.*.collapsed' => ['sometimes', 'integer', 'between:0,4'], 'groups.*.order' => ['sometimes', 'integer', 'between:0,100000'],
            'components.*' => ['array'], 'components.*.id' => ['required', 'integer', 'min:1', 'distinct'], 'components.*.name' => ['required', 'string', 'max:255'], 'components.*.description' => ['nullable', 'string', 'max:255'], 'components.*.status' => ['required', 'integer', 'between:1,5'], 'components.*.group_id' => ['nullable', 'integer', 'min:0'], 'components.*.link' => ['nullable', 'string', 'max:255', 'url:http,https'], 'components.*.enabled' => ['sometimes', 'boolean'], 'components.*.order' => ['sometimes', 'integer', 'between:0,100000'],
            'incidents.*' => ['array'], 'incidents.*.id' => ['required', 'integer', 'min:1', 'distinct'], 'incidents.*.name' => ['required', 'string', 'max:255'], 'incidents.*.status' => ['required', 'integer', 'between:1,4'], 'incidents.*.message' => ['required', 'string', 'max:20000'], 'incidents.*.visible' => ['sometimes', 'boolean'], 'incidents.*.stickied' => ['sometimes', 'boolean'], 'incidents.*.component_id' => ['nullable', 'integer', 'min:0'], 'incidents.*.component_status' => ['sometimes', 'integer', 'between:1,5'], 'incidents.*.updates' => ['sometimes', 'array', 'max:100'], 'incidents.*.updates.*.status' => ['required', 'integer', 'between:1,4'], 'incidents.*.updates.*.message' => ['required', 'string', 'max:20000'], 'incidents.*.updates.*.created_at' => ['nullable', 'string', 'max:40', 'date'],
            'subscribers.*' => ['array'], 'subscribers.*.id' => ['required', 'integer', 'min:1', 'distinct'], 'subscribers.*.email' => ['required', 'string', 'email:rfc', 'max:254'], 'subscribers.*.global' => ['sometimes', 'boolean'], 'subscribers.*.verified_at' => ['nullable', 'string', 'max:40', 'date'], 'subscribers.*.unsubscribed_at' => ['nullable', 'string', 'max:40', 'date'], 'subscribers.*.subscriptions' => ['sometimes', 'array', 'max:1000'], 'subscribers.*.subscriptions.*.component_id' => ['nullable', 'integer', 'min:1'],
        ];
        foreach (['occurred_at', 'created_at', 'updated_at', 'resolved_at'] as $field) {
            $rules['incidents.*.'.$field] = ['nullable', 'string', 'max:40', 'date'];
        }
        Validator::make($data, $rules)->validate();
        $groupIds = array_column($data['groups'], 'id');
        $componentIds = array_column($data['components'], 'id');
        foreach ($data['components'] as $i => $row) {
            if (! empty($row['group_id']) && ! in_array($row['group_id'], $groupIds)) {
                $this->invalid('components.'.$i.'.group_id', __('The component references a group missing from this export.'));
            }
        }
        foreach ($data['incidents'] as $i => $row) {
            if (! empty($row['component_id']) && ! in_array($row['component_id'], $componentIds)) {
                $this->invalid('incidents.'.$i.'.component_id', __('The incident references a component missing from this export.'));
            }
        }
        $emails = [];
        foreach ($data['subscribers'] as $i => &$row) {
            $row['email'] = mb_strtolower(trim($row['email']));
            if (in_array($row['email'], $emails, true)) {
                $this->invalid('subscribers.'.$i.'.email', __('The export contains a duplicate email address.'));
            }
            $emails[] = $row['email'];
            $row['global'] ??= empty($row['subscriptions']);
            $row['subscriptions'] = array_values(array_filter($row['subscriptions'] ?? [], fn ($subscription) => ! empty($subscription['component_id'])));
            if ($row['global']) {
                $row['subscriptions'] = [];
            }
            foreach ($row['subscriptions'] as $j => $subscription) {
                if (! in_array($subscription['component_id'], $componentIds)) {
                    $this->invalid('subscribers.'.$i.'.subscriptions.'.$j.'.component_id', __('The subscription references a component missing from this export.'));
                }
            }

        }
        unset($row);
        // Canonicalize what application actually consumes, including defaults and UTC dates.
        // Source-only IDs remain where needed to map relations; incident/subscriber IDs
        // and overridden timestamp fields cannot change the import replay identity.
        $date = fn ($value) => ($value === null || $value === '') ? null : CarbonImmutable::parse($value, 'UTC')->utc()->format('Y-m-d H:i:s');
        $data['groups'] = array_map(fn ($row) => [
            'id' => (int) $row['id'], 'name' => $row['name'], 'visible' => (bool) ($row['visible'] ?? false),
            'collapsed' => (bool) ($row['collapsed'] ?? false), 'order' => (int) ($row['order'] ?? 0),
        ], $data['groups']);
        $data['components'] = array_map(fn ($row) => [
            'id' => (int) $row['id'], 'name' => $row['name'], 'status' => (int) $row['status'],
            'description' => $row['description'] ?? null, 'link' => $row['link'] ?? null,
            'group_id' => empty($row['group_id']) ? null : (int) $row['group_id'],
            'enabled' => (bool) ($row['enabled'] ?? true), 'order' => (int) ($row['order'] ?? 0),
        ], $data['components']);
        $data['incidents'] = array_map(function ($row) use ($date) {
            $occurred = $date($row['occurred_at'] ?? null) ?? $date($row['created_at'] ?? null);
            $componentId = empty($row['component_id']) ? null : (int) $row['component_id'];
            $updates = array_map(fn ($update) => ['status' => (int) $update['status'], 'message' => $update['message'],
                'created_at' => $date($update['created_at'] ?? null) ?? $occurred], $row['updates'] ?? []);
            usort($updates, fn ($a, $b) => [$a['created_at'], $a['status'], $a['message']] <=> [$b['created_at'], $b['status'], $b['message']]);

            return ['name' => $row['name'], 'status' => (int) $row['status'], 'message' => $row['message'],
                'visible' => (bool) ($row['visible'] ?? false), 'stickied' => (bool) ($row['stickied'] ?? false),
                'component_id' => $componentId, 'component_status' => $componentId === null ? null : (int) ($row['component_status'] ?? 4),
                'occurred_at' => $occurred, 'resolved_at' => (int) $row['status'] === 4 ? ($date($row['resolved_at'] ?? null) ?? $date($row['updated_at'] ?? null) ?? $occurred) : null,
                'updates' => $updates];
        }, $data['incidents']);
        $data['subscribers'] = array_map(function ($row) use ($date) {
            $ids = array_values(array_unique(array_map(fn ($subscription) => (int) $subscription['component_id'], $row['subscriptions'])));
            sort($ids);

            return ['email' => $row['email'], 'global' => (bool) $row['global'],
                'verified_at' => $date($row['verified_at'] ?? null), 'unsubscribed_at' => $date($row['unsubscribed_at'] ?? null),
                'subscriptions' => array_map(fn ($id) => ['component_id' => $id], $ids)];
        }, $data['subscribers']);
        foreach (['groups', 'components'] as $resource) {
            usort($data[$resource], fn ($a, $b) => $a['id'] <=> $b['id']);
        }
        foreach (['incidents', 'subscribers'] as $resource) {
            usort($data[$resource], fn ($a, $b) => strcmp(json_encode($a, JSON_UNESCAPED_UNICODE), json_encode($b, JSON_UNESCAPED_UNICODE)));
        }

        return $data;
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
