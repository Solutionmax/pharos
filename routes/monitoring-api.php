<?php

use App\Http\Controllers\Api\ProbeController;
use App\Http\Controllers\Api\WebCronController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/probe')->middleware('throttle:120,1')->group(function () {
    Route::get('jobs', [ProbeController::class, 'jobs']);
    Route::post('results', [ProbeController::class, 'results']);
});

Route::post('cron', WebCronController::class)->middleware('throttle:2,1');
