<?php

use App\Services\RequestBodyLimits;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request as RawRequest;

define('LARAVEL_START', microtime(true));

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// Native Illuminate capture decodes JSON before Kernel middleware. Keep the
// raw Symfony request bounded first, including requests without Content-Length.
Request::enableHttpMethodParameterOverride();
$request = RawRequest::createFromGlobals();
if (RequestBodyLimits::tooLarge($request)) {
    (new JsonResponse(['message' => 'Request body is too large.'], 413))->send();
    exit;
}

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::createFromBase($request));
