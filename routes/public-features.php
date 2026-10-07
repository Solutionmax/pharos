<?php

use App\Http\Controllers\PublicFeatures\PublicationController;
use App\Http\Controllers\SubscribeController;
use Illuminate\Support\Facades\Route;

Route::get('badges/{kind}/{id}.svg', [PublicationController::class, 'badge'])->whereIn('kind', ['components', 'groups'])->whereNumber('id')->name('public.badge');
Route::get('feed.xml', [PublicationController::class, 'feed'])->name('public.feed');
Route::get('incidents/{incident}', [PublicationController::class, 'incident'])->whereNumber('incident')->name('public.incident');
Route::get('widget.json', [PublicationController::class, 'widgetData'])->name('public.widget');
Route::get('embed.js', [PublicationController::class, 'widgetScript'])->name('public.embed');

Route::get('subscribe/preferences/{subscriber}', [SubscribeController::class, 'preferences'])->middleware(['signed:relative', 'throttle:30,1'])->name('subscribe.preferences');
Route::post('subscribe/preferences/{subscriber}', [SubscribeController::class, 'updatePreferences'])->middleware(['signed:relative', 'throttle:10,1'])->name('subscribe.preferences.update');
