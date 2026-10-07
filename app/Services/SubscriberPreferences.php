<?php

namespace App\Services;

use App\Models\Subscriber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SubscriberPreferences
{
    public static function validate(Request $request): array
    {
        return $request->validate([
            'all_services' => ['sometimes', 'boolean'],
            'component_ids' => ['required_if:all_services,0,false', 'array', 'max:500', Rule::when($request->has('all_services') && ! $request->boolean('all_services'), ['min:1'])],
            'component_ids.*' => ['integer', 'distinct', Rule::exists('components', 'id')->where(fn ($q) => $q->whereIn('id', PublicComponents::query()->select('id')))],
        ]);
    }

    public static function save(Subscriber $subscriber, array $data): void
    {
        DB::transaction(function () use ($subscriber, $data) {
            $all = $data['all_services'] ?? true;
            $subscriber->update(['all_services' => $all]);
            $subscriber->components()->sync($all ? [] : ($data['component_ids'] ?? []));
        });
    }
}
