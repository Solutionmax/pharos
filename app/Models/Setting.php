<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Services\PageContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    use Auditable;

    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    protected $guarded = [];

    /**
     * A setting's value is only as sensitive as its key. The signing secret and
     * the licence key sit in this table next to the brand colour, so the value
     * is redacted per row instead of per column. That a rotation happened, and
     * by whom, is still recorded; only the value itself is withheld.
     *
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    public function auditFilter(array $changes): array
    {
        $sensitive = str((string) $this->getAttribute('key'))
            ->contains(['secret', 'token', 'password', 'license.key']);

        if ($sensitive && isset($changes['value'])) {
            $changes['value'] = ['from' => '****', 'to' => '****'];
        }

        return $changes;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        if (self::isPageKey($key)) {
            $pageId = app(PageContext::class)->id();

            return Cache::rememberForever(
                "status_page.$pageId.setting.$key",
                fn () => StatusPageSetting::query()
                    ->where('status_page_id', $pageId)
                    ->where('key', $key)
                    ->value('value'),
            ) ?? $default;
        }

        return Cache::rememberForever("setting.$key", fn () => self::find($key)?->value) ?? $default;
    }

    public static function put(string $key, mixed $value): void
    {
        if (self::isPageKey($key)) {
            $pageId = app(PageContext::class)->id();
            StatusPageSetting::updateOrCreate(
                ['status_page_id' => $pageId, 'key' => $key],
                ['value' => $value],
            );
            Cache::forget("status_page.$pageId.setting.$key");

            return;
        }

        self::updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget("setting.$key");
    }

    public static function isPageKey(string $key): bool
    {
        return str_starts_with($key, 'brand.')
            || str_starts_with($key, 'page.')
            || str_starts_with($key, 'subscribers.')
            || str_starts_with($key, 'mail.template.')
            || $key === 'integrations.webhook_secret';
    }
}
