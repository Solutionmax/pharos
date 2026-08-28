<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
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
        return Cache::rememberForever("setting.$key", fn () => self::find($key)?->value) ?? $default;
    }

    public static function put(string $key, mixed $value): void
    {
        self::updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget("setting.$key");
    }
}
