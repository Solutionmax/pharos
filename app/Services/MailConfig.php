<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Mail settings from the admin, laid over the MAIL_* lines in .env.
 *
 * The database wins wherever it has a value; anything left empty on the form
 * falls through to .env, so an install that was configured by file keeps
 * working untouched. Applied once per request from AppServiceProvider::boot(),
 * before anything resolves a mailer.
 */
class MailConfig
{
    /** Form field => setting key. The password is separate: it is stored encrypted. */
    public const FIELDS = [
        'mailer' => 'mail.mailer',
        'host' => 'mail.host',
        'port' => 'mail.port',
        'encryption' => 'mail.encryption',
        'username' => 'mail.username',
        'from_address' => 'mail.from_address',
        'from_name' => 'mail.from_name',
    ];

    public const PASSWORD_KEY = 'mail.password';

    public const MAILERS = ['smtp', 'sendmail', 'log'];

    /** none = plain (STARTTLS still taken when offered); tls = STARTTLS on 587; ssl = implicit TLS on 465. */
    public const ENCRYPTIONS = ['none', 'tls', 'ssl'];

    public function apply(): void
    {
        try {
            $stored = $this->stored();
            $password = $this->password();
        } catch (\Throwable) {
            // No database yet (installer, fresh clone, `migrate` on an empty
            // schema, a test without the table): .env is all there is.
            return;
        }

        if ($stored['mailer'] !== '') {
            config(['mail.default' => $stored['mailer']]);
        }

        foreach (['host', 'username'] as $field) {
            if ($stored[$field] !== '') {
                config(["mail.mailers.smtp.{$field}" => $stored[$field]]);
            }
        }

        if ($stored['port'] !== '') {
            config(['mail.mailers.smtp.port' => (int) $stored['port']]);
        }

        // Laravel picks the transport from the scheme: smtps is implicit TLS,
        // smtp is plain with STARTTLS whenever the server offers it.
        if ($stored['encryption'] !== '') {
            config(['mail.mailers.smtp.scheme' => $stored['encryption'] === 'ssl' ? 'smtps' : 'smtp']);
        }

        if ($password !== null) {
            config(['mail.mailers.smtp.password' => $password]);
        }

        if ($stored['from_address'] !== '') {
            config(['mail.from.address' => $stored['from_address']]);
        }

        if ($stored['from_name'] !== '') {
            config(['mail.from.name' => $stored['from_name']]);
        }
    }

    /**
     * What the form shows: every stored value as a string, empty when unset.
     *
     * @return array<string, string>
     */
    public function stored(): array
    {
        $values = [];

        foreach (self::FIELDS as $field => $key) {
            $values[$field] = trim((string) Setting::get($key, ''));
        }

        return $values;
    }

    /** The plaintext, or null when none is stored or the app key changed since. */
    public function password(): ?string
    {
        $stored = Setting::get(self::PASSWORD_KEY);

        if (! is_string($stored) || $stored === '') {
            return null;
        }

        try {
            return Crypt::decryptString($stored);
        } catch (DecryptException) {
            // A rotated APP_KEY: the value is unreadable, not wrong. Falling back
            // to .env beats failing every boot; the form asks for it again.
            return null;
        }
    }

    public function hasPassword(): bool
    {
        return filled(Setting::get(self::PASSWORD_KEY));
    }

    /**
     * @param  array<string, mixed>  $data  validated form input
     */
    public function save(array $data): void
    {
        foreach (self::FIELDS as $field => $key) {
            Setting::put($key, trim((string) ($data[$field] ?? '')));
        }

        // Empty means "keep": the field is never rendered back, so a plain
        // re-save must not wipe it.
        if (filled($data['password'] ?? null)) {
            Setting::put(self::PASSWORD_KEY, Crypt::encryptString($data['password']));
        }

        // Setting::put dropped the cache keys; this request must see them too.
        $this->apply();
    }

    /**
     * What a mail would go out with right now, database and .env combined.
     * No password: this is shown on a screen.
     *
     * @return array<string, string>
     */
    public function effective(): array
    {
        $mailer = (string) config('mail.default');
        $transport = config("mail.mailers.{$mailer}", []);

        return [
            'mailer' => $mailer,
            'host' => (string) ($transport['host'] ?? ''),
            'port' => (string) ($transport['port'] ?? ''),
            'from' => (string) config('mail.from.address'),
            'from_name' => (string) (config('mail.from.name') ?: app(Branding::class)->name()),
        ];
    }
}
