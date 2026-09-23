<?php

namespace App\Models;

use App\Casts\LocalTime;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToStatusPage;
use App\Models\Concerns\LocalTimestamps;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Planned work on one page. The scheduler moves it through announced, started
 * and completed; cancelling stops it wherever it is.
 *
 * @property int $id
 * @property int $status_page_id
 * @property string $title
 * @property string|null $message
 * @property int $announce_minutes
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 * @property CarbonImmutable|null $announced_at
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $cancelled_at
 */
class Maintenance extends Model
{
    use Auditable, BelongsToStatusPage, LocalTimestamps;

    protected $auditName = 'maintenance';

    /** Stamps the scheduler sets on its own; only a person's change is an event. */
    protected $auditIgnore = ['announced_at', 'started_at', 'completed_at'];

    protected $guarded = [];

    /** Minutes => label, for the "tell subscribers" choice. */
    public const LEAD_TIMES = [
        0 => 'Do not announce',
        60 => '1 hour before',
        360 => '6 hours before',
        1440 => '24 hours before',
        2880 => '2 days before',
        4320 => '3 days before',
        10080 => '1 week before',
    ];

    protected $casts = [
        'announce_minutes' => 'integer',
        'starts_at' => LocalTime::class,
        'ends_at' => LocalTime::class,
        'announced_at' => LocalTime::class,
        'started_at' => LocalTime::class,
        'completed_at' => LocalTime::class,
        'cancelled_at' => LocalTime::class,
        'created_at' => LocalTime::class,
        'updated_at' => LocalTime::class,
    ];

    /** @return BelongsToMany<Component, $this> */
    public function components(): BelongsToMany
    {
        return $this->belongsToMany(Component::class)->withPivot('previous_status', 'applied_status')->orderBy('position');
    }

    /** Not cancelled, not over: what the public page lists. */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->whereNull('cancelled_at')->whereNull('completed_at')->where('ends_at', '>', now());
    }

    /** scheduled, in_progress, completed or cancelled, by the clock and the stamps. */
    public function state(): string
    {
        return match (true) {
            $this->cancelled_at !== null => 'cancelled',
            $this->completed_at !== null || $this->ends_at->lte(now()) => 'completed',
            $this->starts_at->lte(now()) => 'in_progress',
            default => 'scheduled',
        };
    }

    public function stateLabel(): string
    {
        return match ($this->state()) {
            'cancelled' => 'Cancelled',
            'completed' => 'Completed',
            'in_progress' => 'In progress',
            default => 'Scheduled',
        };
    }

    public function isOpen(): bool
    {
        return in_array($this->state(), ['scheduled', 'in_progress'], true);
    }

    /** Whether a component is inside a window the scheduler has started and not ended. */
    public static function holds(int $componentId): bool
    {
        return self::query()->whereNotNull('started_at')->whereNull('completed_at')->whereNull('cancelled_at')
            ->whereHas('components', fn ($q) => $q->whereKey($componentId))->exists();
    }
}
