<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The "Good to know" notes around the admin can be hidden per person, and
 * brought back from the profile. "Careful" warnings cannot be hidden at all.
 */
class NotesTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // The Updates screen must not call out while it is used as a fixture.
        config(['pharos.update.check_enabled' => false]);
        Http::fake();
        Cache::flush();

        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'admin@example.com',
            'password' => Hash::make('correct-horse-battery'),
        ]);
    }

    public function test_a_note_offers_a_close_button_and_disappears_once_dismissed(): void
    {
        $this->actingAs($this->admin)->get('/admin/updates')
            ->assertOk()
            ->assertSee('data-note="updates.backups"', false)
            ->assertSee('class="callout-x"', false)
            ->assertSee('Each update copies the version it replaces');

        $this->actingAs($this->admin)
            ->postJson('/admin/notes/updates.backups/dismiss')
            ->assertNoContent();

        $this->actingAs($this->admin)->get('/admin/updates')
            ->assertOk()
            ->assertDontSee('data-note="updates.backups"', false)
            ->assertDontSee('Each update copies the version it replaces')
            // The other note on that page is untouched.
            ->assertSee('data-note="updates.safe"', false);
    }

    public function test_dismissing_without_javascript_goes_back_to_the_page(): void
    {
        $this->actingAs($this->admin)
            ->from('/admin/integrations')
            ->post('/admin/notes/integrations.one-attempt/dismiss')
            ->assertRedirect('/admin/integrations');

        $this->assertTrue($this->admin->fresh()->hasDismissed('integrations.one-attempt'));
    }

    public function test_dismissals_are_per_user(): void
    {
        $other = User::create([
            'name' => 'Other', 'email' => 'other@example.com',
            'password' => Hash::make('correct-horse-battery'),
        ]);

        $this->actingAs($this->admin)->postJson('/admin/notes/integrations.one-attempt/dismiss')->assertNoContent();

        $this->actingAs($this->admin)->get('/admin/integrations')
            ->assertDontSee('data-note="integrations.one-attempt"', false);
        // AuthenticateSession would treat the second person as a hijack of the first's session.
        $this->flushSession();
        $this->actingAs($other)->get('/admin/integrations')
            ->assertSee('data-note="integrations.one-attempt"', false);
    }

    public function test_dismissing_twice_stores_the_id_once(): void
    {
        $this->actingAs($this->admin)->postJson('/admin/notes/audit.record/dismiss')->assertNoContent();
        $this->actingAs($this->admin)->postJson('/admin/notes/audit.record/dismiss')->assertNoContent();

        $this->assertSame(['audit.record'], $this->admin->fresh()->dismissed_notes);
    }

    public function test_restoring_brings_every_note_back_and_says_so(): void
    {
        $this->admin->dismissNote('updates.backups');
        $this->admin->dismissNote('integrations.one-attempt');

        $this->actingAs($this->admin)
            ->from('/admin/profile')
            ->post('/admin/notes/restore')
            ->assertRedirect('/admin/profile')
            ->assertSessionHas('status', 'All notes are back.');

        $this->assertFalse($this->admin->fresh()->hasDismissed('updates.backups'));
        $this->actingAs($this->admin)->get('/admin/integrations')
            ->assertSee('data-note="integrations.one-attempt"', false);
    }

    public function test_a_warning_cannot_be_dismissed(): void
    {
        // PHAROS_VERSION pinned in .env puts the "Careful" box on the Updates screen.
        putenv('PHAROS_VERSION=0.1.0-dev');
        $_ENV['PHAROS_VERSION'] = $_SERVER['PHAROS_VERSION'] = '0.1.0-dev';
        $this->admin->dismissNote('updates.pinned');

        try {
            $body = $this->actingAs($this->admin)->get('/admin/updates')->assertOk()->getContent();
        } finally {
            putenv('PHAROS_VERSION');
            unset($_ENV['PHAROS_VERSION'], $_SERVER['PHAROS_VERSION']);
        }

        $this->assertStringContainsString('data-note="updates.pinned"', $body);
        $this->assertStringContainsString('class="callout warn"', $body);
        // The close button belongs to the two ordinary notes only.
        $this->assertSame(2, substr_count($body, 'class="callout-x"'));
        $this->assertStringNotContainsString('notes/updates.pinned/dismiss', $body);
    }

    public function test_an_odd_id_is_refused_by_the_route(): void
    {
        $this->actingAs($this->admin)->postJson('/admin/notes/../x/dismiss')->assertNotFound();
        $this->actingAs($this->admin)->postJson('/admin/notes/Upper.Case/dismiss')->assertNotFound();
        $this->actingAs($this->admin)->postJson('/admin/notes/with space/dismiss')->assertNotFound();
    }

    public function test_the_profile_counts_hidden_notes_and_offers_them_back(): void
    {
        $this->actingAs($this->admin)->get('/admin/profile')
            ->assertOk()
            ->assertSee('All Good to know notes are showing.')
            ->assertDontSee('Show all notes again');

        $this->admin->dismissNote('updates.backups');
        $this->admin->dismissNote('audit.record');

        $this->actingAs($this->admin)->get('/admin/profile')
            ->assertOk()
            ->assertSee('You have hidden 2 Good to know notes.')
            ->assertSee('Show all notes again')
            ->assertSee(route('admin.notes.restore'), false);
    }

    public function test_a_guest_cannot_dismiss_or_restore(): void
    {
        $this->post('/admin/notes/updates.backups/dismiss')->assertRedirect('/admin/login');
        $this->post('/admin/notes/restore')->assertRedirect('/admin/login');
    }

    public function test_hiding_a_note_is_not_an_audit_event(): void
    {
        $this->actingAs($this->admin)->postJson('/admin/notes/audit.record/dismiss')->assertNoContent();

        $this->assertDatabaseMissing('audit_log', ['action' => 'user.updated']);
    }

    /** The profile says where each hidden note lives, and one can come back on its own. */
    public function test_the_profile_lists_hidden_notes_by_page_and_restores_one(): void
    {
        $user = $this->admin;
        $user->dismissNote('updates.safe');
        $user->dismissNote('subscribers.how');
        $user->dismissNote('some.unknown-note');

        $page = $this->actingAs($user)->get('/admin/profile')->assertOk();
        $page->assertSee('Updates')->assertSee('Why an update is safe to take')
            ->assertSee('Subscribers')->assertSee('How subscriptions work')
            ->assertSee('Elsewhere')->assertSee('some.unknown-note')
            ->assertSee(route('admin.updates'), false);

        $this->actingAs($user)->post('/admin/notes/updates.safe/restore')->assertRedirect();
        $this->assertSame(['subscribers.how', 'some.unknown-note'], $user->fresh()->dismissed_notes);
        $this->actingAs($user)->get('/admin/profile')->assertDontSee('Why an update is safe to take');
    }
}
