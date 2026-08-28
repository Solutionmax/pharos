<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Two worlds, one check.
 *
 * On Docker a host-side updater owns the image, exactly as Portalis does: it
 * writes a status file, the app shows a banner, and applying drops a trigger
 * file the host watches. The app never pulls its own image, because it cannot.
 *
 * On shared hosting there is no daemon and no root, but the app owns its own
 * files, so it can replace them itself. Both paths refuse anything that is not
 * signed by our key.
 */
class Updater
{
    public const CACHE_KEY = 'pharos.update.check';

    public const CHECK_INTERVAL_MINUTES = 60;

    public function __construct(protected License $license) {}

    public function current(): string
    {
        return (string) config('pharos.version');
    }

    /**
     * A version pinned in .env outlives every update, because .env is the one
     * thing an update must not touch. On a self-updating install that means the
     * same release keeps being offered after it has already been applied.
     */
    public function versionIsPinned(): bool
    {
        return ! $this->managed() && filled(env('PHAROS_VERSION'));
    }

    /** True when a host-side updater is in charge of the image. */
    public function managed(): bool
    {
        return is_file((string) config('pharos.update.status_file'));
    }

    /** @return array{version:string,notes:string,url:string,sha256:string,released_at:string}|null */
    public function latest(bool $fresh = false): ?array
    {
        return $this->lastCheck($fresh)['manifest'];
    }

    /**
     * What the last check found, whatever it found. The whole outcome is cached,
     * not just a manifest: Cache::remember drops a null result, so a server that
     * is down or answers 404 used to be asked again on every admin page — and
     * an install behind a firewall waited the full timeout for each of them.
     *
     * @return array{state:'ok'|'no_release'|'unreachable'|'invalid'|'disabled',manifest:array|null,checked_at:string|null,error:string|null}
     */
    public function lastCheck(bool $fresh = false): array
    {
        if (! config('pharos.update.check_enabled') || ! config('pharos.update.manifest_url')) {
            return ['state' => 'disabled', 'manifest' => null, 'checked_at' => null, 'error' => null];
        }

        if ($fresh) {
            Cache::forget(self::CACHE_KEY);
        }

        return Cache::remember(self::CACHE_KEY, now()->addMinutes(self::CHECK_INTERVAL_MINUTES), fn () => $this->check());
    }

    /** The host the manifest comes from, for telling the operator who is asked. */
    public function manifestHost(): ?string
    {
        return parse_url((string) config('pharos.update.manifest_url'), PHP_URL_HOST) ?: null;
    }

    protected function check(): array
    {
        $outcome = fn (string $state, ?array $manifest = null, ?string $error = null) => [
            'state' => $state,
            'manifest' => $manifest,
            'checked_at' => now()->toIso8601String(),
            'error' => $error,
        ];

        try {
            $response = Http::timeout(8)->get((string) config('pharos.update.manifest_url'));
        } catch (\Throwable $e) {
            // A release server we cannot reach is not an error the operator
            // needs to see mid-outage; it just means "no news".
            Log::info('Update check failed', ['error' => $e->getMessage()]);

            // cURL appends a link to its error codes; the operator wants the host, not the manual.
            $error = preg_replace('/\s*\(see https?:\/\/\S+\)/', '', $e->getMessage());

            return $outcome('unreachable', error: Str::limit($error, 100));
        }

        if ($response->status() === 404) {
            return $outcome('no_release');
        }

        if (! $response->successful()) {
            return $outcome('unreachable', error: 'HTTP '.$response->status());
        }

        $manifest = $this->verify($response->body());

        return $manifest === null ? $outcome('invalid') : $outcome('ok', $manifest);
    }

    /**
     * A manifest is "<base64url payload>.<base64url signature>", same shape as a
     * licence key. Returns null on anything suspicious rather than throwing:
     * a broken update check must never take the status page down.
     */
    public function verify(string $manifest): ?array
    {
        $publicKey = config('pharos.license_public_key');

        if (! $publicKey || ! str_contains($manifest, '.')) {
            return null;
        }

        [$payloadPart, $signaturePart] = explode('.', trim($manifest), 2);

        $payload = base64_decode(strtr($payloadPart, '-_', '+/'), true);
        $signature = base64_decode(strtr($signaturePart, '-_', '+/'), true);

        if ($payload === false || $signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return null;
        }

        try {
            $ok = sodium_crypto_sign_verify_detached($signature, $payload, sodium_hex2bin($publicKey));
        } catch (\SodiumException) {
            return null;
        }

        if (! $ok) {
            return null;
        }

        $data = json_decode($payload, true);

        // The purpose field is why a licence key cannot be replayed as an update
        // manifest, and vice versa: same key, different claims.
        if (! is_array($data) || ($data['purpose'] ?? null) !== 'pharos-release') {
            return null;
        }

        foreach (['version', 'url', 'sha256'] as $required) {
            if (empty($data[$required])) {
                return null;
            }
        }

        return $data;
    }

    public function updateAvailable(): bool
    {
        $latest = $this->latest();

        return $latest !== null && version_compare($latest['version'], $this->current(), '>');
    }

    /** What the host-side updater last reported, on a Docker install. */
    public function managedStatus(): ?array
    {
        if (! $this->managed()) {
            return null;
        }

        $raw = @file_get_contents((string) config('pharos.update.status_file'));
        $data = json_decode((string) $raw, true);

        return is_array($data) ? $data : null;
    }

    /** Asks the host-side updater to pull and restart. */
    public function requestManagedUpdate(): bool
    {
        if (! $this->managed()) {
            return false;
        }

        $trigger = (string) config('pharos.update.trigger_file');
        File::ensureDirectoryExists(dirname($trigger));

        return File::put($trigger, json_encode([
            'requested_at' => now()->toIso8601String(),
        ])) !== false;
    }
}
