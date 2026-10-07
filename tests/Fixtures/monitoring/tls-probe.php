<?php

use App\Models\Check;
use App\Services\Probe;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$target = $argv[1];
$check = new Check(['type' => 'http', 'target' => $target.'/up', 'expected_keyword' => 'ready']);
$result = app(Probe::class)->run($check);
if (! $result->ok || ! $result->degraded || ! $result->tlsExpiresAt) {
    throw new RuntimeException('TLS expiry/keyword fixture failed: '.$result->message);
}echo 'Verified TLS expiry '.$result->tlsExpiresAt->format(DATE_ATOM)."; 2-day certificate degrades\n";
$check->expected_keyword = 'missing';
if (app(Probe::class)->run($check)->ok) {
    throw new RuntimeException('Missing keyword passed');
}$check->target = $target.'/redirect';
$check->expected_keyword = null;
$redirect = app(Probe::class)->run($check);
if (! $redirect->ok) {
    throw new RuntimeException('Redirect followed or not accepted');
}echo "Missing keyword fails; redirect remains at vetted target\n";
