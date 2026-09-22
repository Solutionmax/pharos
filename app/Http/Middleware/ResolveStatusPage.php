<?php

namespace App\Http\Middleware;

use App\Models\StatusPage;
use App\Services\PageContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ResolveStatusPage
{
    public function handle(Request $request, Closure $next)
    {
        $route = $request->route();
        $id = $route->parameter('statusPage');
        $slug = $route->parameter('slug');
        $admin = $request->is('admin/*');
        $api = $request->is('api/*');
        $explicit = $id !== null || $slug !== null;
        $page = $id !== null ? StatusPage::findOrFail($id)
            : ($slug !== null ? StatusPage::where('slug', $slug)->firstOrFail() : StatusPage::default());

        // Custom hosts select public pages only; administration stays central.
        $host = strtolower($request->getHost());
        $central = strtolower((string) parse_url(config('app.url'), PHP_URL_HOST));
        abort_if($api && $host !== $central, 404);
        if (! $admin && ! $api && $host !== $central) {
            $domainPage = StatusPage::where('domain', $host)->firstOrFail();
            abort_if($explicit && $domainPage->id !== $page->id, 404);
            $page = $domainPage;
        }
        if ($admin) {
            if (! $request->user()) {
                return redirect()->guest(route('admin.login'));
            }
            abort_unless($request->user()->canAccessPage($page->id), 404);
            abort_if($host !== $central, 404);
        } else {
            $unsubscribe = str_ends_with((string) $route->getName(), 'unsubscribe')
                || str_contains($route->uri(), 'unsubscribe/');
            $writeApi = $api && ! $request->isMethod('GET');
            abort_if(! $unsubscribe && ! $writeApi && (! $page->is_published || $page->archived_at), 404);
            abort_if($writeApi && $page->archived_at, 404);
        }

        if (! $admin && ! $api && $request->isMethod('GET')
            && in_array($route->getName(), ['status', 'page.status'], true)
            && $page->domain && ($host !== $page->domain || $explicit)) {
            $query = $request->getQueryString();

            return redirect()->away($page->publicUrl().'/'.($query ? '?'.$query : ''));
        }

        // These select a context; they are not controller method arguments.
        $route->forgetParameter('statusPage');
        $route->forgetParameter('slug');
        $request->attributes->set('explicit_status_page', $explicit);

        return app(PageContext::class)->run($page->id, function () use ($page, $request, $next) {
            $response = $next($request);
            if ($response instanceof StreamedResponse && $callback = $response->getCallback()) {
                $response->setCallback(fn () => app(PageContext::class)->run($page->id, $callback));
            }

            return $response;
        });
    }
}
