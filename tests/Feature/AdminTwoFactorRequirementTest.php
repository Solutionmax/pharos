<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AuditEntry;
use App\Models\RecoveryCode;
use App\Models\Setting;
use App\Models\StatusPage;
use App\Models\User;
use App\Services\InstallSettings;
use App\Services\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Settings, General: "Require two factor for administrators". */
class AdminTwoFactorRequirementTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name' => 'Raymon', 'email' => 'raymon@example.com',
            'password' => Hash::make('correct-horse-battery'), 'role' => UserRole::Admin,
        ]);
    }

    protected function requireForAdmins(): void
    {
        Setting::put(InstallSettings::ADMIN_TWO_FACTOR, '1');
    }

    protected function enrol(User $user): void
    {
        $user->forceFill(['totp_secret' => (new Totp)->secret(), 'totp_confirmed_at' => now()])->save();
        RecoveryCode::replaceFor($user);
    }

    /** @return array<string, mixed> */
    protected function general(array $with = []): array
    {
        return $with + ['timezone' => 'UTC', 'audit_days' => 180, 'keep_backups' => 3, 'update_check' => '1'];
    }

    public function test_the_rule_is_off_after_an_update_so_nobody_is_locked_out(): void
    {
        $this->assertFalse(InstallSettings::adminTwoFactorRequired());
        $this->assertFalse($this->admin->mustSetUpTwoFactor());

        $this->actingAs($this->admin)->get('/admin/overview')->assertOk();
    }

    public function test_an_admin_without_two_factor_is_kept_on_the_profile(): void
    {
        $this->requireForAdmins();

        $this->actingAs($this->admin)->get('/admin/overview')
            ->assertRedirect('/admin/profile')
            ->assertSessionHas('status', 'This installation requires two factor authentication for administrators. Turn it on before you continue.');
        $this->actingAs($this->admin)->get('/admin/settings')->assertRedirect('/admin/profile');
        $this->actingAs($this->admin)->get('/admin/pages/'.StatusPage::defaultId().'/components')->assertRedirect('/admin/profile');
        $this->actingAs($this->admin)->getJson('/admin/users')->assertForbidden();

        // The profile, where two factor is set up, and the way out stay open.
        $this->actingAs($this->admin)->get('/admin/profile')->assertOk();
        $this->actingAs($this->admin)->post('/admin/profile/two-factor')->assertRedirect('/admin/profile');
        $this->actingAs($this->admin)->post('/admin/logout')->assertRedirect('/admin/login');
    }

    public function test_setting_up_two_factor_releases_the_admin(): void
    {
        $this->requireForAdmins();
        $totp = new Totp;

        $this->actingAs($this->admin)->post('/admin/profile/two-factor');
        $secret = $this->admin->fresh()->totp_secret;
        $this->actingAs($this->admin)->post('/admin/profile/two-factor/confirm', [
            'code' => $totp->at($secret, intdiv(time(), Totp::PERIOD)),
        ])->assertRedirect('/admin/profile');

        $this->assertTrue($this->admin->fresh()->hasTwoFactor());
        $this->actingAs($this->admin->fresh())->get('/admin/overview')->assertOk();
    }

    public function test_an_admin_who_has_two_factor_is_not_held_up(): void
    {
        $this->requireForAdmins();
        $this->enrol($this->admin);

        $this->actingAs($this->admin)->get('/admin/overview')->assertOk();
        $this->actingAs($this->admin)->get('/admin/settings')->assertOk();
    }

    public function test_the_rule_does_not_reach_accounts_that_are_not_administrators(): void
    {
        $this->requireForAdmins();
        $member = User::factory()->create(['role' => UserRole::User]);
        $member->statusPages()->attach(StatusPage::defaultId(), ['role' => 'editor']);

        $this->assertFalse($member->mustSetUpTwoFactor());
        $this->actingAs($member)->get('/admin/overview')->assertOk();
    }

    public function test_the_settings_screen_switches_the_rule_and_audits_it(): void
    {
        $this->enrol($this->admin);

        $this->actingAs($this->admin)->get('/admin/settings')->assertOk()
            ->assertSee('Require two factor for administrators')
            ->assertSee('name="admin_two_factor"', false)
            ->assertDontSee('name="admin_two_factor" value="1" checked', false);

        $this->actingAs($this->admin)->put('/admin/settings', $this->general(['admin_two_factor' => '1']))
            ->assertRedirect('/admin/settings');

        $this->assertTrue(InstallSettings::adminTwoFactorRequired());
        $entry = AuditEntry::where('action', 'settings.saved')->latest('id')->firstOrFail();
        $this->assertSame(['from' => false, 'to' => true], $entry->changes[InstallSettings::ADMIN_TWO_FACTOR]);
        $this->assertSame('Raymon (raymon@example.com)', $entry->actor);

        $this->actingAs($this->admin)->get('/admin/settings')->assertOk()
            ->assertSee('name="admin_two_factor" value="1" checked', false);

        // Unticked is absent from the request: the rule goes off again, also audited.
        $this->actingAs($this->admin)->put('/admin/settings', $this->general())->assertRedirect('/admin/settings');
        $this->assertFalse(InstallSettings::adminTwoFactorRequired());
        $entry = AuditEntry::where('action', 'settings.saved')->latest('id')->firstOrFail();
        $this->assertSame(['from' => true, 'to' => false], $entry->changes[InstallSettings::ADMIN_TWO_FACTOR]);
    }

    public function test_only_an_installation_admin_can_change_the_rule(): void
    {
        $member = User::factory()->create(['role' => UserRole::User]);
        $member->statusPages()->attach(StatusPage::defaultId(), ['role' => 'admin']);

        $this->actingAs($member)->put('/admin/settings', $this->general(['admin_two_factor' => '1']))->assertForbidden();
        $this->assertFalse(InstallSettings::adminTwoFactorRequired());
    }

    public function test_the_team_list_shows_the_admin_as_asked_for_two_factor(): void
    {
        $other = User::factory()->create(['role' => UserRole::Admin, 'name' => 'Second admin']);
        $this->enrol($this->admin);

        $this->actingAs($this->admin)->get('/admin/users')->assertOk()->assertDontSee('2FA asked');

        $this->requireForAdmins();
        $this->assertTrue($other->mustSetUpTwoFactor());
        $this->actingAs($this->admin)->get('/admin/users')->assertOk()->assertSee('2FA asked');
    }
}
