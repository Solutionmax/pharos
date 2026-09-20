<?php

namespace App\Models;

use App\Casts\LocalTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class StatusPage extends Model
{
    public const DEFAULT_ID_SETTING = 'status_page.default_id';

    public const FALLBACK_DEFAULT_ID = 1;

    protected $guarded = [];

    protected $attributes = ['is_published' => false];

    protected $casts = [
        'is_published' => 'boolean',
        'archived_at' => LocalTime::class,
        'created_at' => LocalTime::class,
        'updated_at' => LocalTime::class,
    ];

    public static function defaultId(): int
    {
        if (! Schema::hasTable('settings')) {
            return self::FALLBACK_DEFAULT_ID;
        }

        return (int) (DB::table('settings')->where('key', self::DEFAULT_ID_SETTING)->value('value')
            ?: self::FALLBACK_DEFAULT_ID);
    }

    public static function default(): self
    {
        return self::query()->findOrFail(self::defaultId());
    }

    public function publicUrl(): string
    {
        if (filled($this->domain)) {
            $domain = (string) $this->domain;

            return str_contains($domain, '://') ? rtrim($domain, '/') : 'https://'.$domain;
        }

        $base = rtrim((string) config('app.url'), '/');

        return $this->getKey() === self::defaultId() ? $base : $base.'/status/'.$this->slug;
    }

    /** @return HasMany<StatusPageSetting, $this> */
    public function settings(): HasMany
    {
        return $this->hasMany(StatusPageSetting::class);
    }

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }
}
