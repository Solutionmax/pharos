<?php

use App\Models\Check;
use App\Services\Probe;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
foreach (['A' => '192.0.2.55', 'AAAA' => '2001:db8::55', 'CNAME' => 'fixture.example.test.', 'MX' => '10 mail.fixture.example.test.', 'TXT' => 'hello world'] as $type => $expected) {
    $check = new Check(['type' => 'dns', 'target' => $type === 'CNAME' ? 'alias.fixture.example.test' : 'fixture.example.test', 'dns_type' => $type, 'dns_expected' => $expected]);
    $result = app(Probe::class)->run($check);
    if (! $result->ok) {
        throw new RuntimeException($type.' fixture failed: '.$result->message);
    }echo "$type DNS fixture matches\n";
}
