<?php

namespace App\Models\Concerns;

use App\Models\StatusPage;
use App\Services\PageContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;
use LogicException;

trait BelongsToStatusPage
{
    public static function bootBelongsToStatusPage(): void
    {
        static::addGlobalScope('status_page', function (Builder $builder): void {
            $model = $builder->getModel();

            // Historical migrations instantiate models before this column exists.
            if (Schema::hasColumn($model->getTable(), 'status_page_id')) {
                $builder->where($model->qualifyColumn('status_page_id'), app(PageContext::class)->id());
            }
        });

        static::creating(function ($model): void {
            if (Schema::hasColumn($model->getTable(), 'status_page_id')) {
                $model->setAttribute('status_page_id', app(PageContext::class)->id());
            }
        });

        static::updating(function ($model): void {
            if ($model->isDirty('status_page_id')) {
                throw new LogicException('Status page ownership cannot be changed.');
            }
        });
    }

    /** @return BelongsTo<StatusPage, $this> */
    public function statusPage(): BelongsTo
    {
        return $this->belongsTo(StatusPage::class);
    }
}
