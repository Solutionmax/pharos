<?php

use App\Http\Controllers\Api\ExtendedController;
use App\Http\Controllers\Api\MetricsController;
use App\Http\Middleware\ApiTokenAuth;
use App\Http\Middleware\SubscriberApiTokenAuth;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('ping', [ExtendedController::class, 'ping']);
    Route::name('api.features.')->middleware('throttle:60,1,api-features')->group(function () {
        Route::middleware(ApiTokenAuth::class)->group(function () {
            Route::get('metrics', MetricsController::class)->name('metrics');
            Route::get('groups', [ExtendedController::class, 'groups'])->name('groups');
            Route::get('groups/{group}', [ExtendedController::class, 'group'])->name('group');
            Route::post('groups', [ExtendedController::class, 'storeGroup']);
            Route::put('groups/{group}', [ExtendedController::class, 'updateGroup']);
            Route::delete('groups/{group}', [ExtendedController::class, 'deleteGroup']);
            Route::post('components', [ExtendedController::class, 'storeComponent']);
            Route::delete('components/{component}', [ExtendedController::class, 'deleteComponent']);
            Route::delete('incidents/{incident}', [ExtendedController::class, 'deleteIncident']);
            Route::get('maintenance', [ExtendedController::class, 'maintenanceIndex'])->name('maintenance');
            Route::get('maintenance/{maintenance}', [ExtendedController::class, 'maintenance'])->name('maintenance.show');
            Route::post('maintenance', [ExtendedController::class, 'storeMaintenance']);
            Route::put('maintenance/{maintenance}', [ExtendedController::class, 'updateMaintenance']);
            Route::delete('maintenance/{maintenance}', [ExtendedController::class, 'deleteMaintenance']);
        });
        Route::middleware(SubscriberApiTokenAuth::class)->group(function () {
            Route::get('subscribers', [ExtendedController::class, 'subscribers'])->name('subscribers');
            Route::get('subscribers/{subscriber}', [ExtendedController::class, 'subscriber'])->name('subscriber');
            Route::post('subscribers', [ExtendedController::class, 'storeSubscriber']);
            Route::put('subscribers/{subscriber}', [ExtendedController::class, 'updateSubscriber']);
            Route::delete('subscribers/{subscriber}', [ExtendedController::class, 'deleteSubscriber']);
        });
    });
});
