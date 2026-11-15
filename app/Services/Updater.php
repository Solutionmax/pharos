<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Two worlds, one check.
 *
 * Docker images are updated on the host with Compose. The status file marks
 * external ownership; it does not prove that an update worker is connected.
 *
 * On shared hosting there is no daemon and no root, but the app owns its own
 * files, so it can replace them itself. The release check and shared-hosting
 * archive updates require our signature.
 */
class Updater
{
    public const CACHE_KEY = 'pharos.update.check';

    public const CHECK_INTERVAL_MINUTES = 60;

    /**
     * The newest released_at (Unix seconds) of any manifest this install has
     * accepted. A signed manifest stays valid for ever, so without this an old
     * one, replayed by whoever sits between us and the release server, would
     * be accepted again: an older release offered as new, or the newest one
     * hidden behind it.
     */
    public const RELEASED_AT_KEY = 'update.highest_released_at';

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
        // The process environment, on purpose: a pin set by the host (Docker, SetEnv)
        // must be seen even when the config is cached, and .env is never the place for it.
        $pinned = getenv('PHAROS_VERSION');

        return ! $this->managed() && $pinned !== false && $pinned !== '';
    }

    /** True when application files are managed outside this process. */
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
     * @return array{state:'ok'|'no_release'|'unreachable'|'invalid'|'stale'|'disabled',manifest:array|null,checked_at:string|null,error:string|null}
     */
    public function lastCheck(bool $fresh = false): array
    {
        if (! InstallSettings::updateCheckEnabled() || ! config('pharos.update.manifest_url')) {
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

        if ($manifest === null) {
            return $outcome('invalid');
        }

        if (($problem = $this->replayProblem($manifest)) !== null) {
            Log::warning('Update manifest refused as a replay', ['version' => $manifest['version'], 'released_at' => $manifest['released_at'] ?? null]);

            return $outcome('stale', error: $problem);
        }

        $this->remember($manifest);

        return $outcome('ok', $manifest);
    }

    /**
     * When a manifest says it was released, as Unix seconds, or null when it
     * does not say or says it in a form we do not read. A date alone (the 1.1
     * manifests) counts as midnight UTC.
     */
    public static function releasedAt(array $manifest): ?int
    {
        $value = $manifest['released_at'] ?? null;

        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2}(:\d{2}(\.\d{1,6})?)?(Z|[+-]\d{2}:?\d{2}))?$/', $value)) {
            return null;
        }

        try {
            return (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->getTimestamp();
        } catch (\Exception) {
            return null;
        }
    }

    /** The newest released_at accepted so far, or null before the first dated manifest. */
    public function highestReleasedAt(): ?int
    {
        $seen = Setting::get(self::RELEASED_AT_KEY);

        return is_numeric($seen) ? (int) $seen : null;
    }

    /**
     * Why this manifest must not be accepted, or null when it may. Older than
     * one already accepted is a replay. Undated is only fine until a dated one
     * has been seen: that keeps the 1.1 manifests working and stops an undated
     * one from being slipped in afterwards.
     */
    public function replayProblem(array $manifest): ?string
    {
        $seen = $this->highestReleasedAt();
        $at = self::releasedAt($manifest);

        if ($seen === null) {
            return null;
        }

        if ($at === null) {
            return 'The release manifest carries no release date, and a dated one was accepted before. It was refused.';
        }

        if ($at < $seen) {
            return 'The release manifest is older than one this installation accepted before (released '
                .gmdate('Y-m-d H:i', $at).' UTC, seen '.gmdate('Y-m-d H:i', $seen).' UTC). It was refused.';
        }

        return null;
    }

    /** Raise the mark to this manifest's date; it never goes down. */
    protected function remember(array $manifest): void
    {
        $at = self::releasedAt($manifest);
        $seen = $this->highestReleasedAt();

        if ($at !== null && ($seen === null || $at > $seen)) {
            Setting::put(self::RELEASED_AT_KEY, (string) $at);
        }
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

        // Signed along with the rest. A date in a form we cannot read could not
        // be compared, so it is refused rather than treated as missing.
        if (isset($data['released_at']) && self::releasedAt($data) === null) {
            return null;
        }

        return $data;
    }

    public function updateAvailable(): bool
    {
        $latest = $this->latest();

        return $latest !== null && version_compare($latest['version'], $this->current(), '>');
    }

    /** Installation metadata written by the Docker entrypoint. */
    public function managedStatus(): ?array
    {
        if (! $this->managed()) {
            return null;
        }

        $raw = @file_get_contents((string) config('pharos.update.status_file'));
        $data = json_decode((string) $raw, true);

        return is_array($data) ? $data : null;
    }
}
