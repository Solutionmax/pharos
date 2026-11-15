<?php

use App\Services\SafeHttp;
use App\Support\IpAddress;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Release 1.2.0 refuses carrier grade NAT (Tailscale), benchmarking, the IETF
 * block and multicast where it used to refuse only private ranges: for single
 * sign on, for a remote probe's hub and for remote backups without "private
 * network" ticked.
 *
 * An installation that already relies on such an address keeps working: the
 * identity provider host and a remote probe's hub (PHAROS_PROBE_HUB) go on the
 * internal hosts list, and a backup destination gets the private network
 * permission it would have needed.
 * Nothing else changes; a name that does not resolve now is left alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->keepInternalHosts(array_filter([
            $this->host(DB::table('settings')->where('key', 'sso.issuer')->value('value')),
            $this->host(config('monitoring.probe_hub')),
        ], fn (?string $host) => $this->onlyNewlyRefused($host)));
        $this->keepBackupDestinations();
    }

    public function down(): void
    {
        // What was added is a permission an administrator could have given by
        // hand; taking it away again could lock a working setup out.
    }

    /** Public to the 1.1 rule, internal to the 1.2 one, and not refused by both. */
    private function onlyNewlyRefused(?string $host): bool
    {
        if ($host === null || trim($host, '[] ') === '') {
            return false;
        }

        try {
            foreach (app(SafeHttp::class)->addresses(trim($host, '[] ')) as $ip) {
                if (IpAddress::isPublic($ip) && IpAddress::isInternal($ip) && ! IpAddress::isNeverReachable($ip)) {
                    return true;
                }
            }
        } catch (Throwable) {
            // DNS trouble during an update is not a reason to stop the update.
        }

        return false;
    }

    private function host(mixed $url): ?string
    {
        $host = is_string($url) && $url !== '' ? parse_url($url, PHP_URL_HOST) : null;

        return is_string($host) && $host !== '' ? trim($host, '[]') : null;
    }

    /** @param  array<int, string>  $add */
    private function keepInternalHosts(array $add): void
    {
        $raw = (string) DB::table('settings')->where('key', 'sso.internal_hosts')->value('value');
        $hosts = array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', $raw) ?: [])));
        $known = array_map('strtolower', $hosts);
        $added = false;

        foreach ($add as $host) {
            if (! in_array(strtolower($host), $known, true)) {
                $hosts[] = $host;
                $known[] = strtolower($host);
                $added = true;
            }
        }

        if ($added) {
            DB::table('settings')->updateOrInsert(['key' => 'sso.internal_hosts'], ['value' => implode(', ', $hosts)]);
            Cache::forget('setting.sso.internal_hosts');
        }
    }

    private function keepBackupDestinations(): void
    {
        foreach (DB::table('backup_destinations')->get(['id', 'driver', 'configuration']) as $row) {
            $config = json_decode((string) $row->configuration, true);

            if (! is_array($config) || ! empty($config['allow_private'])) {
                continue;
            }

            $host = $row->driver === 'sftp'
                ? ($config['host'] ?? null)
                : parse_url((string) ($config['endpoint'] ?? 'https://s3.'.($config['region'] ?? 'us-east-1').'.amazonaws.com'), PHP_URL_HOST);

            if (! is_string($host) || ! $this->onlyNewlyRefused($host)) {
                continue;
            }

            $config['allow_private'] = true;
            DB::table('backup_destinations')->where('id', $row->id)->update(['configuration' => json_encode($config)]);
        }
    }
};
