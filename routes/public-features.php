<?php

use App\Http\Controllers\Api\MetricsController;
use App\Http\Controllers\PublicFeatures\CachetImportController;
use App\Http\Controllers\PublicFeatures\PublicationController;
use App\Http\Controllers\PublicFeatures\ReportController;
use App\Http\Controllers\PublicFeatures\SecurityTxtController;
use App\Http\Controllers\SubscribeController;
use App\Http\Middleware\ApiTokenAuth;
use App\Http\Middleware\NoStore;
use App\Http\Middleware\UsePersonalTimezone;
use App\Support\StatelessRoutes;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\Route;

// Read by machines and other sites: no session, no cookie (see StatelessRoutes).
Route::withoutMiddleware(StatelessRoutes::MIDDLEWARE)->group(function () {
    Route::get('badges/{kind}/{id}.svg', [PublicationController::class, 'badge'])->whereIn('kind', ['components', 'groups'])->whereNumber('id')->name('public.badge');
    Route::get('feed.xml', [PublicationController::class, 'feed'])->name('public.feed');
    Route::get('widget.json', [PublicationController::class, 'widgetData'])->name('public.widget');
    Route::get('embed.js', [PublicationController::class, 'widgetScript'])->name('public.embed');
    // One file for the whole installation, on every domain it answers to.
    Route::get('.well-known/security.txt', SecurityTxtController::class)->name('security.txt');
});
Route::get('incidents/{incident}', [PublicationController::class, 'incident'])->whereNumber('incident')->name('public.incident');

Route::get('subscribe/preferences/{subscriber}', [SubscribeController::class, 'preferences'])->middleware(['signed:relative', 'throttle:30,1'])->name('subscribe.preferences');
Route::post('subscribe/preferences/{subscriber}', [SubscribeController::class, 'updatePreferences'])->middleware(['signed:relative', 'throttle:10,1'])->name('subscribe.preferences.update');

foreach (['' => 'public.reports', '.csv' => 'public.reports.csv', '.pdf' => 'public.reports.pdf'] as $extension => $name) {
    $route = Route::get('reports'.$extension, [ReportController::class, 'show'])->middleware('throttle:15,1,reports')->name($name);
    // The downloads are files; only the page with its month picker is a page.
    if ($extension !== '') {
        $route->withoutMiddleware(StatelessRoutes::MIDDLEWARE);
    }
}
Route::prefix('admin')->name('admin.')->middleware(['auth', AuthenticateSession::class, UsePersonalTimezone::class, NoStore::class])->group(function () {
    foreach (['' => 'reports', '.csv' => 'reports.csv', '.pdf' => 'reports.pdf'] as $extension => $name) {
        Route::get('reports'.$extension, [ReportController::class, 'show'])->middleware('throttle:15,1,reports')->name($name);
    }
});

Route::get('metrics', MetricsController::class)->middleware([ApiTokenAuth::class, 'throttle:60,1,metrics'])->withoutMiddleware(StatelessRoutes::MIDDLEWARE)->name('api.features.metrics.web');

Route::prefix('admin/integrations')->name('admin.integrations.')->middleware(['auth', AuthenticateSession::class, UsePersonalTimezone::class, NoStore::class])->group(function () {
    Route::get('cachet', [CachetImportController::class, 'show'])->name('cachet');
    Route::post('cachet/preview', [CachetImportController::class, 'preview'])->middleware('throttle:5,1,cachet-import')->name('cachet.preview');
    Route::post('cachet/apply', [CachetImportController::class, 'apply'])->middleware('throttle:5,1,cachet-import')->name('cachet.apply');
});
