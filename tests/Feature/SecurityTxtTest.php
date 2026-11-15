<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\InstallSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SecurityTxtTest extends TestCase
{
    use RefreshDatabase;

    private function save(User $admin, string $contact)
    {
        return $this->actingAs($admin)->from('/admin/settings')->put('/admin/settings', [
            'timezone' => 'UTC', 'audit_days' => 180, 'keep_backups' => 3, 'update_check' => '1', 'security_contact' => $contact,
        ]);
    }

    public function test_there_is_no_file_until_a_security_contact_is_set(): void
    {
        $this->get('/.well-known/security.txt')->assertNotFound();
    }

    public function test_the_contact_from_settings_general_is_served_as_security_txt_without_a_session(): void
    {
        Carbon::setTestNow('2026-11-15 10:30:00');
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($admin)->get('/admin/settings')->assertOk()->assertSee('name="security_contact"', false);
        $this->save($admin, 'security@example.com')->assertRedirect('/admin/settings');
        $this->assertSame('security@example.com', InstallSettings::securityContact());

        $this->app['auth']->forgetGuards();
        config(['app.url' => 'https://status.example.com']);
        $response = $this->get('/.well-known/security.txt')->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=utf-8')
            ->assertHeaderMissing('Set-Cookie');
        $this->assertSame(
            "Contact: mailto:security@example.com\nExpires: 2027-11-15T00:00:00Z\nPreferred-Languages: en, nl, de, es\nCanonical: https://status.example.com/.well-known/security.txt\n",
            $response->getContent(),
        );

        config(['app.url' => 'http://localhost']);
        $this->save($admin, 'https://example.com/security')->assertRedirect('/admin/settings');
        $this->get('/.well-known/security.txt')->assertOk()->assertSee('Contact: https://example.com/security');

        $this->save($admin, '')->assertRedirect('/admin/settings');
        $this->get('/.well-known/security.txt')->assertNotFound();
    }

    public function test_only_an_email_address_or_an_https_link_is_accepted(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        foreach (['http://example.com/security', 'call us', "a@example.com\nContact: evil"] as $bad) {
            $this->save($admin, $bad)->assertSessionHasErrors('security_contact');
        }
        $this->assertSame('', InstallSettings::securityContact());
    }
}
