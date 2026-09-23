<?php

use App\Http\Controllers\Api\ComponentController;
use App\Http\Controllers\Api\HeartbeatController;
use App\Http\Controllers\Api\IncidentController;
use App\Http\Controllers\Api\KumaController;
use App\Http\Middleware\ApiTokenAuth;
use App\Http\Middleware\ResolveStatusPage;
use Illuminate\Support\Facades\Route;

// Path and shape match Cachet 2.x on purpose: existing scripts keep working.
Route::prefix('v1')->group(function () {
    Route::get('components', [ComponentController::class, 'index']);
    Route::get('components/{component}', [ComponentController::class, 'show']);
    Route::get('incidents', [IncidentController::class, 'index']);
    // Per IP, with its own bucket: a job pings once a minute at most, but many
    // jobs behind one NAT add up, and they must not use up the write budget.
    Route::post('heartbeat/{token}', [HeartbeatController::class, 'ping'])->middleware('throttle:120,1,heartbeat');

    Route::middleware([ApiTokenAuth::class, 'throttle:60,1,api-write'])->group(function () {
        Route::post('integrations/kuma/{component}', KumaController::class);
        // The first address the guide gave out; Kuma notifications saved with it keep working.
        Route::post('kuma/components/{component}', KumaController::class);
        Route::put('components/{component}', [ComponentController::class, 'update']);
        Route::post('components/{component}', [ComponentController::class, 'update']); // Cachet 2.x used POST
        Route::post('incidents', [IncidentController::class, 'store']);
        Route::post('incidents/{incident}/updates', [IncidentController::class, 'addUpdate']);
    });
});

foreach (Route::getRoutes()->getRoutes() as $original) {
    if (! str_starts_with($original->uri(), 'api/v1/')) {
        continue;
    }
    $original->middleware(ResolveStatusPage::class);
    $action = $original->getAction();
    $copy = clone $original;
    $copy->setUri('api/v1/pages/{slug}/'.substr($original->uri(), strlen('api/v1/')));
    $copy->setAction($action);
    Route::getRoutes()->add($copy);
}
