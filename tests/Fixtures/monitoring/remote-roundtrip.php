<?php

use App\Models\BackupDestination;
use App\Services\BackupArchive;
use App\Services\RemoteBackup;
use Illuminate\Contracts\Console\Kernel;

// Controlled acceptance runner: pass destination JSON in a private temporary file.
require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$configuration = json_decode(file_get_contents($argv[1]), true, 16, JSON_THROW_ON_ERROR);
$destination = BackupDestination::create($configuration);
try {
    $backup = app(RemoteBackup::class);
    $filesystem = $backup->filesystem($destination);
    $filesystem->write('transport-proof.txt', 'verified-round-trip');
    if ($filesystem->read('transport-proof.txt') !== 'verified-round-trip') {
        throw new RuntimeException('Transport read differs');
    }$filesystem->delete('transport-proof.txt');
    $archive = app(BackupArchive::class)->create();
    $stream = fopen($archive, 'rb');
    $filesystem->writeStream(basename($archive), $stream, ['visibility' => 'private']);
    fclose($stream);
    $download = $filesystem->readStream(basename($archive));
    $hash = hash_init('sha256');
    hash_update_stream($hash, $download);
    fclose($download);
    if (hash_file('sha256', $archive) !== hash_final($hash)) {
        throw new RuntimeException('Archive checksum differs');
    }unlink($archive);
    if (! $backup->run($destination)) {
        throw new RuntimeException($destination->fresh()->last_error);
    }echo $destination->driver." actual archive upload/download checksum verified\n";
} finally {
    $destination->delete();
}
