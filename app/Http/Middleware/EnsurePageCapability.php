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
        $read = in_array($name, ['admin.components', 'admin.groups', 'admin.incidents', 'admin.subscribers', 'admin.integrations', 'admin.status-page.preview'], true);
        if ($admin) {
            abort_unless($request->user()->canAdministerPage($pageId), 403);
        } elseif (! $read) {
            abort_unless($request->user()->canEditPage($pageId), 403);
        }

        return $next($request);
    }
}
