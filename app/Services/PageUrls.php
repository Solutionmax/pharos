<?php

namespace App\Services;

use App\Models\StatusPage;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;

/** Central account URLs stay central; page links retain their explicit owner. */
class PageUrls
{
    public static function route(string $name, mixed $parameters = [], bool $absolute = true): string
    {
        [$name, $parameters, $domain] = self::resolve($name, $parameters);
        if ($domain !== null) {
            $path = route($name, $parameters, false);

            return $absolute ? 'https://'.$domain.$path : $path;
        }

        $url = route($name, $parameters, $absolute);
        if (! $absolute) {
            return $url;
        }
        $base = parse_url(config('app.url'));
        $origin = ($base['scheme'] ?? 'https').'://'.$base['host'].(isset($base['port']) ? ':'.$base['port'] : '');
        $path = parse_url($url, PHP_URL_PATH) ?? '';
        $basePath = rtrim($base['path'] ?? '', '/');
        if ($basePath !== '' && $path !== $basePath && ! str_starts_with($path, $basePath.'/')) {
            $path = $basePath.$path;
        }
        $query = parse_url($url, PHP_URL_QUERY);

        return $origin.$path.($query !== null ? '?'.$query : '');
    }

    public static function signedRoute(string $name, array $parameters = [], ?DateTimeInterface $expiration = null): string
    {
        // Credential links outlive custom domains. Keep them on the installation
        // URL and immutable page slug so old unsubscribe links remain usable.
        $page = app(PageContext::class)->page();
        if ($page->id !== StatusPage::defaultId()) {
            $name = 'page.'.$name;
            $parameters = ['slug' => $page->slug] + $parameters;
        }
        $relative = URL::signedRoute($name, $parameters, $expiration, absolute: false);

        return rtrim(config('app.url'), '/').$relative;
    }

    public static function api(string $path = ''): string
    {
        $page = app(PageContext::class)->page();
        $prefix = $page->id === StatusPage::default()->id
            ? '/api/v1' : '/api/v1/pages/'.rawurlencode($page->slug);

        return rtrim(config('app.url'), '/').$prefix.($path !== '' ? '/'.ltrim($path, '/') : '');
    }

    public static function landing(User $user): string
    {
        if ($user->canAccessPage(StatusPage::default()->id)) {
            return route('admin.components');
        }
        $page = $user->statusPages()->whereNull('archived_at')->first();

        return $page ? route('page.admin.components', ['statusPage' => $page->id]) : route('admin.no-pages');
    }

    private static function resolve(string $name, mixed $parameters): array
    {
        if (! Route::has('page.'.$name)) {
            return [$name, $parameters, null];
        }

        $page = app(PageContext::class)->page();
        $isPublic = ! str_starts_with($name, 'admin.');
        if ($isPublic && $page->domain) {
            return [$name, $parameters, $page->domain];
        }

        $explicit = request()->attributes->get('explicit_status_page', false);
        if ($page->id === StatusPage::default()->id && ! $explicit) {
            return [$name, $parameters, null];
        }

        $parameters = is_array($parameters) ? $parameters : [$parameters];
        $key = $isPublic ? 'slug' : 'statusPage';
        $value = $isPublic ? $page->slug : $page->id;

        return ['page.'.$name, [$key => $value] + $parameters, null];
    }
}
