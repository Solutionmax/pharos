<?php

namespace App\Services;

/** Accept complete SHA256/SHA512 fingerprints, never a false-like verification switch. */
class SftpFingerprint
{
    public static function valid(mixed $value): bool
    {
        if (! is_string($value) || strlen($value) > 200 || ! preg_match('/^(?:(sha256|sha512):)?(.+)$/iD', $value, $parts)) {
            return false;
        }
        $algorithm = strtolower($parts[1]);
        $digest = $parts[2];
        $expected = $algorithm === 'sha256' ? 32 : ($algorithm === 'sha512' ? 64 : null);
        $hex = str_replace(':', '', $digest);
        if ((ctype_xdigit($digest) || preg_match('/^(?:[a-f0-9]{2}:)+[a-f0-9]{2}$/iD', $digest)) && in_array(strlen($hex), [64, 128], true)) {
            return $expected === null || strlen($hex) === $expected * 2;
        }
        if (! preg_match('/^[A-Za-z0-9+\/]+={0,2}$/D', $digest)) {
            return false;
        }
        $decoded = base64_decode($digest, true);

        return $decoded !== false && in_array(strlen($decoded), [32, 64], true) && ($expected === null || strlen($decoded) === $expected) && rtrim(base64_encode($decoded), '=') === rtrim($digest, '=');
    }
}
