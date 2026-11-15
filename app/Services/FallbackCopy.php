<?php

namespace App\Services;

use App\Http\Controllers\StatusPageController;
use App\Models\BackupDestination;
use App\Models\Setting;
use App\Models\StatusPage;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * A self-hosted status page goes down with its server. This keeps a static
 * copy of every published page at one of the remote backup destinations, so
 * a bucket website or another web server can still show the last state.
 * Runs every minute; a page is uploaded only when what it says has changed.
 */
class FallbackCopy
{
    public const ENABLED = 'fallback.enabled';

    public const DESTINATION = 'fallback.destination_id';

    /** Published path => hash of the destination and the content last uploaded there. */
    public const HASHES = 'fallback.hashes';

    public const LAST_AT = 'fallback.last_at';

    public const LAST_BYTES = 'fallback.last_bytes';

    public const LAST_ERROR = 'fallback.last_error';

    /** Far beyond any status page; a file this size is a fault, not a page. */
    public const MAX_BYTES = 4194304;

    /** All uploads of one run share this, well inside the minute between runs. */
    public const BUDGET_SECONDS = 45;

    public function __construct(private RemoteBackup $remote, private StaticPage $static) {}

    public static function enabled(): bool
    {
        return Setting::get(self::ENABLED) === '1';
    }

    public static function destination(): ?BackupDestination
    {
        $id = Setting::get(self::DESTINATION);

        return is_numeric($id) ? BackupDestination::find((int) $id) : null;
    }

    /** @return array{destination: ?BackupDestination, last_at: ?Carbon, bytes: ?int, error: ?string} */
    public static function status(): array
    {
        $at = Setting::get(self::LAST_AT);
        $bytes = Setting::get(self::LAST_BYTES);

        return [
            'destination' => self::destination(),
            'last_at' => $at ? Carbon::parse($at)->setTimezone(Clock::timezone()) : null,
            'bytes' => is_numeric($bytes) ? (int) $bytes : null,
            'error' => Setting::get(self::LAST_ERROR),
        ];
    }

    /** Where a page's copy lives at the destination. */
    public static function path(StatusPage $page): string
    {
        return $page->getKey() === StatusPage::defaultId() ? 'index.html' : 'status/'.$page->slug.'/index.html';
    }

    /**
     * Renders every published page and uploads the ones that changed. Never
     * throws: a failure is kept for the settings screen and logged by type only,
     * as a storage error can quote the destination.
     *
     * @return array{uploaded: array<string, int>, error: ?string}
     */
    public function run(bool $force = false): array
    {
        $lock = Cache::lock('pharos:fallback-copy', 120);
        if (! $lock->get()) {
            return ['uploaded' => [], 'error' => null];
        }
        $uploaded = [];
        try {
            $destination = self::destination();
            if ($destination === null) {
                return $this->failed(__('Choose a backup destination for the fallback copy.'), $uploaded);
            }
            $stored = json_decode((string) Setting::get(self::HASHES, '{}'), true);
            $known = ! $force && is_array($stored) ? $stored : [];
            $filesystem = null;
            $savedAt = now();
            $pages = StatusPage::where('is_published', true)->whereNull('archived_at')->orderBy('id')->get();
            foreach ($pages as $page) {
                $path = self::path($page);
                // The slug becomes a folder at the destination: only the shape the page form allows.
                if ($path !== 'index.html' && ! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', (string) $page->slug)) {
                    continue;
                }
                [$html, $content] = $this->render($page, $savedAt);
                $hash = hash('sha256', $destination->getKey().'|'.$content);
                if (($known[$path] ?? null) === $hash) {
                    continue;
                }
                if (strlen($html) > self::MAX_BYTES) {
                    throw new \RuntimeException('Static page too large');
                }
                $filesystem ??= $this->remote->filesystem($destination, self::MAX_BYTES, microtime(true) + self::BUDGET_SECONDS);
                // A web server reads SFTP files as another user; on S3 the bucket policy decides who reads.
                $filesystem->write($path, $html, [
                    'visibility' => $destination->driver === 'sftp' ? 'public' : 'private',
                    'mimetype' => 'text/html; charset=utf-8',
                    'CacheControl' => 'max-age=60',
                ]);
                $known[$path] = $hash;
                $uploaded[$path] = strlen($html);
                Setting::put(self::HASHES, json_encode($known, JSON_UNESCAPED_SLASHES));
            }
            if ($uploaded !== []) {
                Setting::put(self::LAST_AT, now()->toIso8601String());
                Setting::put(self::LAST_BYTES, (string) array_sum($uploaded));
            }
            if (Setting::get(self::LAST_ERROR) !== null) {
                Setting::put(self::LAST_ERROR, null);
            }

            return ['uploaded' => $uploaded, 'error' => null];
        } catch (\Throwable $e) {
            Log::warning('Fallback copy failed', ['type' => get_class($e)]);

            return $this->failed(__('The last copy failed. Check the destination, its credentials and that this server can reach it.'), $uploaded);
        } finally {
            $lock->release();
        }
    }

    /**
     * One page as its static file, and the content that decides whether it
     * changed: the file without its saved-at line and without the minute the
     * page says it was checked, so a quiet page is not uploaded every minute.
     *
     * @return array{0: string, 1: string}
     */
    public function render(StatusPage $page, CarbonInterface $savedAt): array
    {
        return app(PageContext::class)->run($page->getKey(), fn () => Localization::run(Localization::page(),
            fn () => Clock::withInstallationZone(function () use ($savedAt) {
                $before = Clock::now()->format('H:i');
                $html = $this->static->make(app(StatusPageController::class)->snapshot());
                $minutes = array_unique([$before, Clock::now()->format('H:i')]);
                $content = str_replace(array_map(fn (string $m) => '>'.$m.'<', $minutes), '><', $html);

                return [$this->stamp($html, $savedAt), $content];
            })));
    }

    /** Always shown: whoever reads this file is reading a copy. */
    private function stamp(string $html, CarbonInterface $savedAt): string
    {
        $line = e(__('Saved copy · :time · this server may be unreachable', [
            'time' => Carbon::instance($savedAt)->setTimezone(Clock::timezone())->translatedFormat('j M Y, H:i'),
        ]));
        $note = '<p class="fallback-note" role="note" style="margin:0;padding:10px 16px;text-align:center;'
            .'font:500 13px/1.45 var(--sans);background:var(--amber-soft);color:var(--amber-ink);'
            .'border-bottom:1px solid var(--line)">'.$line.'</p>';
        // Not indexed next to the real page; the chart's "point at a day" needs the script that is gone.
        $head = '<meta name="robots" content="noindex"><style>.readout{display:none}</style>';
        $html = (string) preg_replace_callback('#<head\b[^>]*>#i', fn (array $m) => $m[0].$head, $html, 1);

        return (string) preg_replace_callback('#<body\b[^>]*>#i', fn (array $m) => $m[0].$note, $html, 1);
    }

    /** @param  array<string, int>  $uploaded */
    private function failed(string $message, array $uploaded): array
    {
        Setting::put(self::LAST_ERROR, $message);
        if ($uploaded !== []) {
            Setting::put(self::LAST_AT, now()->toIso8601String());
            Setting::put(self::LAST_BYTES, (string) array_sum($uploaded));
        }

        return ['uploaded' => $uploaded, 'error' => $message];
    }
}
