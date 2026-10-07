<?php

namespace App\Models;

use App\Casts\LocalTime;
use App\Enums\ComponentStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToStatusPage;
use App\Models\Concerns\LocalTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * @property-read Pivot|null $pivot
 */
class Component extends Model
{
    use Auditable, BelongsToStatusPage, LocalTimestamps;

    /** Sources whose status is written by something other than Pharos or a person in the admin. */
    public const OUTSIDE_SOURCES = ['kuma', 'webhook', 'upstream'];

    protected $guarded = [];

    /** Written on every report; only a change of source is an event worth an audit line. */
    protected $auditIgnore = ['reported_at', 'reported_by_token_id'];

    protected $attributes = [
        'status' => 1,
        'enabled' => true,
        'show_uptime' => true,
        'source' => 'manual',
        'position' => 0,
    ];

    protected $casts = [
        'status' => ComponentStatus::class,
        'enabled' => 'boolean',
        'show_uptime' => 'boolean',
        'show_latency' => 'boolean',
        'reported_at' => LocalTime::class,
        'created_at' => LocalTime::class,
        'updated_at' => LocalTime::class,
    ];

    /** @return BelongsTo<ComponentGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(ComponentGroup::class, 'component_group_id');
    }

    /** @return HasOne<Check, $this> */
    public function check(): HasOne
    {
        return $this->hasOne(Check::class);
    }

    /** @return HasMany<CheckResult, $this> */
    public function results(): HasMany
    {
        return $this->hasMany(CheckResult::class);
    }

    /** @return HasMany<UptimeDay, $this> */
    public function uptimeDays(): HasMany
    {
        return $this->hasMany(UptimeDay::class);
    }

    /** @return BelongsToMany<Incident, $this> */
    public function incidents(): BelongsToMany
    {
        return $this->belongsToMany(Incident::class)->withPivot('status');
    }

    /** @return BelongsTo<ApiToken, $this> */
    public function reportedBy(): BelongsTo
    {
        return $this->belongsTo(ApiToken::class, 'reported_by_token_id');
    }

    /** Pharos runs an enabled check of its own against this component. */
    public function isChecked(): bool
    {
        return (bool) $this->check?->enabled;
    }

    /** No check of its own, and something outside writes the status (Uptime Kuma, the API, an upstream page). */
    public function isSetFromOutside(): bool
    {
        return ! $this->isChecked() && in_array($this->source, self::OUTSIDE_SOURCES, true);
    }

    /** Neither checked nor reported: only a person changes it. */
    public function isSetByHand(): bool
    {
        return ! $this->isChecked() && ! $this->isSetFromOutside();
    }

    /**
     * What to save along with a status that came in through the API: the label
     * follows the facts. A component with its own enabled check is left alone,
     * and a source someone chose on purpose (Kuma, upstream) is kept.
     *
     * @return array<string, mixed>
     */
    public function reportedAttributes(?ApiToken $token, string $source): array
    {
        if ($this->isChecked()) {
            return [];
        }

        return [
            'source' => $this->isSetFromOutside() ? $this->source : $source,
            'reported_at' => now(),
            'reported_by_token_id' => $token?->id,
        ];
    }

    /** Components without an enabled check of their own that something outside keeps up to date. */
    public function scopeSetFromOutside($query)
    {
        return $query->whereIn('source', self::OUTSIDE_SOURCES)
            ->whereDoesntHave('check', fn ($check) => $check->where('enabled', true));
    }

    public function tagList(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $this->tags))));
    }
}
