<?php

namespace App\Support;

use App\Models\User;
use App\Services\PageContext;
use App\Services\PageUrls;
use App\Services\Subscriptions;
use App\Services\Updater;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * The sidebar as data: two groups ("This page" and "Installation"), each a list
 * of entries that are either a link or an expandable group of links.
 *
 * An entry exists only when the signed in user may open it. The same rules the
 * middleware enforces decide visibility here, so the menu never offers a screen
 * that answers with a 403. A group with no visible children disappears, and a
 * group left with a single child collapses into a plain link.
 *
 * Routes another branch adds (Maintenance, the split Integrations screens) are
 * guarded with Route::has(), so the menu works with or without them.
 */
class AdminMenu
{
    /**
     * @return array{page: list<array<string, mixed>>, installation: list<array<string, mixed>>}
     */
    public static function build(User $user, Request $request): array
    {
        $pageId = app(PageContext::class)->id();
        $current = preg_replace('/^page\./', '', (string) $request->route()?->getName());
        $settingsTab = (string) ($request->query('tab') ?? $request->old('_tab') ?? 'general');

        return [
            'page' => $user->canAccessPage($pageId) ? self::pageItems($user, $pageId, $current) : [],
            'installation' => $user->isAdmin() ? self::installationItems($current, $settingsTab) : [],
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function pageItems(User $user, int $pageId, string $current): array
    {
        $edit = $user->canEditPage($pageId);
        $administer = $user->canAdministerPage($pageId);

        $integrations = array_values(array_filter([
            self::pageLink('Send out', 'admin.integrations.out', $current, ['admin.integrations.out*', 'admin.integrations']),
            self::pageLink('Bring in', 'admin.integrations.in', $current, ['admin.integrations.in', 'admin.integrations.in.*']),
            $administer ? self::pageLink('API tokens', 'admin.integrations.tokens', $current, ['admin.integrations.tokens', 'admin.integrations.tokens.*']) : null,
            self::pageLink('Delivery log', 'admin.integrations.log', $current, ['admin.integrations.log*']),
        ]));
        if ($integrations === []) {
            // Until the split screens exist, one Integrations screen covers all four.
            $integrations = [self::pageLink('Integrations', 'admin.integrations', $current, ['admin.integrations*'])];
        }

        return array_values(array_filter([
            self::pageLink('Overview', 'admin.overview', $current, ['admin.overview'], 'overview'),
            self::group('incidents', 'Incidents', 'incidents', [
                self::pageLink('Incidents', 'admin.incidents', $current, ['admin.incidents*']),
                self::pageLink('Maintenance', 'admin.maintenance', $current, ['admin.maintenance*']),
            ]),
            self::group('services', 'Services', 'services', [
                self::pageLink('Services', 'admin.groups', $current, ['admin.groups*']),
                self::pageLink('Components', 'admin.components', $current, ['admin.components*']),
            ]),
            self::pageLink('Subscribers', 'admin.subscribers', $current, ['admin.subscribers*'], 'subscribers',
                Subscriptions::enabled() ? null : 'off'),
            self::group('appearance', 'Appearance', 'sliders', [
                $edit ? self::pageLink('Layout', 'admin.status-page', $current, ['admin.status-page*']) : null,
                $administer ? self::pageLink('Branding', 'admin.branding', $current, ['admin.branding*']) : null,
            ]),
            self::group('email', 'Email', 'mail', [
                $administer ? self::pageLink('Delivery', 'admin.mail.edit', $current, ['admin.mail.*']) : null,
                $administer ? self::pageLink('Templates', 'admin.mail-templates', $current, ['admin.mail-templates*']) : null,
            ]),
            self::group('integrations', 'Integrations & API', 'integrations', $integrations),
        ]));
    }

    /** @return list<array<string, mixed>> */
    private static function installationItems(string $current, string $settingsTab): array
    {
        $onSettings = Str::is('admin.settings*', $current);
        $settings = [];
        foreach (['general' => 'General', 'mail' => 'Central mail', 'sso' => 'Single sign on'] as $tab => $label) {
            $settings[] = [
                'label' => $label,
                'url' => route('admin.settings', ['tab' => $tab]),
                'active' => $onSettings && ($settingsTab === $tab || ($tab === 'general' && ! in_array($settingsTab, ['mail', 'sso'], true))),
            ];
        }

        return array_values(array_filter([
            self::link('Status pages', route('admin.pages.index'), Str::is('admin.pages.*', $current), 'pages'),
            self::link('Users', route('admin.users'), Str::is(['admin.users', 'admin.users.*'], $current), 'users'),
            self::group('settings', 'Settings', 'settings', $settings),
            self::link('Audit log', route('admin.audit'), Str::is('admin.audit*', $current), 'audit'),
            self::link('Updates', route('admin.updates'), Str::is('admin.updates*', $current), 'update',
                dot: app(Updater::class)->updateAvailable() ? 'Update available' : null),
        ]));
    }

    /** @param list<string> $patterns */
    private static function pageLink(string $label, string $route, string $current, array $patterns, ?string $icon = null, ?string $hint = null): ?array
    {
        if (! Route::has($route)) {
            return null;
        }

        return self::link($label, PageUrls::route($route), Str::is($patterns, $current), $icon, $hint);
    }

    private static function link(string $label, string $url, bool $active, ?string $icon = null, ?string $hint = null, ?string $dot = null): array
    {
        return compact('label', 'url', 'active', 'icon', 'hint', 'dot') + ['children' => []];
    }

    /** @param list<array<string, mixed>|null> $children */
    private static function group(string $key, string $label, string $icon, array $children): ?array
    {
        $children = array_values(array_filter($children));
        if ($children === []) {
            return null;
        }
        if (count($children) === 1) {
            // A group of one is a detour: show the only screen under the group's own name and icon.
            return ['label' => $label, 'icon' => $icon] + $children[0];
        }
        $active = collect($children)->contains('active', true);

        return [
            'key' => $key,
            'label' => $label,
            'icon' => $icon,
            'url' => $children[0]['url'],
            'active' => $active,
            'hint' => null,
            'dot' => null,
            'children' => $children,
        ];
    }
}
