<?php

use App\Services\SafeHttp;
use App\Support\IpAddress;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * From 1.2.0 a page SMTP host is checked on every send unless an installation
 * administrator saved it (mail.host_trusted). Page SMTP saved by 0.7.0, before
 * that marker existed, has no marker at all; it was never checked when mail
 * went out. Where such a page points at an internal relay, it is marked as
 * trusted so its mail keeps flowing. Public hosts need nothing: they pass.
 */
return new class extends Migration
{
    private const MODE = 'mail.mode';

    private const HOST = 'mail.host';

    private const TRUSTED = 'mail.host_trusted';

    public function up(): void
    {
        $custom = DB::table('status_page_settings')->where('key', self::MODE)->where('value', 'custom')->pluck('status_page_id');

        foreach ($custom as $pageId) {
            $hasMarker = DB::table('status_page_settings')->where('status_page_id', $pageId)->where('key', self::TRUSTED)->exists();
            $host = trim((string) DB::table('status_page_settings')->where('status_page_id', $pageId)->where('key', self::HOST)->value('value'), "[] \t");

            if ($hasMarker || $host === '' || ! $this->internal($host)) {
                continue;
            }

            DB::table('status_page_settings')->insert([
                'status_page_id' => $pageId,
                'key' => self::TRUSTED,
                'value' => '1',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // The marker is what 0.7.1 and later write anyway; leaving it is harmless.
    }

    private function internal(string $host): bool
    {
        try {
            foreach (app(SafeHttp::class)->addresses($host) as $ip) {
                if (IpAddress::isInternal($ip)) {
                    return true;
                }
            }
        } catch (Throwable) {
            // Unknown now: the send-time check decides.
        }

        return false;
    }
};
