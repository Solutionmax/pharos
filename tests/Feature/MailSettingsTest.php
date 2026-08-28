<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Mail\TestMail;
use App\Models\AuditEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Settings → Mail: what the install sends with, and the button that proves it. */
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

        $this->actingAs($this->admin)->get('/admin/settings')->assertOk()
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
        \App\Models\Setting::put('brand.name', 'Acme Cloud');

        $this->actingAs($this->admin)->get('/admin/settings')->assertOk()
            ->assertSee('Acme Cloud &lt;', false);
    }

    public function test_the_test_mail_goes_to_the_signed_in_admin(): void
    {
        $this->actingAs($this->admin)->post('/admin/settings/mail-test')
            ->assertRedirect('/admin/settings')
            ->assertSessionHas('status', 'Test e-mail sent to raymon@example.net.');

        Mail::assertSent(TestMail::class, fn (TestMail $m) => $m->hasTo('raymon@example.net')
            && str_contains($m->render(), 'Mail works'));

        $this->assertSame(1, AuditEntry::where('action', 'mail.test')->count());
    }

    public function test_a_transport_failure_is_shown_not_hidden(): void
    {
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('Connection refused [smtp.example.net:587]'));

        $this->actingAs($this->admin)->post('/admin/settings/mail-test')
            ->assertRedirect('/admin/settings')
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
}
