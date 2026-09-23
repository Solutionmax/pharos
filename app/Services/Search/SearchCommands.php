<?php

namespace App\Services\Search;

use App\Models\StatusPage;
use App\Models\User;
use App\Services\PageContext;
use App\Services\PageStatus;
use App\Services\PageUrls;
use App\Support\AdminMenu;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;

/**
 * The palette's commands: quick actions ("Report an incident") and admin
 * screens reached by name. Screens come straight from AdminMenu, so the
 * palette offers exactly what the sidebar offers and nothing that would
 * answer with a 403. Actions check the same page roles as the routes they
 * open.
 */
class SearchCommands
{
    /** How many "Switch to" rows the empty palette lists. */
    public const MAX_SWITCHES = 8;

    /** Extra words a screen answers to, by its menu label. */
    public const KEYWORDS = [
        'Overview' => 'dashboard home health uptime',
        'Incidents' => 'outage problem report',
        'Maintenance' => 'planned window downtime schedule',
        'Services' => 'groups',
        'Components' => 'checks monitors probes',
        'Subscribers' => 'email notify notifications subscriptions',
        'Layout' => 'status page appearance design look',
        'Branding' => 'logo colours colors theme brand',
        'Delivery' => 'smtp mail email server sending transport',
        'Templates' => 'mail email templates messages',
        'Send out' => 'webhooks notifications outgoing slack discord teams',
        'Bring in' => 'webhook incoming inbound monitor kuma',
        'API tokens' => 'tokens keys api',
        'Delivery log' => 'webhook log deliveries history',
        'Integrations' => 'webhooks api tokens',
        'Status pages' => 'pages sites',
        'Users' => 'people accounts team invite roles',
        'General' => 'settings name time zone installation',
        'Central mail' => 'smtp mail email server',
        'Single sign on' => 'sso oidc oauth login',
        'Audit log' => 'history activity changes',
        'Updates' => 'upgrade version backup release',
        'Your profile' => 'account password two factor sessions theme',
    ];

    /**
     * What the empty palette offers under "Jump to".
     *
     * @param  Collection<int, StatusPage>  $openPages  pages the user may open, not archived
     * @return list<array<string, mixed>>
     */
    public function actions(User $user, ?StatusPage $current, Collection $openPages): array
    {
        $rows = array_map(fn (array $row) => array_diff_key($row, ['keywords' => true]), $this->pageActions($user, $current));
        $states = PageStatus::worstByPage();
        $others = $openPages->reject(fn (StatusPage $p) => $p->id === $current?->id)->take(self::MAX_SWITCHES);
        foreach ($others as $page) {
            $state = $states[$page->id] ?? null;
            $rows[] = SearchRow::make('Action', 'Switch to '.$page->name, route('page.admin.overview', ['statusPage' => $page->id]), [
                'icon' => 'pages',
                'page' => SearchRow::page($page),
                'status' => SearchRow::status(PageStatus::label($state), $state?->tone() ?? 'off'),
                'hint' => 'Switch',
            ]);
        }

        return $rows;
    }

    /**
     * Screens and actions whose name or keywords hold every word of the term.
     * Switching pages is left to the Pages group, which already lists them.
     *
     * @return list<array<string, mixed>>
     */
    public function matching(User $user, ?StatusPage $current, string $term, int $limit): array
    {
        $words = preg_split('/\s+/u', mb_strtolower($term), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $hits = fn (array $row) => $this->hasEvery($words, $row['label'].' '.$row['keywords']);
        $strip = fn (array $row) => array_diff_key($row, ['keywords' => true]);

        $screens = array_slice(array_filter($this->screens($user, $current), $hits), 0, $limit);
        $actions = array_slice(array_filter($this->pageActions($user, $current), $hits), 0, $limit);

        return array_map($strip, [...$screens, ...$actions]);
    }

    /** @return list<array<string, mixed>> the create actions of the current page and, for administrators, inviting */
    protected function pageActions(User $user, ?StatusPage $current): array
    {
        $rows = [];
        if ($current && $user->canEditPage($current->id)) {
            $rows = app(PageContext::class)->run($current->id, function () use ($current) {
                $extra = ['page' => SearchRow::page($current), 'context' => $current->name, 'current' => true];
                $make = fn (string $label, string $route, string $icon, string $keywords) => Route::has($route)
                    ? SearchRow::make('Action', $label, PageUrls::route($route), $extra + ['icon' => $icon, 'keywords' => $keywords, 'hint' => 'Create'])
                    : null;

                return array_values(array_filter([
                    $make('Report an incident', 'admin.incidents.create', 'incidents', 'new incident outage down create'),
                    $make('Schedule maintenance', 'admin.maintenance.create', 'maintenance', 'new planned window downtime create'),
                    $make('Add a component', 'admin.components.create', 'components', 'new component check monitor create'),
                ]));
            });
        }
        if ($user->isAdmin()) {
            // #user-add opens the "Add someone" dialog on the Users screen.
            $rows[] = SearchRow::make('Action', 'Invite someone', route('admin.users').'#user-add', [
                'icon' => 'users', 'context' => 'Users', 'keywords' => 'users add user person account invite team', 'hint' => 'Invite',
            ]);
        }

        return array_map(fn (array $row) => $row + ['keywords' => ''], $rows);
    }

    /** @return list<array<string, mixed>> every screen in the sidebar for this user, with its keywords */
    protected function screens(User $user, ?StatusPage $current): array
    {
        $menu = $current
            ? app(PageContext::class)->run($current->id, fn () => AdminMenu::build($user, request()))
            : AdminMenu::build($user, request());

        $rows = [];
        foreach (['page' => $menu['page'], 'installation' => $menu['installation']] as $scope => $items) {
            foreach ($items as $item) {
                $leaves = $item['children'] ?: [$item];
                foreach ($leaves as $leaf) {
                    $parent = $item['children'] ? $item['label'] : null;
                    $extra = $scope === 'page' && $current
                        ? ['page' => SearchRow::page($current), 'context' => $parent ?? 'This page', 'current' => true]
                        : ['context' => $parent ?? 'Installation'];
                    $rows[] = SearchRow::make('Screen', $leaf['label'], $leaf['url'], $extra + [
                        'hint' => 'Go to',
                        'keywords' => trim(($parent ?? '').' '.(self::KEYWORDS[$leaf['label']] ?? '')),
                    ]);
                }
            }
        }
        $rows[] = SearchRow::make('Screen', 'Your profile', route('admin.profile'), [
            'context' => 'Account', 'hint' => 'Go to', 'keywords' => self::KEYWORDS['Your profile'],
        ]);

        return $rows;
    }

    /** @param list<string> $words */
    protected function hasEvery(array $words, string $haystack): bool
    {
        $haystack = mb_strtolower($haystack);
        foreach ($words as $word) {
            if (! str_contains($haystack, $word)) {
                return false;
            }
        }

        return $words !== [];
    }
}
