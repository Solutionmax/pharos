<?php

namespace App\Http\Middleware;

use App\Services\PageContext;
use Closure;
use Illuminate\Http\Request;

class EnsurePageCapability
{
    public function handle(Request $request, Closure $next)
    {
        $name = preg_replace('/^page\./', '', (string) $request->route()->getName());
        $pageId = app(PageContext::class)->id();
        $admin = preg_match('/^admin\.(branding|mail|mail-templates)(\.|$)/', $name)
            || in_array($name, ['admin.integrations.tokens.store', 'admin.integrations.tokens.destroy', 'admin.integrations.webhook.rotate'], true);
        $read = in_array($name, [
            'admin.overview', 'admin.components', 'admin.groups', 'admin.incidents', 'admin.subscribers', 'admin.status-page.preview', 'admin.maintenance',
            'admin.integrations', 'admin.integrations.out', 'admin.integrations.in', 'admin.integrations.tokens', 'admin.integrations.log',
        ], true);
        if ($admin) {
            abort_unless($request->user()->canAdministerPage($pageId), 403);
        } elseif (! $read) {
            abort_unless($request->user()->canEditPage($pageId), 403);
        }

        return $next($request);
    }
}
