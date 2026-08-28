<?php

namespace App\Services;

use App\Models\ApiToken;
use App\Models\AuditEntry;
use Illuminate\Database\Eloquent\Model;

/**
 * Who did what, and when.
 *
 * The rule that keeps this readable: **only actions with an actor are
 * recorded.** The check runner touches components every minute from cron and
 * has no actor, so it writes nothing here — its results already live on the
 * status page and in the incident timeline. What lands in the audit log is
 * what a person or an integration deliberately changed.
 */
class Audit
{
    /** Values never worth storing, and dangerous if they were. */
    public const REDACTED = ['password', 'mail.password', 'remember_token', 'token', 'token_hash', 'url', 'secret'];

    /** Null when nothing is signed in: cron, queue work, an artisan command. */
    public static function actor(): ?string
    {
        if ($user = auth()->user()) {
            return trim($user->name.' ('.$user->email.')');
        }

        $token = request()?->attributes?->get('api_token');

        return $token instanceof ApiToken ? 'API token: '.$token->name : null;
    }

    public static function record(string $action, ?Model $subject = null, array $changes = []): ?AuditEntry
    {
        $actor = self::actor();

        if ($actor === null) {
            return null;
        }

        return AuditEntry::create([
            'user_id' => auth()->id(),
            'actor' => $actor,
            'action' => $action,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey(),
            'subject_label' => $subject ? self::label($subject) : null,
            'changes' => $changes ?: null,
            'ip' => request()?->ip(),
            'created_at' => now(),
        ]);
    }

    /**
     * Drops lines older than the retention window (Settings → General, else
     * PHAROS_AUDIT_DAYS). Age is the only thing that bounds an append-only table.
     *
     * @return int how many lines went
     */
    public static function prune(): int
    {
        return AuditEntry::where('created_at', '<', now()->subDays(InstallSettings::auditDays()))->delete();
    }

    /** Records an action nobody is signed in for, such as a failed login. */
    public static function recordAs(string $actor, string $action, ?Model $subject = null, array $changes = []): AuditEntry
    {
        return AuditEntry::create([
            'user_id' => null,
            'actor' => $actor,
            'action' => $action,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey(),
            'subject_label' => $subject ? self::label($subject) : null,
            'changes' => $changes ?: null,
            'ip' => request()?->ip(),
            'created_at' => now(),
        ]);
    }

    /** Something a human recognises, because an id alone says nothing. */
    public static function label(Model $subject): string
    {
        if ($subject instanceof \App\Models\IncidentUpdate) {
            return 'Update on "'.$subject->incident?->name.'"';
        }

        foreach (['name', 'title', 'label', 'email', 'key'] as $attribute) {
            if (filled($subject->getAttribute($attribute))) {
                return (string) $subject->getAttribute($attribute);
            }
        }

        return class_basename($subject).' #'.$subject->getKey();
    }

    /** @return array<string, array{from: mixed, to: mixed}> */
    public static function diff(Model $model): array
    {
        $changes = [];

        foreach ($model->getDirty() as $key => $new) {
            if (in_array($key, self::REDACTED, true)) {
                $changes[$key] = ['from' => '••••', 'to' => '••••'];

                continue;
            }

            $changes[$key] = [
                'from' => self::scalar($model->getOriginal($key)),
                // getAttribute() runs the cast, so an enum column records its
                // label on both sides instead of "Identified → 4".
                'to' => self::scalar($model->getAttribute($key)),
            ];
        }

        return $changes;
    }

    /** Keeps the JSON column small and printable; a blob helps nobody read a table. */
    protected static function scalar(mixed $value): mixed
    {
        if (is_object($value)) {
            $value = method_exists($value, 'label') ? $value->label() : (string) $value;
        }

        return is_string($value) && mb_strlen($value) > 120
            ? mb_substr($value, 0, 120).'…'
            : $value;
    }
}
