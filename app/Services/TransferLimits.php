<?php

namespace App\Services;

use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\ResponseInterface;

/** Enforce byte and wall-clock bounds while cURL receives bytes, including chunked bodies. */
class TransferLimits
{
    public static function options(int $maxBytes, int $seconds, ?float $deadline = null): array
    {
        $deadline = min($deadline ?? INF, microtime(true) + $seconds);
        $remaining = $deadline - microtime(true);
        if ($remaining <= 0) {
            throw new \RuntimeException('Remote transfer budget expired.');
        }
        $guard = function (float $downloaded = 0) use ($maxBytes, $deadline): void {
            if ($downloaded > $maxBytes) {
                throw new \RuntimeException('Remote response exceeds verification size.');
            }if (microtime(true) >= $deadline) {
                throw new \RuntimeException('Remote transfer budget expired.');
            }
        };

        $stream = Utils::streamFor(fopen('php://temp', 'w+b'));
        $bytes = 0;
        $sink = FnStream::decorate($stream, ['write' => function (string $chunk) use ($stream, &$bytes, $maxBytes, $deadline): int {
            // Returning zero makes cURL abort immediately; throwing from its progress
            // callback can defer the exception until the entire body was buffered.
            if ($bytes + strlen($chunk) > $maxBytes || microtime(true) >= $deadline) {
                return 0;
            }
            $bytes += strlen($chunk);

            return $stream->write($chunk);
        }]);

        return ['sink' => $sink, 'timeout' => $remaining, 'connect_timeout' => min(10, $remaining), 'decode_content' => false, 'on_headers' => function (ResponseInterface $response) use ($guard): void {
            $guard((float) $response->getHeaderLine('Content-Length'));
        }];
    }
}
