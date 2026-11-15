<?php

namespace App\Services;

use App\Models\Component;
use App\Models\ComponentGroup;
use App\Models\StatusPage;
use App\Models\User;

/**
 * "Is this page in order?" for the selected page, as a short checklist. The
 * same facts drive the "Get this page ready" steps on the Overview, so the
 * two can never disagree.
 *
 * Every item carries a link only when the given user may open that screen.
 */
class PageHealth
{
    public function __construct(protected MailConfig $mail, protected Branding $branding) {}

    /**
     * @return list<array{key: string, state: string, label: string, detail: string, action: ?string, url: ?string, external?: bool}>
     */
    public function checks(User $user, ?StatusPage $page = null): array
    {
        $page ??= app(PageContext::class)->page();
        $administerPage = $user->canAdministerPage($page->id);
        $global = $user->isAdmin();
        $published = $page->is_published && $page->archived_at === null;

        $components = Component::query()->where('enabled', true)->with('check:id,component_id,enabled')->get(['id', 'source']);
        $automatic = $components->filter(fn (Component $c) => $c->isChecked() || $c->isSetFromOutside())->count();
        $total = $components->count();
        $delivery = $this->delivery();
        $subscriptions = Subscriptions::enabled();
        $subscribeButton = $this->branding->module('page.show_subscribe');
        $edit = $user->canEditPage($page->id);
        $technical = $this->technicalNames();

        return array_values(array_filter([
            [
                'key' => 'published',
                'state' => $published ? 'good' : 'warn',
                'label' => 'Published',
                'detail' => $published ? 'Visitors can open the page' : ($page->archived_at ? 'Archived, visitors see nothing' : 'Draft, visitors see nothing yet'),
                'action' => $published ? 'Open' : ($global ? 'Publish' : null),
                'url' => $published ? $page->publicUrl() : ($global ? route('admin.pages.edit', $page) : null),
                'external' => $published,
            ],
            [
                'key' => 'domain',
                'state' => filled($page->domain) ? 'good' : 'off',
                'label' => 'Custom domain',
                'detail' => filled($page->domain) ? (string) $page->domain : 'Using the central address',
                'action' => $global ? (filled($page->domain) ? 'Change' : 'Set up') : null,
                'url' => $global ? route('admin.pages.edit', $page) : null,
            ],
            [
                'key' => 'delivery',
                'state' => $delivery['ready'] ? 'good' : 'warn',
                'label' => 'Email delivery',
                'detail' => $delivery['detail'],
                'action' => $administerPage ? 'Change' : null,
                'url' => $administerPage ? PageUrls::route('admin.mail.edit') : null,
            ],
            // What the public page needs for its "Get notified" button, and what is missing.
            match (true) {
                ! $subscriptions || ! $subscribeButton => [
                    'key' => 'subscriptions',
                    'state' => 'off',
                    'label' => 'Subscriptions',
                    'detail' => __('Visitors cannot get notified by email: subscribing is switched off.'),
                    'action' => $subscriptions ? ($edit ? 'Change' : null) : 'Manage',
                    'url' => $subscriptions ? ($edit ? PageUrls::route('admin.status-page') : null) : PageUrls::route('admin.subscribers'),
                ],
                ! $delivery['ready'] => [
                    'key' => 'subscriptions',
                    'state' => 'warn',
                    'label' => 'Subscriptions',
                    'detail' => __('Visitors cannot get notified by email: mail is not set up.'),
                    'action' => $administerPage ? 'Set up' : null,
                    'url' => $administerPage ? PageUrls::route('admin.mail.edit') : null,
                ],
                default => [
                    'key' => 'subscriptions',
                    'state' => 'good',
                    'label' => 'Subscriptions',
                    'detail' => 'On for this page',
                    'action' => 'Manage',
                    'url' => PageUrls::route('admin.subscribers'),
                ],
            },
            [
                'key' => 'checks',
                'state' => $total === 0 ? 'off' : ($automatic === $total ? 'good' : 'warn'),
                'label' => 'Automatic checks',
                'detail' => $this->coverage($automatic, $total),
                'action' => $total === 0 ? 'Add components' : ($automatic === $total ? 'Review' : 'Add checks'),
                'url' => PageUrls::route('admin.components'),
            ],
            $technical === [] ? null : [
                'key' => 'names',
                'state' => 'warn',
                'label' => 'Service names',
                'detail' => __('Some service names look technical (:names). Name services the way visitors know them.', ['names' => implode(', ', array_slice(array_keys($technical), 0, 3))]),
                'action' => $edit ? 'Rename' : null,
                'url' => $edit ? PageUrls::route($technical[array_key_first($technical)]) : null,
            ],
            [
                'key' => 'brand',
                'state' => $this->branding->licensed() ? ($this->branding->logoUrl() ? 'good' : 'warn') : 'off',
                'label' => 'Brand pack',
                'detail' => $this->branding->licensed()
                    ? ($this->branding->logoUrl() ? 'Own logo on this page' : 'Licensed, no logo uploaded yet')
                    : 'Pharos logo and footer credit',
                'action' => $administerPage ? 'Details' : null,
                'url' => $administerPage ? PageUrls::route('admin.branding') : null,
            ],
        ]));
    }

    /**
     * Names on the public page that read like the target of a check: a link, a
     * path, a file or a bare host name. Name => the screen where it is renamed.
     *
     * @return array<string, string>
     */
    protected function technicalNames(): array
    {
        $names = PublicComponents::query()->orderBy('position')->pluck('name')->mapWithKeys(fn ($name) => [$name => 'admin.components'])
            ->union(ComponentGroup::where('visible', true)->orderBy('position')->pluck('name')->mapWithKeys(fn ($name) => [$name => 'admin.groups']));

        return $names->filter(fn ($route, $name) => self::looksTechnical((string) $name))->all();
    }

    public static function looksTechnical(string $name): bool
    {
        $name = trim($name);

        return str_contains(strtolower($name), '.php')
            || str_contains($name, '/')
            || str_starts_with(strtolower($name), 'http')
            // pharos-install.php, status.example.com, 192.0.2.10, db01:5432
            || preg_match('/^[\w-]+(\.[\w-]+)+(:\d+)?$|^[\w.-]+:\d+$/u', $name) === 1;
    }

    /**
     * The steps that stand between a new page and a useful one. Empty when
     * the page has components, a way to send mail and is published.
     *
     * @return list<array{label: string, detail: string, done: bool, action: ?string, url: ?string}>
     */
    public function readiness(User $user, ?StatusPage $page = null): array
    {
        $page ??= app(PageContext::class)->page();
        $hasComponents = Component::query()->exists();
        $delivery = $this->delivery()['ready'];
        $published = $page->is_published && $page->archived_at === null;

        if ($hasComponents && $delivery && $published) {
            return [];
        }

        return [
            [
                'label' => 'Add what you want to report on',
                'detail' => 'Create a service and put components in it, with an automatic check where you can.',
                'done' => $hasComponents,
                'action' => $user->canEditPage($page->id) ? 'Add a component' : null,
                'url' => $user->canEditPage($page->id) ? PageUrls::route('admin.components.create') : null,
            ],
            [
                'label' => 'Choose how email goes out',
                'detail' => 'Subscribers get incident mail through the central transport or this page\'s own SMTP server.',
                'done' => $delivery,
                'action' => $user->canAdministerPage($page->id) ? 'Set up delivery' : null,
                'url' => $user->canAdministerPage($page->id) ? PageUrls::route('admin.mail.edit') : null,
            ],
            [
                'label' => 'Publish the page',
                'detail' => __('Until then visitors see nothing at :url.', ['url' => $page->publicUrl()]),
                'done' => $published,
                'action' => $user->isAdmin() ? 'Publish' : null,
                'url' => $user->isAdmin() ? route('admin.pages.edit', $page) : null,
            ],
        ];
    }

    /** @return array{ready: bool, detail: string} */
    protected function delivery(): array
    {
        $effective = $this->mail->effectivePage();
        $custom = $effective['mode'] === 'custom';

        if (! $this->mail->configured()) {
            return ['ready' => false, 'detail' => $custom ? 'Own SMTP server is incomplete' : 'Central mail has no server yet'];
        }
        if (! $custom && in_array($effective['mailer'], ['log', 'array'], true)) {
            return ['ready' => false, 'detail' => 'Central mail only writes to the log'];
        }

        return ['ready' => true, 'detail' => __($custom ? 'Own SMTP server' : 'Central transport').($effective['from'] !== '' ? __(', from :address', ['address' => $effective['from']]) : '')];
    }

    protected function coverage(int $automatic, int $total): string
    {
        if ($total === 0) {
            return 'No components yet';
        }
        if ($automatic === $total) {
            return __('All :count components are watched automatically', ['count' => $total]);
        }
        $manual = $total - $automatic;

        return __($manual === 1 ? ':automatic of :total components; :manual relies on someone noticing' : ':automatic of :total components; :manual rely on someone noticing', ['automatic' => $automatic, 'total' => $total, 'manual' => $manual]);
    }
}
