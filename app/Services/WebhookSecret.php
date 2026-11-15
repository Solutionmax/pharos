<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The page's secret for signing Generic JSON deliveries. Stored encrypted with
 * APP_KEY (it was plain text before 1.2.0), shown masked in the admin until an
 * administrator asks to see it.
 *
 * Two signatures go out with every delivery. X-Pharos-Signature is the HMAC of
 * the body alone, unchanged, so receivers built for 1.1 keep working.
 * X-Pharos-Signature-V2 signs "<X-Pharos-Timestamp>.<body>", so a receiver can
 * refuse an old request that is sent again.
 */
class WebhookSecret
{
    public const KEY = 'integrations.webhook_secret';

    /** The current page's secret, or null when there is none or it cannot be read. */
    public function get(): ?string
    {
        $stored = Setting::get(self::KEY);

        if (! is_string($stored) || $stored === '') {
            return null;
        }

        try {
            return Crypt::decryptString($stored);
        } catch (DecryptException) {
            if (self::isEncryptedPayload($stored)) {
                // Encrypted with another APP_KEY: unreadable, not wrong.
                return null;
            }

            // Plain text from before 1.2.0. The migration encrypts these; a value
            // still in the cache is read this once and stored encrypted.
            Setting::put(self::KEY, Crypt::encryptString($stored));

            return $stored;
        }
    }

    /** The secret, made now when the page has none (or only an unreadable one). */
    public function ensure(): string
    {
        $secret = $this->get();

        if ($secret !== null) {
            return $secret;
        }

        if (filled(Setting::get(self::KEY))) {
            Log::warning('Webhook signing secret could not be decrypted; a new one was made.', ['page' => app(PageContext::class)->id()]);
        }

        return $this->rotate();
    }

    public function rotate(): string
    {
        $secret = Str::random(32);
        Setting::put(self::KEY, Crypt::encryptString($secret));

        return $secret;
    }

    /** Enough to recognise it, not enough to use it. */
    public static function mask(string $secret): string
    {
        return str_repeat('•', 12).substr($secret, -4);
    }

    /**
     * The headers for one delivery.
     *
     * @return array{X-Pharos-Signature: string, X-Pharos-Timestamp: string, X-Pharos-Signature-V2: string}
     */
    public static function headers(string $body, string $secret, int $timestamp): array
    {
        return [
            'X-Pharos-Signature' => hash_hmac('sha256', $body, $secret),
            'X-Pharos-Timestamp' => (string) $timestamp,
            'X-Pharos-Signature-V2' => hash_hmac('sha256', $timestamp.'.'.$body, $secret),
        ];
    }

    /** What Crypt::encryptString() makes: base64 of a JSON object with iv, value and mac. */
    public static function isEncryptedPayload(string $value): bool
    {
        $decoded = json_decode((string) base64_decode($value, true), true);

        return is_array($decoded) && isset($decoded['iv'], $decoded['value']);
    }
}
