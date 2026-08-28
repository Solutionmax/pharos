<?php

namespace App\Services;

/**
 * Where each "Good to know" note lives, so the profile can say what was hidden
 * and where. A note the registry does not know (an older build, a plugin)
 * still lists under "Elsewhere".
 */
class Notes
{
    /** @var array<string, array{page: string, route: string, params: array<string, string>, title: string}> */
    public const REGISTRY = [
        'audit.record' => ['page' => 'Audit log', 'route' => 'admin.audit', 'params' => [], 'title' => 'This page is the record, not a backup'],
        'branding.activated' => ['page' => 'Branding', 'route' => 'admin.branding', 'params' => [], 'title' => 'What the Brand pack unlocks'],
        'component.heartbeat-url' => ['page' => 'Components', 'route' => 'admin.components', 'params' => [], 'title' => 'How a heartbeat check is pinged'],
        'integrations.no-heartbeats' => ['page' => 'Integrations', 'route' => 'admin.integrations', 'params' => [], 'title' => 'No heartbeats yet'],
        'integrations.one-attempt' => ['page' => 'Integrations', 'route' => 'admin.integrations', 'params' => [], 'title' => 'A webhook gets one attempt'],
        'mail-templates.frame' => ['page' => 'Mail templates', 'route' => 'admin.mail-templates', 'params' => [], 'title' => 'You edit the body, not the frame'],
        'profile.recovery-codes' => ['page' => 'Your profile', 'route' => 'admin.profile', 'params' => [], 'title' => 'Recovery codes'],
        'profile.two-factor' => ['page' => 'Your profile', 'route' => 'admin.profile', 'params' => [], 'title' => 'Two-factor authentication'],
        'settings.mail-env' => ['page' => 'Settings › Mail', 'route' => 'admin.settings', 'params' => ['tab' => 'mail'], 'title' => 'Mail settings and .env'],
        'sso.no-provisioning' => ['page' => 'Settings › Single sign-on', 'route' => 'admin.settings', 'params' => ['tab' => 'sso'], 'title' => 'People sign in, they do not appear'],
        'sso.what-still-applies' => ['page' => 'Settings › Single sign-on', 'route' => 'admin.settings', 'params' => ['tab' => 'sso'], 'title' => 'What still applies with SSO'],
        'status-page.no-services' => ['page' => 'Status page', 'route' => 'admin.status-page', 'params' => [], 'title' => 'No services yet'],
        'subscribers.how' => ['page' => 'Subscribers', 'route' => 'admin.subscribers', 'params' => [], 'title' => 'How subscriptions work'],
        'updates.backups' => ['page' => 'Updates', 'route' => 'admin.updates', 'params' => [], 'title' => 'What a backup holds'],
        'updates.how-it-installs' => ['page' => 'Updates', 'route' => 'admin.updates', 'params' => [], 'title' => 'How an update installs'],
        'updates.managed' => ['page' => 'Updates', 'route' => 'admin.updates', 'params' => [], 'title' => 'This install is managed from outside'],
        'updates.not-writable' => ['page' => 'Updates', 'route' => 'admin.updates', 'params' => [], 'title' => 'The directory is not writable'],
        'updates.safe' => ['page' => 'Updates', 'route' => 'admin.updates', 'params' => [], 'title' => 'Why an update is safe to take'],
    ];

    /**
     * The user's hidden notes, grouped by page in registry order.
     *
     * @return array<string, array{url: ?string, notes: list<array{id: string, title: string}>}>
     */
    public static function hiddenByPage(array $ids): array
    {
        $groups = [];
        foreach ($ids as $id) {
            $meta = self::REGISTRY[$id] ?? ['page' => 'Elsewhere', 'route' => null, 'params' => [], 'title' => $id];
            $groups[$meta['page']] ??= ['url' => $meta['route'] ? route($meta['route'], $meta['params']) : null, 'notes' => []];
            $groups[$meta['page']]['notes'][] = ['id' => $id, 'title' => $meta['title']];
        }
        ksort($groups);

        return $groups;
    }
}
