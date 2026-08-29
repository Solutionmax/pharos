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

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * One line per change. A change is either {from, to} or a plain value
     * (a backup's name, a template key); the plain kind used to render as "— → —".
     *
     * @return list<array{field: string, from: ?string, to: ?string, plain: bool}>
     */
    public function changeLines(): array
    {
        $lines = [];
        // $this->changes would hit Eloquent's own dirty-tracking property, not the column.
        foreach ((array) ($this->getAttribute('changes') ?: []) as $field => $change) {
            $label = ucfirst(str_replace('_', ' ', (string) $field));
            $lines[] = is_array($change)
                ? ['field' => $label, 'from' => $change['from'] ?? null, 'to' => $change['to'] ?? null, 'plain' => false]
                : ['field' => $label, 'from' => null, 'to' => (string) $change, 'plain' => true];
        }

        return $lines;
    }

    /** "component.created" reads as "Component created" in a table. */
    public function actionLabel(): string
    {
        [$subject, $verb] = array_pad(explode('.', $this->action, 2), 2, '');

        return ucfirst(str_replace('_', ' ', $subject)).' '.$verb;
    }
}
