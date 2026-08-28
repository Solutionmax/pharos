<?php

namespace App\Models;

use App\Casts\LocalTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line in the audit trail. Append-only by intent: nothing in the
 * application updates or deletes a row, apart from pruning by age.
 */
class AuditEntry extends Model
{
    protected $table = 'audit_log';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['changes' => 'array', 'created_at' => LocalTime::class];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** "component.created" reads as "Component created" in a table. */
    public function actionLabel(): string
    {
        [$subject, $verb] = array_pad(explode('.', $this->action, 2), 2, '');

        return ucfirst(str_replace('_', ' ', $subject)).' '.$verb;
    }
}
