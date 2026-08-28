<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RolesTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name' => 'Raymon', 'email' => 'raymon@example.com',
            'password' => Hash::make('correct-horse-battery'), 'role' => UserRole::Admin,
        ]);

        $this->member = User::create([
            'name' => 'Tom', 'email' => 'tom@example.com',
            'password' => Hash::make('correct-horse-battery'), 'role' => UserRole::User,
        ]);
    }

    public static function adminOnlyPages(): array
    {
        return [
            'the user list' => ['/admin/users'],
            'the audit log' => ['/admin/audit'],
            'the update screen' => ['/admin/updates'],
            'branding' => ['/admin/branding'],
            'mail templates' => ['/admin/mail-templates'],
            'settings' => ['/admin/settings'],
        ];
    }

    #[DataProvider('adminOnlyPages')]
    public function test_a_user_is_kept_out_of_the_admin_only_pages(string $url): void
    {
        $this->actingAs($this->member)->get($url)->assertForbidden();

        // AuthenticateSession signs the old account out when the user behind a
        // session changes, so the second visit needs a session of its own.
        $this->flushSession();
        $this->actingAs($this->admin)->get($url)->assertOk();
    }

    public function test_the_old_sso_url_redirects_to_settings(): void
    {
        // Single sign-on moved into Settings. Bookmarks and the docs still say
        // /admin/sso, so that address keeps working instead of turning into a 404.
        $this->actingAs($this->admin)->get('/admin/sso')
            ->assertStatus(301)
            ->assertRedirect('/admin/settings?tab=sso');
    }

    public function test_a_user_can_shape_the_status_page_but_not_the_installation(): void
    {
        // What the page shows is operational; the time zone and sign-in are not.
        $this->actingAs($this->member)->get('/admin/status-page')->assertOk();
        $this->actingAs($this->member)->put('/admin/status-page', [
            'theme' => 'dark',
            'incident_days' => 7,
        ])->assertRedirect('/admin/status-page');
        $this->assertSame('dark', \App\Models\Setting::get('brand.theme'));

        $this->actingAs($this->member)->get('/admin/settings')->assertForbidden();
        $this->actingAs($this->member)->put('/admin/settings', ['timezone' => 'Europe/Amsterdam'])->assertForbidden();
        $this->actingAs($this->member)->put('/admin/sso', ['enabled' => '0'])->assertForbidden();
        $this->assertSame('UTC', \App\Services\Clock::timezone());
    }

    public function test_a_user_may_not_touch_api_tokens_or_the_signing_secret(): void
    {
        $this->actingAs($this->member)->post('/admin/integrations/tokens', ['name' => 'mine'])->assertForbidden();
        $this->actingAs($this->member)->post('/admin/integrations/webhook/rotate')->assertForbidden();
    }

    public function test_a_user_still_runs_the_status_page_itself(): void
    {
        // The whole point of the role: everything operational stays open.
        $this->actingAs($this->member)->get('/admin/components')->assertOk();
        $this->actingAs($this->member)->get('/admin/incidents')->assertOk();
        $this->actingAs($this->member)->get('/admin/integrations')->assertOk();
    }

    public function test_the_integrations_page_hides_tokens_from_a_user(): void
    {
        $this->actingAs($this->admin)->get('/admin/integrations')->assertSee('API tokens');

        $this->flushSession();
        $this->actingAs($this->member)->get('/admin/integrations')->assertDontSee('API tokens');
    }

    public function test_a_user_can_still_change_their_own_password(): void
    {
        $this->actingAs($this->member)->get('/admin/profile')->assertOk();

        $this->actingAs($this->member)->put('/admin/profile/password', [
            'current_password' => 'correct-horse-battery',
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertRedirect();

        $this->assertTrue(Hash::check('a-brand-new-password', $this->member->fresh()->password));
    }

    public function test_the_last_administrator_cannot_be_demoted(): void
    {
        // Same reasoning as the last account: an install with no admin left has no
        // way back through the interface.
        $this->actingAs($this->admin)->put("/admin/users/{$this->admin->id}/role", ['role' => 'user'])
            ->assertSessionHasErrors('role');

        $this->assertTrue($this->admin->fresh()->isAdmin());
    }

    public function test_an_admin_may_promote_and_demote_someone_else(): void
    {
        $this->actingAs($this->admin)->put("/admin/users/{$this->member->id}/role", ['role' => 'admin'])
            ->assertRedirect('/admin/users');
        $this->assertTrue($this->member->fresh()->isAdmin());

        $this->actingAs($this->admin)->put("/admin/users/{$this->member->id}/role", ['role' => 'user'])
            ->assertRedirect('/admin/users');
        $this->assertFalse($this->member->fresh()->isAdmin());
    }

    public function test_a_user_may_not_hand_themselves_the_admin_role(): void
    {
        $this->actingAs($this->member)->put("/admin/users/{$this->member->id}/role", ['role' => 'admin'])
            ->assertForbidden();

        $this->assertFalse($this->member->fresh()->isAdmin());
    }

    public function test_a_new_account_is_an_ordinary_user_unless_asked_otherwise(): void
    {
        $this->actingAs($this->admin)->post('/admin/users', [
            'name' => 'Nieuw', 'email' => 'nieuw@example.com',
            'password' => 'a-long-enough-password', 'password_confirmation' => 'a-long-enough-password',
        ])->assertRedirect('/admin/users');

        $this->assertFalse(User::where('email', 'nieuw@example.com')->first()->isAdmin());
    }

    public function test_the_cli_can_hand_out_the_admin_role(): void
    {
        // The way back in when the last administrator is gone.
        $this->artisan('pharos:user', ['email' => 'tom@example.com', '--role' => 'admin'])
            ->assertSuccessful();

        $this->assertTrue(User::where('email', 'tom@example.com')->first()->isAdmin());
    }

    public function test_the_cli_refuses_a_role_that_does_not_exist(): void
    {
        $this->artisan('pharos:user', ['email' => 'tom@example.com', '--role' => 'wizard'])
            ->assertFailed();

        $this->assertFalse($this->member->fresh()->isAdmin());
    }
}
