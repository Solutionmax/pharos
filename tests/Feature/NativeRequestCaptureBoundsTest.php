<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Exercise actual public/index.php through PHP HTTP, not a preconstructed Request. */
class NativeRequestCaptureBoundsTest extends TestCase
{
    use RefreshDatabase;

    public function test_native_front_controller_rejects_chunked_excess_before_json_capture(): void
    {
        $directory = sys_get_temp_dir().'/pharos-capture-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $marker = $directory.'/parsed';
        $database = $directory.'/database.sqlite';
        touch($database);
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $address = stream_socket_get_name($server, false);
        fclose($server);
        $env = ['APP_ENV' => 'testing', 'APP_URL' => 'http://'.$address, 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database, 'DB_URL' => '', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'MAIL_MAILER' => 'array', 'PHAROS_PARSE_MARKER' => $marker];
        $migrate = new Process(['php', 'artisan', 'migrate', '--force'], base_path(), $env);
        $migrate->mustRun();
        $source = file_get_contents(base_path('public/index.php'));
        $source = str_replace(['__DIR__', 'use Illuminate\\Http\\Request;'], [var_export(base_path('public'), true), 'use CaptureSpyRequest as Request;'], $source);
        $prefix = '<?php require '.var_export(base_path('vendor/autoload.php'), true).'; class CaptureSpyRequest extends Illuminate\\Http\\Request { public function json($key = null, $default = null) { file_put_contents(getenv("PHAROS_PARSE_MARKER"), "called"); return parent::json($key, $default); } } ?>';
        file_put_contents($directory.'/router.php', $prefix.$source);
        $process = new Process(['php', '-S', $address, $directory.'/router.php'], base_path(), $env);
        $process->start();
        try {
            for ($attempt = 0; $attempt < 100; $attempt++) {
                if ($ready = @stream_socket_client('tcp://'.$address)) {
                    fclose($ready);
                    break;
                }
                usleep(20000);
            }
            $this->assertTrue($process->isRunning());
            foreach (['/api/v1/groups' => 262144, '/api/v1/groups/' => 262144, '/api/v1/pages/other/groups' => 262144, '/api/v1/%67roups' => 262144, '/api/v1/components/1' => 262144, '/api/v1/incidents/1' => 262144, '/api/v1/probe/results' => 16384, '/api/v1/%70robe/results/' => 16384] as $path => $limit) {
                foreach ([$limit, $limit + 1] as $bytes) {
                    @unlink($marker);
                    $data = str_ends_with($path, '/1') ? ['padding' => '', '_method' => 'DELETE'] : ['padding' => ''];
                    $data['padding'] = str_repeat('x', $bytes - strlen(json_encode($data, JSON_THROW_ON_ERROR)));
                    $body = json_encode($data, JSON_THROW_ON_ERROR);
                    $this->assertSame($bytes, strlen($body));
                    $socket = stream_socket_client('tcp://'.$address, timeout: 5);
                    stream_set_timeout($socket, 5);
                    $wire = "POST $path HTTP/1.1\r\nHost: $address\r\nContent-Type: application/json\r\nTransfer-Encoding: chunked\r\nConnection: close\r\n\r\n".dechex(strlen($body))."\r\n".$body."\r\n0\r\n\r\n";
                    while ($wire !== '') {
                        $written = fwrite($socket, $wire);
                        $this->assertGreaterThan(0, $written);
                        $wire = substr($wire, $written);
                    }
                    $response = stream_get_contents($socket);
                    fclose($socket);
                    $this->assertSame(1, preg_match('/^HTTP\/1\.[01] (\d+)/', $response, $match), 'Unexpected native HTTP status line');
                    if ($bytes > $limit) {
                        $this->assertSame(413, (int) $match[1], $path);
                        $this->assertFileDoesNotExist($marker, 'Native capture parsed excess JSON: '.$path);
                        $this->assertStringContainsString('Request body is too large.', $response);
                    } else {
                        $this->assertNotSame(413, (int) $match[1], $path);
                        $this->assertFileExists($marker, 'Accepted JSON must reach native capture');
                    }
                }
            }
        } finally {
            $process->stop();
            foreach (glob($directory.'/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }
}
