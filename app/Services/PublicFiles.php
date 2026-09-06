<?php

namespace App\Services;

class PublicFiles
{
    /** Publish release-owned files and remove obsolete, unmodified release assets. */
    public function sync(string $base, ?string $releasePublic = null): void
    {
        $marker = $base.'/.pharos-public';
        if (! is_file($marker)) {
            return;
        }
        $web = realpath(trim(file_get_contents($marker)));
        if ($web === realpath($base.'/public')) {
            return;
        }
        if ($web === false || ! is_writable($web)) {
            throw new \RuntimeException('The installed public folder is not writable.');
        }
        $manifest = $base.'/storage/app/private/public-manifest.json';
        $previous = is_file($manifest) ? json_decode(file_get_contents($manifest), true, flags: JSON_THROW_ON_ERROR) : [];
        if (! is_array($previous)) {
            throw new \RuntimeException('The public file manifest is invalid.');
        }
        $source = $releasePublic ?? $base.'/public';
        $contents = [];
        $hashes = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $relative = substr($file->getPathname(), strlen($source) + 1);
            if ($file->isLink() || ! $file->isFile() || str_starts_with($relative, 'storage/') || $relative === '.htaccess') {
                continue;
            }
            $this->destination($web, $relative);
            $content = file_get_contents($file->getPathname());
            if ($relative === 'index.php') {
                $content = str_replace("__DIR__.'/../", var_export($base.'/', true).".'", $content);
            }
            $contents[$relative] = $content;
            $hashes[$relative] = hash('sha256', $content);
        }
        foreach ($previous as $relative => $hash) {
            $destination = $this->destination($web, $relative);
            if (! isset($hashes[$relative]) && is_file($destination) && hash_file('sha256', $destination) !== $hash) {
                throw new \RuntimeException('An obsolete public release file was edited locally; review it before updating.');
            }
        }
        if (! is_dir(dirname($manifest)) && ! mkdir(dirname($manifest), 0700, true)) {
            throw new \RuntimeException('Cannot create the public file manifest directory.');
        }
        // Journal new ownership before publishing, so rollback can remove new assets after a partial copy.
        $this->write($manifest, json_encode($hashes + $previous, JSON_THROW_ON_ERROR));
        foreach ($contents as $relative => $content) {
            $destination = $this->destination($web, $relative);
            if (! is_dir(dirname($destination)) && ! mkdir(dirname($destination), 0755, true)) {
                throw new \RuntimeException('Cannot create a public release directory.');
            }
            $this->write($destination, $content);
        }
        foreach (array_diff_key($previous, $hashes) as $relative => $hash) {
            $destination = $this->destination($web, $relative);
            if (is_file($destination) && ! unlink($destination)) {
                throw new \RuntimeException('Cannot remove an obsolete public release file.');
            }
        }
        $this->write($manifest, json_encode($hashes, JSON_THROW_ON_ERROR));
    }

    private function destination(string $web, string $relative): string
    {
        $segments = explode('/', $relative);
        if ($relative === '' || str_contains($relative, '\\') || array_intersect($segments, ['', '.', '..'])
            || $segments[0] === 'storage' || $relative === '.htaccess') {
            throw new \RuntimeException('Invalid public release path.');
        }
        $path = $web;
        foreach ($segments as $segment) {
            $path .= '/'.$segment;
            if (is_link($path)) {
                throw new \RuntimeException('Refusing a public symlink.');
            }
        }

        return $path;
    }

    private function write(string $destination, string $content): void
    {
        $temporary = $destination.'.'.bin2hex(random_bytes(6)).'.tmp';
        if (file_put_contents($temporary, $content) === false || ! rename($temporary, $destination)) {
            @unlink($temporary);
            throw new \RuntimeException('Cannot publish a public release file.');
        }
    }
}
