<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\StatusPage;
use App\Models\User;
use App\Notifications\InviteUser;
use App\Notifications\ResetAccountPassword;
use App\Services\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class UserInvitationTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => UserRole::Admin, 'name' => 'Raymon']);
    }

    protected function inviteAnita(array $extra = []): User
    {
        Notification::fake();
        $page = StatusPage::default();
        $this->actingAs($this->admin)->post('/admin/users', [
            'name' => 'Anita', 'email' => 'anita@example.net',
            'role' => 'user', 'access' => [$page->id => 'viewer'], ...$extra,
        ])->assertRedirect('/admin/users')->assertSessionHas('status', 'Invitation sent to anita@example.net.');

        return User::where('email', 'anita@example.net')->sole();
    }

    public function test_adding_someone_without_a_password_sends_an_invitation(): void
    {
        $anita = $this->inviteAnita();

        $this->assertSame('viewer', $anita->statusPages()->first()->pivot->role);
        $this->assertFalse($anita->require_two_factor);
        Notification::assertSentTo($anita, InviteUser::class, function (InviteUser $n) use ($anita) {
            $url = $n->url($anita);
            $mail = $n->toMail($anita);

            return str_starts_with($url, rtrim(config('app.url'), '/').'/admin/welcome/')
                && str_contains($url, 'email=anita%40example.net')
                && $mail->actionUrl === $url
                && str_contains($mail->subject, 'You are invited')
                && str_contains(implode(' ', $mail->introLines), 'Raymon created an account');
        });
        $this->assertDatabaseCount('invitation_tokens', 1);
    }

    public function test_the_invitation_link_sets_the_password_once(): void
    {
        $anita = $this->inviteAnita();
        $token = null;
        Notification::assertSentTo($anita, InviteUser::class, function (InviteUser $n) use (&$token) {
            $token = $n->token;

            return true;
        });

        $this->post('/admin/logout');
        $this->get('/admin/welcome/'.$token.'?email=anita@example.net')->assertOk()->assertSee('Choose your password')
            ->assertSee(route('admin.invitation.accept'), false);

        $this->post('/admin/welcome', ['email' => 'anita@example.net', 'token' => $token, 'password' => 'a-good-long-password', 'password_confirmation' => 'a-good-long-password'])
            ->assertRedirect(route('admin.login'));
        $this->assertTrue(Hash::check('a-good-long-password', $anita->fresh()->password));

        // Used up.
        $this->post('/admin/welcome', ['email' => 'anita@example.net', 'token' => $token, 'password' => 'another-long-password', 'password_confirmation' => 'another-long-password'])
            ->assertSessionHasErrors('email');
    }

    public function test_reset_and_invitation_tokens_do_not_cross(): void
    {
        $anita = $this->inviteAnita();
        $resetToken = Password::broker()->createToken($anita);

        $this->post('/admin/logout');
        $this->post('/admin/welcome', ['email' => 'anita@example.net', 'token' => $resetToken, 'password' => 'a-good-long-password', 'password_confirmation' => 'a-good-long-password'])
            ->assertSessionHasErrors('email');

        $inviteToken = Password::broker('invitations')->createToken($anita);
        $this->post(route('admin.password.update'), ['email' => 'anita@example.net', 'token' => $inviteToken, 'password' => 'a-good-long-password', 'password_confirmation' => 'a-good-long-password'])
            ->assertSessionHasErrors('email');
    }

    public function test_a_typed_password_still_works_and_sends_nothing(): void
    {
        Notification::fake();
        $this->actingAs($this->admin)->post('/admin/users', [
            'name' => 'Bob', 'email' => 'bob@example.net', 'password' => 'a-good-long-password', 'password_confirmation' => 'a-good-long-password',
        ])->assertSessionHas('status', 'Bob can now sign in.');

        Notification::assertNothingSent();
        $this->assertTrue(Hash::check('a-good-long-password', User::where('email', 'bob@example.net')->sole()->password));
    }

    public function test_a_failing_mail_still_creates_the_account_and_says_so(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 9, 'mail.mailers.smtp.timeout' => 1]);

        $this->actingAs($this->admin)->post('/admin/users', ['name' => 'Cleo', 'email' => 'cleo@example.net'])
            ->assertRedirect('/admin/users')->assertSessionHasErrors('mail');

        $this->assertDatabaseHas('users', ['email' => 'cleo@example.net']);
    }

    public function test_only_administrators_invite(): void
    {
        Notification::fake();
        $pageAdmin = User::factory()->create(['role' => UserRole::User]);
        $pageAdmin->statusPages()->attach(StatusPage::defaultId(), ['role' => 'admin']);

        $this->actingAs($pageAdmin)->post('/admin/users', ['name' => 'X', 'email' => 'x@example.net'])->assertForbidden();
        $this->actingAs($pageAdmin)->post('/admin/users/'.$this->admin->id.'/invite')->assertForbidden();
        Notification::assertNothingSent();

        $this->actingAs($this->admin)->post('/admin/users/'.$pageAdmin->id.'/invite')->assertSessionHas('status');
        Notification::assertSentTo($pageAdmin, InviteUser::class);
        Notification::assertNotSentTo($pageAdmin, ResetAccountPassword::class);
    }

    public function test_required_two_factor_keeps_the_user_on_the_profile_until_it_is_on(): void
    {
        $anita = $this->inviteAnita(['require_two_factor' => '1']);
        $this->assertTrue($anita->require_two_factor);

        $this->flushSession();
        $this->actingAs($anita)->get('/admin/overview')->assertRedirect(route('admin.profile'));
        $this->get('/admin/components')->assertRedirect(route('admin.profile'));
        $this->getJson('/admin/search?q=ab')->assertForbidden();
        $this->get('/admin/profile')->assertOk();

        $this->post('/admin/profile/two-factor')->assertRedirect();
        $secret = $anita->fresh()->totp_secret;
        $totp = app(Totp::class);
        $code = $totp->at($secret, intdiv(time(), 30));
        $this->post('/admin/profile/two-factor/confirm', ['code' => $code])->assertRedirect(route('admin.profile'));

        $this->assertFalse($anita->fresh()->require_two_factor);
        $this->actingAs($anita->fresh())->get('/admin/overview')->assertOk();
    }

    public function test_edit_access_sets_role_and_pages_together(): void
    {
        $page = StatusPage::create(['name' => 'Harbor', 'slug' => 'harbor', 'is_published' => true]);
        $member = User::factory()->create(['role' => UserRole::User]);

        $this->actingAs($this->admin)->put('/admin/users/'.$member->id.'/access', [
            'role' => 'user', 'access' => [StatusPage::defaultId() => 'editor', $page->id => 'admin'],
        ])->assertRedirect('/admin/users');
        $this->assertSame([StatusPage::defaultId() => 'editor', $page->id => 'admin'], $member->statusPages()->pluck('status_page_user.role', 'status_pages.id')->all());

        $this->put('/admin/users/'.$member->id.'/access', ['role' => 'user', 'access' => [StatusPage::defaultId() => 'none', $page->id => 'viewer']]);
        $this->assertSame([$page->id => 'viewer'], $member->statusPages()->pluck('status_page_user.role', 'status_pages.id')->all());

        $this->put('/admin/users/'.$member->id.'/access', ['role' => 'admin'])->assertRedirect('/admin/users');
        $this->assertTrue($member->fresh()->isAdmin());

        $this->put('/admin/users/'.$member->id.'/access', ['role' => 'user', 'access' => [$page->id => 'owner']])->assertSessionHasErrors('access.'.$page->id);
    }

    public function test_the_last_administrator_cannot_lock_everyone_out(): void
    {
        $this->actingAs($this->admin)->put('/admin/users/'.$this->admin->id.'/access', ['role' => 'user'])
            ->assertSessionHasErrors('role');
        $this->assertTrue($this->admin->fresh()->isAdmin());
    }

    public function test_users_screen_shows_drawers_roles_and_last_seen(): void
    {
        $member = User::factory()->create(['role' => UserRole::User, 'name' => 'Zed']);

        $this->actingAs($this->admin)->get('/admin/users?q=zed')->assertOk()
            ->assertSee('id="user-add"', false)
            ->assertSee('id="user-edit-'.$member->id.'"', false)
            ->assertSee('What each role may do')
            ->assertSee('No page access yet')
            ->assertSee('value="zed"', false)
            ->assertSee('Ask them to turn on two factor');
    }
}
