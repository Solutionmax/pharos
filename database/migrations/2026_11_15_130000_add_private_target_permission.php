<?php

use App\Services\SafeHttp;
use App\Support\IpAddress;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Page editors may no longer point checks and notification destinations at
 * private networks unless the installation allows it (Settings, General).
 *
 * allow_private marks a check or destination an installation administrator
 * saved: those may stay private whatever the setting says. Rows that exist
 * now get false, so the setting decides for them: on when any of them already
 * reaches a private address (nothing changes for that install), off otherwise.
 */
return new class extends Migration
{
    private const SETTING = 'network.editor_private_targets';

    /** Names that only make sense inside a network, decided without DNS. */
    private const LOCAL_SUFFIXES = ['.local', '.lan', '.internal', '.intranet', '.home', '.home.arpa', '.localdomain', '.corp'];

    /**
     * Seconds of DNS this migration may spend. The 1.1 updater migrates inside
     * a web request, so dead DNS on an install with many checks must not run it
     * into max_execution_time. Past the budget a name decides nothing, the same
     * as a lookup that fails.
     */
    private const DNS_BUDGET = 20;

    private float $dnsUntil = 0.0;

    public function up(): void
    {
        Schema::table('checks', function (Blueprint $t) {
            $t->boolean('allow_private')->default(false);
        });
        Schema::table('webhook_endpoints', function (Blueprint $t) {
            $t->boolean('allow_private')->default(false);
        });

        if (DB::table('settings')->where('key', self::SETTING)->exists()) {
            return;
        }

        DB::table('settings')->insert(['key' => self::SETTING, 'value' => $this->privateTargetInUse() ? '1' : '0']);
        Cache::forget('setting.'.self::SETTING);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', self::SETTING)->delete();
        Cache::forget('setting.'.self::SETTING);

        Schema::table('checks', function (Blueprint $t) {
            $t->dropColumn('allow_private');
        });
        Schema::table('webhook_endpoints', function (Blueprint $t) {
            $t->dropColumn('allow_private');
        });
    }

    private function privateTargetInUse(): bool
    {
        $hosts = [];
        $this->dnsUntil = microtime(true) + self::DNS_BUDGET;

        foreach (DB::table('checks')->whereIn('type', ['http', 'tcp'])->get(['type', 'target']) as $check) {
            $hosts[] = $this->host((string) $check->type, (string) $check->target);
        }
        foreach (DB::table('webhook_endpoints')->pluck('url') as $url) {
            $hosts[] = $this->host('http', $this->plainUrl((string) $url));
        }

        foreach (array_unique(array_filter($hosts)) as $host) {
            if ($this->isPrivate($host)) {
                return true;
            }
        }

        return false;
    }

    /** Destination URLs are stored encrypted (WebhookEndpoint::url()); older rows may not be. */
    private function plainUrl(string $stored): string
    {
        if (str_starts_with($stored, 'http')) {
            return $stored;
        }

        try {
            return Crypt::decryptString($stored);
        } catch (Throwable) {
            return '';
        }
    }

    private function host(string $type, string $target): ?string
    {
        if ($type === 'tcp') {
            return preg_match('/^(\[[^\]]+\]|[^:]+):\d{1,5}$/', $target, $m) ? strtolower(trim($m[1], '[]')) : null;
        }

        $host = parse_url($target, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? strtolower(trim($host, '[]')) : null;
    }

    private function isPrivate(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return $this->privateAddress($host);
        }

        $name = rtrim($host, '.');
        if ($name === 'localhost' || str_ends_with($name, '.localhost')) {
            return false;
        }
        if (! str_contains($name, '.')) {
            return true;
        }
        foreach (self::LOCAL_SUFFIXES as $suffix) {
            if (str_ends_with($name, $suffix)) {
                return true;
            }
        }

        if (microtime(true) > $this->dnsUntil) {
            return false;
        }

        try {
            foreach (app(SafeHttp::class)->addresses($name) as $ip) {
                if ($this->privateAddress($ip)) {
                    return true;
                }
            }
        } catch (Throwable) {
            // A lookup that fails during the update decides nothing.
        }

        return false;
    }

    /** Internal, but not this machine or link local: those never worked anyway. */
    private function privateAddress(string $ip): bool
    {
        return IpAddress::isInternal($ip) && ! IpAddress::isNeverReachable($ip);
    }
};
