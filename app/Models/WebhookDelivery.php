<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property int $status_page_id */
class WebhookDelivery extends Model
{
    protected $guarded = [];

    protected $casts = ['payload' => 'encrypted:array', 'sent_at' => 'datetime', 'next_attempt_at' => 'datetime'];

    /** @return BelongsTo<WebhookEndpoint, $this> */
    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class, 'webhook_endpoint_id');
    }
}
