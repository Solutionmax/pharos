<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;

class CronSetup
{
    public function php(): ?string
    {
        $marker = base_path('.pharos-cron-php');
        if (is_file($marker)) {
            $path = trim(file_get_contents($marker));
            if (str_starts_with($path, '/') && ! preg_match('/[\r\n%]/', $path)) {
                return $path;
            }
        }
        if (PHP_SAPI === 'cli') {
            return realpath(PHP_BINARY) ?: PHP_BINARY;
        }
        $mm = PHP_MAJOR_VERSION.PHP_MINOR_VERSION;
        $dot = PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;
        foreach ([dirname(PHP_BINARY).'/php', "/usr/local/php{$mm}/bin/php", "/opt/alt/php{$mm}/usr/bin/php", "/opt/cpanel/ea-php{$mm}/root/usr/bin/php", "/opt/plesk/php/{$dot}/bin/php", "/usr/bin/php{$dot}"] as $path) {
            if (@is_executable($path) && preg_match('~php/?[0-9]~', $path)) {
                return $path;
            }
        }

        return null;
    }

    public function command(?string $php = null): string
    {
        $php ??= $this->php();
        if (! $php) {
            throw new \RuntimeException('No versioned CLI PHP found. Ask your host for its absolute path.');
        }
        foreach ([base_path(), $php] as $path) {
            if (preg_match('/[\r\n%]/', $path)) {
                throw new \RuntimeException('Cron paths cannot contain newlines or percent signs.');
            }
        }

        return 'cd '.escapeshellarg(base_path()).' && '.escapeshellarg($php).' artisan schedule:run >> /dev/null 2>&1';
    }

    public function install(): string
    {
        $before = $this->read();
        $command = $this->command();
        foreach (explode("\n", $before) as $line) {
            if (trim($line) === '* * * * * '.$command) {
                return 'Cron already configured.';
            }
            if (! str_starts_with(ltrim($line), '#') && preg_match('~'.preg_quote(base_path(), '~').'(?=[/\s\'\"])~', $line) && str_contains($line, 'artisan schedule:run')) {
                throw new \RuntimeException('An existing Pharos job uses different settings. Review it manually; no task was changed.');
            }
        }
        $after = rtrim($before, "\n")."\n* * * * * ".$command."\n";
        if ($this->read() !== $before) {
            throw new \RuntimeException('Cron changed while preparing the task. Retry.');
        }
        $result = Process::timeout(10)->input($after)->run(['crontab', '-']);
        if (! $result->successful() || ! str_contains($this->read(), '* * * * * '.$command)) {
            throw new \RuntimeException('Cron could not be verified. Check the hosting panel before retrying.');
        }

        return 'Cron saved. The first completed scheduler run confirms monitoring.';
    }

    private function read(): string
    {
        $result = Process::timeout(10)->run(['crontab', '-l']);
        if ($result->successful()) {
            return $result->output();
        }
        if ($result->exitCode() === 1 && preg_match('/^no crontab for [^\r\n]+\s*$/i', trim($result->errorOutput()))) {
            return '';
        }
        throw new \RuntimeException('Cannot read the existing crontab. Use the hosting panel; no tasks were changed.');
    }
}
