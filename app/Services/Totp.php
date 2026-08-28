<?php

namespace App\Services;

/**
 * RFC 6238 time-based one-time passwords, by hand.
 *
 * A package would be a package a customer on cPanel hosting has to install, and
 * this is thirty lines of hashing. No QR image either: rendering one needs a
 * library, and the online generators would receive the secret.
 */
class Totp
{
    public const DIGITS = 6;

    public const PERIOD = 30;

    /** One step either side, so a slow phone clock still works. */
    public const DRIFT = 1;

    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function secret(int $bytes = 20): string
    {
        return $this->base32Encode(random_bytes($bytes));
    }

    /** What the authenticator app reads: otpauth://totp/Issuer:you@example.net?... */
    public function uri(string $secret, string $account, string $issuer): string
    {
        return 'otpauth://totp/'.rawurlencode($issuer).':'.rawurlencode($account).'?'.http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => self::DIGITS,
            'period' => self::PERIOD,
        ]);
    }

    /**
     * Returns the time step the code belongs to, or null when it does not match.
     *
     * The step is the caller's replay guard: a code stays valid for half a minute,
     * so whoever read it over your shoulder could otherwise use it a second time.
     */
    public function verify(string $secret, string $code, ?int $after = null, ?int $now = null): ?int
    {
        $code = preg_replace('/\D/', '', $code) ?? '';

        if (strlen($code) !== self::DIGITS) {
            return null;
        }

        $current = intdiv($now ?? time(), self::PERIOD);

        for ($step = $current - self::DRIFT; $step <= $current + self::DRIFT; $step++) {
            if ($after !== null && $step <= $after) {
                continue;
            }

            if (hash_equals($this->at($secret, $step), $code)) {
                return $step;
            }
        }

        return null;
    }

    public function at(string $secret, int $step): string
    {
        $hash = hash_hmac('sha1', pack('J', $step), $this->base32Decode($secret), true);

        // Dynamic truncation: the low nibble of the last byte picks the offset.
        $offset = ord($hash[19]) & 0xF;
        $number = unpack('N', substr($hash, $offset, 4))[1] & 0x7FFFFFFF;

        return str_pad((string) ($number % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    public function base32Encode(string $raw): string
    {
        $bits = '';

        foreach (str_split($raw) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }

        $out = '';

        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }

        return $out;
    }

    public function base32Decode(string $secret): string
    {
        $bits = '';

        foreach (str_split(strtoupper(rtrim($secret, '='))) as $char) {
            $index = strpos(self::ALPHABET, $char);

            if ($index === false) {
                continue;
            }

            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }

        $out = '';

        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $out .= chr(bindec($chunk));
            }
        }

        return $out;
    }
}
