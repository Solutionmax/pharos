<?php

use App\Http\Controllers\Api\MetricsController;
use App\Http\Controllers\PublicFeatures\PublicationController;
use App\Http\Controllers\PublicFeatures\ReportController;
use App\Http\Controllers\SubscribeController;
use App\Http\Middleware\ApiTokenAuth;
use App\Http\Middleware\NoStore;
use App\Http\Middleware\UsePersonalTimezone;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\Route;

Route::get('badges/{kind}/{id}.svg', [PublicationController::class, 'badge'])->whereIn('kind', ['components', 'groups'])->whereNumber('id')->name('public.badge');
Route::get('feed.xml', [PublicationController::class, 'feed'])->name('public.feed');
Route::get('incidents/{incident}', [PublicationController::class, 'incident'])->whereNumber('incident')->name('public.incident');
Route::get('widget.json', [PublicationController::class, 'widgetData'])->name('public.widget');
Route::get('embed.js', [PublicationController::class, 'widgetScript'])->name('public.embed');

Route::get('subscribe/preferences/{subscriber}', [SubscribeController::class, 'preferences'])->middleware(['signed:relative', 'throttle:30,1'])->name('subscribe.preferences');
Route::post('subscribe/preferences/{subscriber}', [SubscribeController::class, 'updatePreferences'])->middleware(['signed:relative', 'throttle:10,1'])->name('subscribe.preferences.update');

foreach (['' => 'public.reports', '.csv' => 'public.reports.csv', '.pdf' => 'public.reports.pdf'] as $extension => $name) {
    Route::get('reports'.$extension, [ReportController::class, 'show'])->middleware('throttle:15,1,reports')->name($name);
}
Route::prefix('admin')->name('admin.')->middleware(['auth', AuthenticateSession::class, UsePersonalTimezone::class, NoStore::class])->group(function () {
    foreach (['' => 'reports', '.csv' => 'reports.csv', '.pdf' => 'reports.pdf'] as $extension => $name) {
        Route::get('reports'.$extension, [ReportController::class, 'show'])->middleware('throttle:15,1,reports')->name($name);
    }
});

Route::get('metrics', MetricsController::class)->middleware([ApiTokenAuth::class, 'throttle:60,1,metrics'])->name('api.features.metrics.web');
