<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\UserSessions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * "Where you are signed in" reads and ends sessions of the signed in account
 * only, and never shows a raw session id.
 */
class ProfileSessionsTest extends TestCase
{
    use RefreshDatabase;

    protected User $me;

    protected User $other;

    protected function setUp(): void
    {
        parent::setUp();
        config(['session.driver' => 'database']);
        $this->me = User::factory()->create(['role' => UserRole::Admin]);
        $this->other = User::factory()->create(['role' => UserRole::Admin]);
    }

    protected function addSession(string $id, User $user, string $agent, int $ago = 60): void
    {
        DB::table('sessions')->insert([
            'id' => $id, 'user_id' => $user->id, 'ip_address' => '203.0.113.7',
            'user_agent' => $agent, 'payload' => '', 'last_activity' => time() - $ago,
        ]);
    }

    public function test_the_profile_lists_only_my_sessions_without_raw_ids(): void
    {
        $this->addSession('my-phone-session-id', $this->me, 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile/15E148 Safari/604.1');
        $this->addSession('their-session-id', $this->other, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Gecko/20100101 Firefox/130.0');

        $html = $this->actingAs($this->me)->get('/admin/profile')->assertOk()
            ->assertSee('Safari on iPhone')
            ->assertDontSee('Firefox on Windows')
            ->assertSee(UserSessions::key('my-phone-session-id'))
            ->getContent();

        $this->assertStringNotContainsString('my-phone-session-id', $html);
        $this->assertStringNotContainsString(UserSessions::key('their-session-id'), $html);
    }

    public function test_i_can_sign_out_one_of_my_sessions_but_not_someone_elses(): void
    {
        $this->addSession('my-old-session', $this->me, 'Firefox/130.0 (Windows)');
        $this->addSession('their-session', $this->other, 'Firefox/130.0 (Windows)');

        $this->actingAs($this->me)->delete('/admin/profile/sessions/'.UserSessions::key('their-session'))
            ->assertRedirect(route('admin.profile'))->assertSessionHasErrors('session');
        $this->assertDatabaseHas('sessions', ['id' => 'their-session']);

        $token = $this->me->fresh()->remember_token;
        $this->delete('/admin/profile/sessions/'.UserSessions::key('my-old-session'))
            ->assertRedirect(route('admin.profile'))->assertSessionHas('status', 'That session is signed out.');
        $this->assertDatabaseMissing('sessions', ['id' => 'my-old-session']);
        $this->assertDatabaseHas('sessions', ['id' => 'their-session']);
        $this->assertNotSame($token, $this->me->fresh()->remember_token);

        $this->delete('/admin/profile/sessions/not-a-hash')->assertNotFound();
    }

    public function test_sign_out_the_others_keeps_mine_and_everyone_elses(): void
    {
        $this->addSession('mine-1', $this->me, 'Chrome/128.0 (Macintosh)');
        $this->addSession('mine-2', $this->me, 'Chrome/128.0 (Macintosh)');
        $this->addSession('theirs', $this->other, 'Chrome/128.0 (Macintosh)');

        $this->actingAs($this->me)->delete('/admin/profile/sessions')
            ->assertSessionHas('status', 'Signed out everywhere else (2 sessions).');

        $this->assertDatabaseMissing('sessions', ['id' => 'mine-1']);
        $this->assertDatabaseMissing('sessions', ['id' => 'mine-2']);
        $this->assertDatabaseHas('sessions', ['id' => 'theirs']);
    }

    public function test_the_current_session_is_never_ended_by_key(): void
    {
        $sessions = app(UserSessions::class);
        $this->addSession('current-one', $this->me, 'Chrome/128.0');

        $this->assertFalse($sessions->destroy($this->me, UserSessions::key('current-one'), 'current-one'));
        $this->assertSame(0, $sessions->destroyOthers($this->me, 'current-one'));
        $this->assertDatabaseHas('sessions', ['id' => 'current-one']);
    }

    public function test_user_agents_read_as_browser_and_system(): void
    {
        $this->assertSame(['browser' => 'Edge', 'platform' => 'Windows', 'phone' => false],
            UserSessions::describe('Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 Chrome/128.0 Safari/537.36 Edg/128.0'));
        $this->assertSame(['browser' => 'Chrome', 'platform' => 'Android', 'phone' => true],
            UserSessions::describe('Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 Chrome/128.0 Mobile Safari/537.36'));
        $this->assertSame('Unknown browser', UserSessions::describe('')['browser']);
    }

    public function test_last_seen_on_the_users_screen_comes_from_sessions(): void
    {
        $this->addSession('seen', $this->other, 'Chrome/128.0', ago: 3 * 86400);

        $this->actingAs($this->me)->get('/admin/users')->assertOk()->assertSee('Last seen <b>3 days ago</b>', false);
    }

    public function test_the_theme_preference_is_saved_and_applied(): void
    {
        $this->actingAs($this->me)->put('/admin/profile/preferences', ['theme' => 'dark'])
            ->assertRedirect(route('admin.profile'))->assertSessionHas('theme_saved');
        $this->assertSame('dark', $this->me->fresh()->theme);

        $html = $this->actingAs($this->me->fresh())->get('/admin/overview')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<html lang="en"\s+data-theme="dark"/', $html);

        $this->put('/admin/profile/preferences', ['theme' => 'purple'])->assertSessionHasErrors('theme');
        $this->assertSame('dark', $this->me->fresh()->theme);
    }

    public function test_the_theme_of_one_user_does_not_leak_to_another(): void
    {
        $this->me->forceFill(['theme' => 'dark'])->save();

        $html = $this->actingAs($this->other)->get('/admin/overview')->assertOk()->getContent();
        $this->assertDoesNotMatchRegularExpression('/<html lang="en"\s+data-theme="dark"/', $html);
    }
}
