<?php

namespace App\Services;

use App\Models\Component;
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

        $components = Component::query()->where('enabled', true)->get(['id', 'source']);
        $automatic = $components->where('source', '!=', 'manual')->count();
        $total = $components->count();
        $delivery = $this->delivery();
        $subscriptions = Subscriptions::enabled();

        return [
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
            [
                'key' => 'subscriptions',
                'state' => $subscriptions ? 'good' : 'off',
                'label' => 'Subscriptions',
                'detail' => $subscriptions ? 'On for this page' : 'Off, visitors cannot subscribe',
                'action' => 'Manage',
                'url' => PageUrls::route('admin.subscribers'),
            ],
            [
                'key' => 'checks',
                'state' => $total === 0 ? 'off' : ($automatic === $total ? 'good' : 'warn'),
                'label' => 'Automatic checks',
                'detail' => $this->coverage($automatic, $total),
                'action' => $total === 0 ? 'Add components' : ($automatic === $total ? 'Review' : 'Add checks'),
                'url' => PageUrls::route('admin.components'),
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
        ];
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
                'detail' => 'Until then visitors see nothing at '.$page->publicUrl().'.',
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

        return ['ready' => true, 'detail' => ($custom ? 'Own SMTP server' : 'Central transport').($effective['from'] !== '' ? ', from '.$effective['from'] : '')];
    }

    protected function coverage(int $automatic, int $total): string
    {
        if ($total === 0) {
            return 'No components yet';
        }
        if ($automatic === $total) {
            return 'All '.$total.' components are watched automatically';
        }
        $manual = $total - $automatic;

        return $automatic.' of '.$total.' components; '.$manual.' '.($manual === 1 ? 'relies' : 'rely').' on someone noticing';
    }
}
