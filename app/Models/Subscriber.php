<?php

namespace App\Models;

use App\Casts\LocalTime;
use App\Models\Concerns\LocalTimestamps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * A visitor who asked to be mailed about incidents. Not Auditable: the public
 * opt-in has no actor, and what an operator does to a subscriber is recorded by
 * name in the controller (removed, confirmation resent).
 */
class Subscriber extends Model
{
    use LocalTimestamps;

    /** How long a confirmation link is good for. */
    public const CONFIRM_HOURS = 24;

    /** An address that never confirmed is forgotten after this many days. */
    public const PENDING_DAYS = 7;

    protected $guarded = [];

    protected $hidden = ['token'];

    protected $casts = [
        'verified_at' => LocalTime::class,
        'unsubscribed_at' => LocalTime::class,
        'created_at' => LocalTime::class,
        'updated_at' => LocalTime::class,
    ];

    /** @return HasMany<SubscriberNotification, $this> */
    public function notifications(): HasMany
    {
        return $this->hasMany(SubscriberNotification::class);
    }

    /** Confirmed and not opted out: the only people who get incident mail. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNotNull('verified_at')->whereNull('unsubscribed_at');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('verified_at');
    }

    public function isActive(): bool
    {
        return $this->verified_at !== null && $this->unsubscribed_at === null;
    }

    public function isPending(): bool
    {
        return $this->verified_at === null;
    }

    public static function freshToken(): string
    {
        return Str::random(40);
    }

    /**
     * Signed relative to the path, not the absolute URL: a proxy that terminates
     * TLS hands Laravel an http:// request for an https:// link, and an absolute
     * signature would refuse every click. The host comes from APP_URL, which is
     * what a cron-sent mail has to go on anyway.
     */
    public function confirmUrl(): string
    {
        return url(URL::temporarySignedRoute(
            'subscribe.confirm',
            now()->addHours(self::CONFIRM_HOURS),
            ['subscriber' => $this->id, 'token' => $this->token],
            absolute: false,
        ));
    }

    /** No expiry: the link in a mail from last year must still work. */
    public function unsubscribeUrl(): string
    {
        return url(URL::signedRoute(
            'unsubscribe',
            ['subscriber' => $this->id, 'token' => $this->token],
            absolute: false,
        ));
    }
}
