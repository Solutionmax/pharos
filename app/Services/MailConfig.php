<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\StatusPageSetting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

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

    public const PAGE_FIELDS = [
        'mode' => 'mail.mode',
        'host' => 'mail.host',
        'port' => 'mail.port',
        'encryption' => 'mail.encryption',
        'username' => 'mail.username',
        'from_address' => 'mail.from_address',
        'from_name' => 'mail.from_name',
        'reply_to' => 'mail.reply_to',
    ];

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
    /** Can a mail leave this install at all? SMTP without a host cannot; every other transport can. */
    public function configured(): bool
    {
        $page = $this->storedPage();
        if (($page['mode'] ?: 'central') === 'custom') {
            if ($this->pageHasPassword()) {
                try {
                    $this->pagePassword();
                } catch (RuntimeException) {
                    return false;
                }
            }

            return $page['host'] !== ''
                && (int) $page['port'] > 0
                && $this->sender()['address'] !== '';
        }

        $effective = $this->effective();

        return $effective['mailer'] !== 'smtp' || $effective['host'] !== '';
    }

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

    /** @param array<string, mixed> $data */
    public function savePage(array $data): void
    {
        $pageId = app(PageContext::class)->id();
        foreach (self::PAGE_FIELDS as $field => $key) {
            StatusPageSetting::updateOrCreate(
                ['status_page_id' => $pageId, 'key' => $key],
                ['value' => trim((string) ($data[$field] ?? ''))],
            );
        }

        if (filled($data['password'] ?? null)) {
            StatusPageSetting::updateOrCreate(
                ['status_page_id' => $pageId, 'key' => self::PASSWORD_KEY],
                ['value' => Crypt::encryptString((string) $data['password'])],
            );
        }
    }

    /** @return array<string, string> */
    public function storedPage(): array
    {
        $values = [];
        foreach (self::PAGE_FIELDS as $field => $key) {
            $values[$field] = trim((string) $this->pageValue($key, ''));
        }

        return $values;
    }

    public function pageHasPassword(): bool
    {
        return filled($this->pageValue(self::PASSWORD_KEY));
    }

    /** @return array<string, string> */
    public function effectivePage(): array
    {
        $stored = $this->storedPage();
        $sender = $this->sender();

        if (($stored['mode'] ?: 'central') === 'custom') {
            return [
                'mode' => 'custom',
                'mailer' => 'smtp',
                'host' => $stored['host'],
                'port' => $stored['port'],
                'from' => $sender['address'],
                'from_name' => $sender['name'],
                'reply_to' => $sender['reply_to'],
            ];
        }

        return array_merge($this->effective(), [
            'mode' => 'central',
            'from' => $sender['address'],
            'from_name' => $sender['name'],
            'reply_to' => $sender['reply_to'],
        ]);
    }

    /** @return array{address: string, name: string, reply_to: string} */
    public function sender(): array
    {
        $stored = $this->storedPage();

        return [
            'address' => $stored['from_address'] ?: (string) config('mail.from.address'),
            'name' => $stored['from_name'] ?: ((string) config('mail.from.name') ?: app(Branding::class)->name()),
            'reply_to' => $stored['reply_to'],
        ];
    }

    /**
     * Mail is for someone else, so it is rendered in the installation zone even
     * when an admin with a personal zone triggers it.
     */
    public function sendTo(string $address, Mailable $mail): void
    {
        Clock::withInstallationZone(fn () => $this->deliver($address, $mail));
    }

    protected function deliver(string $address, Mailable $mail): void
    {
        if (($this->storedPage()['mode'] ?: 'central') === 'central') {
            Mail::to($address)->send($mail);

            return;
        }

        $mailer = 'pharos_page_'.app(PageContext::class)->id();
        $stored = $this->storedPage();
        config(["mail.mailers.$mailer" => [
            'transport' => 'smtp',
            'scheme' => $stored['encryption'] === 'ssl' ? 'smtps' : 'smtp',
            'host' => $stored['host'],
            'port' => (int) $stored['port'],
            'username' => $stored['username'] ?: null,
            'password' => $this->pagePassword(),
            'timeout' => 10,
            'local_domain' => parse_url((string) config('app.url'), PHP_URL_HOST),
        ]]);

        Mail::purge($mailer);
        try {
            Mail::mailer($mailer)->to($address)->send($mail);
        } finally {
            Mail::purge($mailer);
        }
    }

    public function pagePassword(): ?string
    {
        $encrypted = $this->pageValue(self::PASSWORD_KEY);
        if (! is_string($encrypted) || $encrypted === '') {
            return null;
        }

        try {
            return Crypt::decryptString($encrypted);
        } catch (DecryptException) {
            throw new RuntimeException('Stored page SMTP password cannot be decrypted.');
        }
    }

    private function pageValue(string $key, mixed $default = null): mixed
    {
        return StatusPageSetting::query()
            ->where('status_page_id', app(PageContext::class)->id())
            ->where('key', $key)
            ->value('value') ?? $default;
    }
}
