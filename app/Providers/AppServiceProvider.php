<?php

namespace App\Providers;

use App\Services\Branding;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        \Illuminate\Pagination\Paginator::defaultView('pagination::pharos');
        \Illuminate\Pagination\Paginator::defaultSimpleView('pagination::pharos');
        // Shared with every view: the layout, the child view and the partials all
        // need it, and a child does not inherit variables defined in its layout.
        View::share('branding', $this->app->make(Branding::class));
    }
}
