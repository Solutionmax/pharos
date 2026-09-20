<?php

namespace App\Models;

use App\Casts\LocalTime;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\LocalTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ApiToken extends Model
{
    use Auditable, LocalTimestamps;

    protected $auditName = 'api_token';

    /** Columns the check runner and delivery code touch on their own. */
    protected $auditIgnore = ['last_used_at'];

    protected $guarded = [];

    protected $hidden = ['token_hash'];

    protected $casts = [
        'last_used_at' => LocalTime::class,
        'created_at' => LocalTime::class,
        'updated_at' => LocalTime::class,
    ];

    /** Returns [model, plaintext]. The plaintext is shown once and never stored. */
    public static function issue(string $name, ?User $user = null, ?int $statusPageId = null): array
    {
        $plain = Str::random(40);

        return [self::create([
            'name' => $name,
            'token_hash' => hash('sha256', $plain),
            'user_id' => $user?->id,
            'status_page_id' => $statusPageId ?? StatusPage::defaultId(),
        ]), $plain];
    }

    public static function findByPlaintext(string $plain): ?self
    {
        return self::where('token_hash', hash('sha256', $plain))->first();
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<StatusPage, $this> */
    public function statusPage(): BelongsTo
    {
        return $this->belongsTo(StatusPage::class);
    }
}
