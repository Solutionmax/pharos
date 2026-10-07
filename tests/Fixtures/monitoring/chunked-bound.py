from http.server import HTTPServer, BaseHTTPRequestHandler
import threading, subprocess, tempfile, json, time, os
from pathlib import Path

# Native cURL regression: refuse chunked excess before the server sends its full body.
project_root = Path(__file__).resolve().parents[3]

sent = {'bytes': 0}
class Handler(BaseHTTPRequestHandler):
    def log_message(self, *args):
        pass
    def do_GET(self):
        self.send_response(200)
        self.send_header('Transfer-Encoding', 'chunked')
        self.end_headers()
        try:
            for i in range(256):
                self.wfile.write(b'1000\r\n' + b'x' * 4096 + b'\r\n')
                self.wfile.flush()
                sent['bytes'] += 4096
                time.sleep(.005)
            self.wfile.write(b'0\r\n\r\n')
        except (BrokenPipeError, ConnectionResetError):
            pass

server = HTTPServer(('127.0.0.1', 0), Handler)
threading.Thread(target=server.serve_forever, daemon=True).start()
script = '''<?php
require '{project_root}/vendor/autoload.php';
$opts = App\\Services\\TransferLimits::options(16384, 5);
$started = microtime(true);
try {
    (new GuzzleHttp\\Client(['handler'=>new GuzzleHttp\\Handler\\CurlHandler]))->get($argv[1], $opts);
    echo json_encode(['unexpected_success'=>true]);
    exit(1);
} catch (Throwable $e) {
    echo json_encode(['exception'=>$e->getMessage(), 'elapsed_ms'=>round((microtime(true)-$started)*1000)]);
}
'''
with tempfile.NamedTemporaryFile(mode='w', suffix='.php', delete=False) as f:
    f.write(script.replace('{project_root}', str(project_root)))
    name = f.name
try:
    result = subprocess.run(['php', name, 'http://127.0.0.1:'+str(server.server_port)+'/chunked'], capture_output=True, text=True)
    print(result.stdout)
    if result.stderr:
        print(result.stderr)
    print(json.dumps({'response_size':1048576, 'guard_limit':16384, 'server_sent_before_rejection':sent['bytes']}))
    # A small socket-buffer overshoot is acceptable; reading the full response is not.
    if result.returncode or sent['bytes'] >= 131072:
        raise SystemExit(1)
finally:
    os.unlink(name)
    server.shutdown()
