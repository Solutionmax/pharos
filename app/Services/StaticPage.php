<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;

/**
 * Turns the rendered public page into one file that stands on its own:
 * stylesheets inlined, images as data URIs, no JavaScript, no forms and
 * nothing fetched from anywhere. It works on the page the server renders, so
 * the copy cannot drift from the real thing. Only for Pharos' own templates:
 * this is not a sanitiser for HTML from outside.
 */
class StaticPage
{
    /** An image or stylesheet larger than this is left out rather than inlined. */
    public const MAX_ASSET_BYTES = 262144;

    private const IMAGE_TYPES = [
        'svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'gif' => 'image/gif', 'webp' => 'image/webp', 'ico' => 'image/x-icon',
    ];

    public function make(string $html): string
    {
        $html = $this->replace('#<script\b[^>]*>.*?</script\s*>#is', fn () => '', $html);
        // A form needs this server, and the theme switch needs a script.
        $html = $this->replace('#<form\b[^>]*>.*?</form\s*>#is', fn () => '', $html);
        $html = $this->replace('#<button\b[^>]*\bdata-theme-toggle\b[^>]*>.*?</button\s*>#is', fn () => '', $html);
        $html = $this->replace('#<link\b[^>]*>#i', fn (array $m) => $this->link($m[0]), $html);
        $html = $this->replace('#<img\b[^>]*>#i', fn (array $m) => $this->image($m[0]), $html);
        // A nonce belongs to one response; kept, it would change the copy every minute.
        $html = $this->replace('#\snonce="[^"]*"#i', fn () => '', $html);

        if (preg_match('#<(?:script|iframe|object|embed|form)\b#i', $html) === 1) {
            throw new \RuntimeException('The static copy still holds active content.');
        }

        return $html;
    }

    /** @param  callable(array<int, string>): string  $callback */
    private function replace(string $pattern, callable $callback, string $html): string
    {
        $result = preg_replace_callback($pattern, $callback, $html);
        if ($result === null) {
            throw new \RuntimeException('The page could not be turned into a static copy.');
        }

        return $result;
    }

    /** A stylesheet becomes a <style>, the favicon a data URI; every other <link> goes. */
    private function link(string $tag): string
    {
        $rel = strtolower((string) $this->attribute($tag, 'rel'));
        $href = (string) $this->attribute($tag, 'href');

        if ($rel === 'stylesheet') {
            $file = $this->localFile($href);
            $css = $file !== null && str_ends_with(strtolower($file), '.css') ? $this->css((string) file_get_contents($file)) : '';

            return trim($css) === '' ? '' : '<style>'.$css.'</style>';
        }
        if ($rel === 'icon') {
            $uri = $this->dataUri($href);

            return $uri === null ? '' : '<link rel="icon" href="'.$uri.'">';
        }

        return '';
    }

    private function image(string $tag): string
    {
        $uri = $this->dataUri((string) $this->attribute($tag, 'src'));
        if ($uri === null) {
            return '';
        }

        return $this->replace('#\ssrc="[^"]*"#i', fn () => ' src="'.$uri.'"', $tag);
    }

    /** Web fonts and anything else a stylesheet would fetch are dropped; the font stacks fall back. */
    private function css(string $css): string
    {
        $css = $this->replace('#@font-face\s*\{[^}]*\}#i', fn () => '', $css);
        $css = $this->replace('#@import[^;]*;#i', fn () => '', $css);
        $css = $this->replace('#url\(\s*(?![\'"]?data:)[^)]*\)#i', fn () => 'none', $css);

        return str_ireplace('</style', '<\/style', $css);
    }

    private function dataUri(string $url): ?string
    {
        $file = $this->localFile($url);
        $type = $file === null ? null : (self::IMAGE_TYPES[strtolower(pathinfo($file, PATHINFO_EXTENSION))] ?? null);

        return $type === null ? null : 'data:'.$type.';base64,'.base64_encode((string) file_get_contents((string) $file));
    }

    /**
     * The file behind a URL of this installation: an upload on the public disk
     * or a file under public/. Anything else, or anything too large, is null.
     */
    private function localFile(string $url): ?string
    {
        $plain = strtok($url, '?#');
        if (! is_string($plain)) {
            return null;
        }
        $roots = [
            rtrim(Storage::disk('public')->url(''), '/') => Storage::disk('public')->path(''),
            rtrim(asset(''), '/') => public_path(),
        ];
        foreach ($roots as $base => $root) {
            if (! str_starts_with($plain, $base.'/')) {
                continue;
            }
            $real = realpath($root);
            $file = realpath($root.'/'.rawurldecode(substr($plain, strlen($base) + 1)));
            if ($real !== false && $file !== false && str_starts_with($file, $real.DIRECTORY_SEPARATOR)
                && is_file($file) && filesize($file) <= self::MAX_ASSET_BYTES) {
                return $file;
            }
        }

        return null;
    }

    private function attribute(string $tag, string $name): ?string
    {
        return preg_match('#\s'.$name.'="([^"]*)"#i', $tag, $m) === 1
            ? html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5)
            : null;
    }
}
