<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StatusPageSetting extends Model
{
    use Auditable;

    protected $auditName = 'setting';

    protected $guarded = [];

    /** @return BelongsTo<StatusPage, $this> */
    public function statusPage(): BelongsTo
    {
        return $this->belongsTo(StatusPage::class);
    }

    /** @param array<string, mixed> $changes */
    public function auditFilter(array $changes): array
    {
        $sensitive = str((string) $this->getAttribute('key'))
            ->contains(['secret', 'token', 'password']);

        if ($sensitive && isset($changes['value'])) {
            $changes['value'] = ['from' => '****', 'to' => '****'];
        }

        return $changes;
    }
}
