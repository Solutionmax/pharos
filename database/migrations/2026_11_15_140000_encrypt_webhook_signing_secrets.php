<?php

use App\Services\WebhookSecret;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * The webhook signing secret of every page, encrypted with APP_KEY. Receivers
 * notice nothing: the secret itself stays the same. A value that is already
 * encrypted is left alone, so running this twice changes nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->rewrite(fn (string $value) => WebhookSecret::isEncryptedPayload($value) ? null : Crypt::encryptString($value));
    }

    /** Back to plain text, for a 1.1 install restored on top of this database. */
    public function down(): void
    {
        $this->rewrite(function (string $value) {
            try {
                return Crypt::decryptString($value);
            } catch (DecryptException) {
                return null;
            }
        });
    }

    /** @param  Closure(string): ?string  $change  the new value, or null to leave the row */
    private function rewrite(Closure $change): void
    {
        $rows = DB::table('status_page_settings')->where('key', WebhookSecret::KEY)->get(['id', 'status_page_id', 'value']);

        foreach ($rows as $row) {
            $value = (string) $row->value;
            $new = $value === '' ? null : $change($value);

            if ($new !== null) {
                DB::table('status_page_settings')->where('id', $row->id)->update(['value' => $new]);
            }
            // Setting::get() caches forever; the old value must not outlive this.
            Cache::forget('status_page.'.$row->status_page_id.'.setting.'.WebhookSecret::KEY);
        }
    }
};
