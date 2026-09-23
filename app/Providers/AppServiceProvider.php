<?php

namespace App\Providers;

use App\Services\Branding;
use App\Services\DisplayZone;
use App\Services\MailConfig;
use App\Services\PageContext;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(PageContext::class, fn () => new PageContext);
        // Scoped: a personal zone belongs to one request and is flushed with it.
        $this->app->scoped(DisplayZone::class, fn () => new DisplayZone);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Paginator::defaultView('pagination::pharos');
        Paginator::defaultSimpleView('pagination::pharos');
        // Shared with every view: the layout, the child view and the partials all
        // need it, and a child does not inherit variables defined in its layout.
        View::share('branding', $this->app->make(Branding::class));
        // Before anything resolves a mailer: the admin's mail settings sit in
        // the database and have to be in config by the time one is built.
        $this->app->make(MailConfig::class)->apply();
    }
}
