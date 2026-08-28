<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ApiToken extends Model
{
    use Auditable;

    protected $auditName = 'api_token';

    /** Columns the check runner and delivery code touch on their own. */
    protected $auditIgnore = ['last_used_at'];

    protected $guarded = [];

    protected $hidden = ['token_hash'];

    protected $casts = ['last_used_at' => 'datetime'];

    /** Returns [model, plaintext]. The plaintext is shown once and never stored. */
    public static function issue(string $name): array
    {
        $plain = Str::random(40);

        return [self::create(['name' => $name, 'token_hash' => hash('sha256', $plain)]), $plain];
    }

    public static function findByPlaintext(string $plain): ?self
    {
        return self::where('token_hash', hash('sha256', $plain))->first();
    }
}
