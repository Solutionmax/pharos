<?php

namespace App\Services;

class InitialSetup
{
    public function path(): string
    {
        return storage_path('app/private/setup-key');
    }

    /** Only the filesystem owner or the authenticated web installer knows this key. */
    public function key(): string
    {
        if ($key = config('pharos.setup_key')) {
            return (string) $key;
        }
        $path = $this->path();
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }
        $file = fopen($path, 'c+');
        if (! $file || ! flock($file, LOCK_EX)) {
            throw new \RuntimeException('Cannot secure the installation key.');
        }
        try {
            chmod($path, 0600);
            $key = trim(stream_get_contents($file));
            if ($key === '') {
                $key = bin2hex(random_bytes(32));
                fwrite($file, $key);
                fflush($file);
            }

            return $key;
        } finally {
            flock($file, LOCK_UN);
            fclose($file);
        }
    }
}
