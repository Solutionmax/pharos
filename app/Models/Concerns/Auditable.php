<?php

namespace App\Models\Concerns;

use App\Services\Audit;

/**
 * Records create, change and delete on a model, without a line in any
 * controller. Model events also catch changes made through the API, which is
 * the half a controller-level trail would miss.
 *
 * Two things keep the table readable:
 *  - Audit::record() drops anything with no actor, so the cron check runner is
 *    silent even though it writes to components every minute.
 *  - $auditIgnore lists columns the machine touches on its own. An update that
 *    changed nothing else is not an event.
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(fn ($model) => Audit::record($model->auditAction('created'), $model));

        static::updating(function ($model) {
            $changes = $model->auditFilter(
                array_diff_key(Audit::diff($model), array_flip($model->auditIgnored()))
            );

            if ($changes !== []) {
                Audit::record($model->auditAction('updated'), $model, $changes);
            }
        });

        static::deleted(fn ($model) => Audit::record($model->auditAction('deleted'), $model));
    }

    public function auditAction(string $verb): string
    {
        return ($this->auditName ?? str(class_basename($this))->snake()->toString()).'.'.$verb;
    }

    /**
     * Last word on what gets stored, for models where the sensitivity of a
     * column depends on the row rather than on the column name.
     *
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    public function auditFilter(array $changes): array
    {
        return $changes;
    }

    /** @return array<int, string> */
    public function auditIgnored(): array
    {
        return array_merge(['updated_at', 'created_at'], $this->auditIgnore ?? []);
    }
}
