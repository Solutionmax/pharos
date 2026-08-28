<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Mail\TestMail;
use App\Models\AuditEntry;
use App\Models\Setting;
use App\Models\User;
use App\Services\MailConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Settings → Mail: what the install sends with, the form that sets it, and the button that proves it. */
class MailSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->admin = User::create([
            'name' => 'Raymon', 'email' => 'raymon@example.net',
            'password' => Hash::make('correct-horse-battery'), 'role' => UserRole::Admin,
        ]);
    }

    /** A complete, valid SMTP form; tests override what they are about. */
    protected function form(array $overrides = []): array
    {
        return array_merge([
            'mailer' => 'smtp',
            'host' => 'smtp.example.net',
            'port' => 587,
            'encryption' => 'tls',
            'username' => 'status@example.net',
            'password' => 'hunter2-secret',
            'from_address' => 'status@example.net',
            'from_name' => 'Acme Status',
        ], $overrides);
    }

    protected function save(array $overrides = [])
    {
        return $this->actingAs($this->admin)->put('/admin/settings/mail', $this->form($overrides));
    }

    public function test_the_panel_shows_the_effective_mailer_without_secrets(): void
    {
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'smtp.example.net',
            'mail.mailers.smtp.port' => 587,
            'mail.mailers.smtp.password' => 'hunter2-secret',
            'mail.from.address' => 'status@example.net',
            'mail.from.name' => null,
        ]);

        $this->actingAs($this->admin)->get('/admin/settings?tab=mail')->assertOk()
            ->assertSee('Mail')
            ->assertSee('smtp.example.net')
            ->assertSee('status@example.net')
            ->assertSee('Send test e-mail')
            ->assertSee('MAIL_*')
            ->assertDontSee('hunter2-secret');
    }

    public function test_the_from_name_falls_back_to_the_brand(): void
    {
        config(['mail.from.name' => null]);
        Setting::put('brand.name', 'Acme Cloud');

        $this->actingAs($this->admin)->get('/admin/settings?tab=mail')->assertOk()
            ->assertSee('Acme Cloud &lt;', false);
    }

    public function test_the_test_mail_goes_to_the_signed_in_admin(): void
    {
        $this->actingAs($this->admin)->post('/admin/settings/mail-test')
            ->assertRedirect('/admin/settings?tab=mail')
            ->assertSessionHas('status', 'Test e-mail sent to raymon@example.net.');

        Mail::assertSent(TestMail::class, fn (TestMail $m) => $m->hasTo('raymon@example.net')
            && str_contains($m->render(), 'Mail works'));

        $this->assertSame(1, AuditEntry::where('action', 'mail.test')->count());
    }

    public function test_a_transport_failure_is_shown_not_hidden(): void
    {
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('Connection refused [smtp.example.net:587]'));

        $this->actingAs($this->admin)->post('/admin/settings/mail-test')
            ->assertRedirect('/admin/settings?tab=mail')
            ->assertSessionHasErrors(['mail' => 'Test e-mail failed: Connection refused [smtp.example.net:587]']);

        $this->assertSame(0, AuditEntry::where('action', 'mail.test')->count());
    }

    public function test_the_test_mail_is_for_administrators_only(): void
    {
        $member = User::create([
            'name' => 'Tom', 'email' => 'tom@example.net',
            'password' => Hash::make('correct-horse-battery'), 'role' => UserRole::User,
        ]);

        $this->actingAs($member)->post('/admin/settings/mail-test')->assertForbidden();
        Mail::assertNothingSent();
    }

    // ---------- the form ----------

    public function test_saving_stores_the_settings_and_encrypts_the_password(): void
    {
        $this->save()
            ->assertRedirect('/admin/settings?tab=mail')
            ->assertSessionHas('status', 'Mail settings saved.');

        $this->assertSame('smtp', Setting::get('mail.mailer'));
        $this->assertSame('smtp.example.net', Setting::get('mail.host'));
        $this->assertSame('587', (string) Setting::get('mail.port'));
        $this->assertSame('tls', Setting::get('mail.encryption'));
        $this->assertSame('status@example.net', Setting::get('mail.username'));
        $this->assertSame('status@example.net', Setting::get('mail.from_address'));
        $this->assertSame('Acme Status', Setting::get('mail.from_name'));

        // At rest the password is ciphertext; only the app key turns it back.
        $stored = Setting::get('mail.password');
        $this->assertNotSame('hunter2-secret', $stored);
        $this->assertSame('hunter2-secret', Crypt::decryptString($stored));
        $this->assertSame('hunter2-secret', app(MailConfig::class)->password());
    }

    public function test_the_password_is_never_rendered_back(): void
    {
        $this->save();

        $this->actingAs($this->admin)->get('/admin/settings?tab=mail')->assertOk()
            ->assertSee('Stored — leave empty to keep')
            ->assertSee('smtp.example.net')
            ->assertDontSee('hunter2-secret')
            ->assertDontSee(Setting::get('mail.password'));
    }

    public function test_an_empty_password_keeps_the_stored_one(): void
    {
        $this->save();
        $this->save(['password' => '', 'host' => 'mail.other.net']);

        $this->assertSame('mail.other.net', Setting::get('mail.host'));
        $this->assertSame('hunter2-secret', app(MailConfig::class)->password());
    }

    public function test_the_audit_row_carries_no_password(): void
    {
        $this->save();

        $entry = AuditEntry::where('action', 'mail.settings_saved')->firstOrFail();
        $json = json_encode($entry->changes);

        $this->assertStringContainsString('smtp.example.net', $json);
        $this->assertStringNotContainsString('hunter2-secret', $json);
        $this->assertStringNotContainsString(Setting::get('mail.password'), $json);

        // The per-row setting audit redacts it too: the key says "password".
        foreach (AuditEntry::where('subject_label', 'mail.password')->get() as $row) {
            $this->assertStringNotContainsString('hunter2-secret', json_encode($row->changes));
        }
    }

    public function test_smtp_needs_a_host_and_a_port(): void
    {
        $this->actingAs($this->admin)->putJson('/admin/settings/mail', $this->form(['host' => '', 'port' => '']))
            ->assertStatus(422)->assertJsonValidationErrors(['host', 'port']);

        // "log" needs neither: nothing is connected to.
        $this->actingAs($this->admin)->put('/admin/settings/mail', $this->form(['mailer' => 'log', 'host' => '', 'port' => '']))
            ->assertRedirect('/admin/settings?tab=mail');

        $this->assertSame('log', Setting::get('mail.mailer'));
    }

    public function test_the_port_the_mailer_and_the_from_address_are_validated(): void
    {
        $bad = [
            ['port' => 0], ['port' => 65536], ['port' => 'abc'],
            ['mailer' => 'ses'],
            ['encryption' => 'rot13'],
            ['from_address' => 'not-an-address'],
        ];

        foreach ($bad as $case) {
            $this->actingAs($this->admin)->putJson('/admin/settings/mail', $this->form($case))
                ->assertStatus(422)->assertJsonValidationErrors(array_keys($case));
        }

        $this->assertNull(Setting::get('mail.mailer'));
    }

    public function test_the_form_is_for_administrators_only(): void
    {
        $member = User::create([
            'name' => 'Tom', 'email' => 'tom@example.net',
            'password' => Hash::make('correct-horse-battery'), 'role' => UserRole::User,
        ]);

        $this->actingAs($member)->put('/admin/settings/mail', $this->form())->assertForbidden();
        $this->assertNull(Setting::get('mail.host'));
    }

    // ---------- the runtime overlay ----------

    public function test_stored_settings_overlay_the_config(): void
    {
        config([
            'mail.default' => 'log',
            'mail.mailers.smtp.host' => 'env.example.net',
            'mail.mailers.smtp.port' => 2525,
            'mail.mailers.smtp.scheme' => null,
        ]);

        $this->save();

        // The provider does this at boot; the service is what it calls.
        app(MailConfig::class)->apply();

        $this->assertSame('smtp', config('mail.default'));
        $this->assertSame('smtp.example.net', config('mail.mailers.smtp.host'));
        $this->assertSame(587, config('mail.mailers.smtp.port'));
        $this->assertSame('smtp', config('mail.mailers.smtp.scheme'));
        $this->assertSame('status@example.net', config('mail.mailers.smtp.username'));
        $this->assertSame('hunter2-secret', config('mail.mailers.smtp.password'));
        $this->assertSame('status@example.net', config('mail.from.address'));
        $this->assertSame('Acme Status', config('mail.from.name'));
    }

    public function test_ssl_selects_the_smtps_scheme(): void
    {
        $this->save(['encryption' => 'ssl', 'port' => 465]);
        app(MailConfig::class)->apply();

        $this->assertSame('smtps', config('mail.mailers.smtp.scheme'));
    }

    public function test_env_stays_the_fallback_for_anything_left_empty(): void
    {
        config([
            'mail.mailers.smtp.username' => 'env-user',
            'mail.mailers.smtp.password' => 'env-pass',
            'mail.from.address' => 'env@example.net',
            'mail.from.name' => 'Env Name',
        ]);

        $this->save(['username' => '', 'password' => '', 'from_address' => '', 'from_name' => '']);
        app(MailConfig::class)->apply();

        $this->assertSame('smtp.example.net', config('mail.mailers.smtp.host'));
        $this->assertSame('env-user', config('mail.mailers.smtp.username'));
        $this->assertSame('env-pass', config('mail.mailers.smtp.password'));
        $this->assertSame('env@example.net', config('mail.from.address'));
        $this->assertSame('Env Name', config('mail.from.name'));
    }

    public function test_the_default_mailer_follows_the_stored_one(): void
    {
        config(['mail.default' => 'smtp']);

        $this->save(['mailer' => 'log']);
        app(MailConfig::class)->apply();

        $this->assertSame('log', config('mail.default'));
    }

    public function test_the_overlay_is_harmless_without_a_settings_table(): void
    {
        // The installer, a fresh clone and `php artisan migrate` all boot before
        // the table exists; the overlay must not be the thing that breaks them.
        config(['mail.default' => 'log']);
        Schema::drop('settings');

        app(MailConfig::class)->apply();

        $this->assertSame('log', config('mail.default'));
    }

    public function test_saving_does_not_serve_the_old_value_from_cache(): void
    {
        $this->save(['host' => 'first.example.net']);
        $this->assertSame('first.example.net', Setting::get('mail.host'));

        $this->save(['host' => 'second.example.net']);
        $this->assertSame('second.example.net', Setting::get('mail.host'));
        $this->assertSame('second.example.net', config('mail.mailers.smtp.host'));
    }

    public function test_the_effective_line_shows_what_wins(): void
    {
        config(['mail.default' => 'log', 'mail.mailers.smtp.host' => 'env.example.net']);

        $this->save();

        $this->actingAs($this->admin)->get('/admin/settings?tab=mail')->assertOk()
            ->assertSee('smtp via smtp.example.net:587 as Acme Status &lt;status@example.net&gt;', false)
            ->assertDontSee('env.example.net');
    }

    public function test_the_test_mail_uses_the_saved_settings(): void
    {
        // Real mailer for this one: the fake would accept anything.
        Mail::clearResolvedInstance('mail.manager');
        $this->app->forgetInstance('mail.manager');

        // Nothing listens on port 1, so the transport's own error names the
        // host and port it was given — which is the proof.
        $this->save(['host' => '127.0.0.1', 'port' => 1, 'encryption' => 'none']);

        $errors = $this->actingAs($this->admin)->post('/admin/settings/mail-test')
            ->assertRedirect('/admin/settings?tab=mail')
            ->assertSessionHasErrors('mail')
            ->baseResponse->getSession()->get('errors')->get('mail');

        $this->assertStringContainsString('127.0.0.1:1', $errors[0]);
    }

    // ---------- the subscriptions note ----------

    public function test_the_mail_panel_shows_whether_subscriptions_are_on(): void
    {
        $this->actingAs($this->admin)->get('/admin/settings?tab=mail')->assertOk()
            ->assertSee('Subscriptions are on')
            ->assertSee(route('admin.subscribers'));

        Setting::put('subscribers.enabled', '0');

        $this->actingAs($this->admin)->get('/admin/settings?tab=mail')->assertOk()
            ->assertSee('Subscriptions are off');
    }
}
