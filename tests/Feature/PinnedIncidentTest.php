<?php

namespace Tests\Feature;

use App\Enums\ComponentStatus;
use App\Enums\IncidentStatus;
use App\Enums\UserRole;
use App\Models\Component;
use App\Models\Incident;
use App\Models\IncidentUpdate;
use App\Models\Setting;
use App\Models\StatusPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The pin on an incident was a switch that did nothing: the page ignored it and
 * nothing could take it off again.
 */
class PinnedIncidentTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => UserRole::Admin]);
        Setting::put('page.incident_days', 2);
        Component::create(['name' => 'Mail', 'status' => ComponentStatus::Operational]);
    }

    private function resolved(string $name, int $daysAgo, array $attributes = []): Incident
    {
        $start = now()->subDays($daysAgo)->setTime(10, 0);
        $incident = Incident::create(array_merge(['name' => $name, 'status' => IncidentStatus::Resolved,
            'occurred_at' => $start, 'resolved_at' => $start->copy()->addMinutes(30)], $attributes));
        IncidentUpdate::create(['incident_id' => $incident->id, 'status' => IncidentStatus::Resolved, 'message' => 'Done.', 'created_at' => $start->copy()->addMinutes(30)]);

        return $incident;
    }

    public function test_a_pinned_incident_stays_at_the_top_of_the_log_after_it_is_over(): void
    {
        $this->resolved('Storage move', 12, ['pinned' => true]);
        $this->resolved('Old and loose', 20);

        $this->get('/')->assertOk()
            ->assertSeeInOrder(['Incident log', '<h3 class="label sect">Pinned</h3>', 'Storage move'], false)
            ->assertDontSee('Old and loose')
            // The quiet line still holds, and with an entry in it the log keeps its spine.
            ->assertSee('No incidents in the past 12 days')
            ->assertDontSee('class="log is-quiet"', false);

        // Its own day in the history keeps it too, and only the first page pins.
        $this->get('/?page=7')->assertOk()->assertSee('Storage move')->assertDontSee('<h3 class="label sect">Pinned</h3>', false);
    }

    public function test_a_pinned_incident_shows_once_and_only_to_those_who_may_see_it(): void
    {
        $this->resolved('Today and pinned', 0, ['pinned' => true]);
        $this->resolved('Internal and pinned', 9, ['pinned' => true, 'visibility' => 'internal']);
        $open = Incident::create(['name' => 'Open and pinned', 'status' => IncidentStatus::Investigating, 'occurred_at' => now()->subMinutes(5), 'pinned' => true]);
        IncidentUpdate::create(['incident_id' => $open->id, 'status' => IncidentStatus::Investigating, 'message' => 'Looking.']);

        $html = $this->get('/')->assertOk()->assertDontSee('Internal and pinned')->getContent();

        // Already in today's log and under Ongoing: no second copy under a Pinned heading.
        $this->assertSame(1, substr_count($html, 'id="incident-'.Incident::where('name', 'Today and pinned')->value('id').'"'));
        $this->assertSame(1, substr_count($html, 'id="incident-'.$open->id.'"'));
        $this->assertStringNotContainsString('<h3 class="label sect">Pinned</h3>', $html);
    }

    public function test_an_admin_pins_and_unpins_from_the_list(): void
    {
        $incident = $this->resolved('Storage move', 12);

        $this->actingAs($this->admin)->get('/admin/incidents')->assertOk()
            ->assertSee('action="'.url('/admin/incidents/'.$incident->id.'/pin').'"', false)
            ->assertDontSee('Pinned on the status page');

        $this->actingAs($this->admin)->post('/admin/incidents/'.$incident->id.'/pin', ['pinned' => '1'])
            ->assertRedirect('/admin/incidents')->assertSessionHas('status');
        $this->assertTrue($incident->fresh()->pinned);
        $this->actingAs($this->admin)->get('/admin/incidents')->assertOk()
            ->assertSee('Pinned on the status page')->assertSee('Unpin');
        $this->get('/')->assertSee('Storage move');

        $this->actingAs($this->admin)->post('/admin/incidents/'.$incident->id.'/pin', ['pinned' => '0'])->assertRedirect('/admin/incidents');
        $this->assertFalse($incident->fresh()->pinned);
        $this->get('/')->assertDontSee('Storage move');
    }

    public function test_an_open_incident_can_be_pinned_from_its_card_and_a_viewer_cannot_pin(): void
    {
        $open = Incident::create(['name' => 'Open now', 'status' => IncidentStatus::Investigating, 'occurred_at' => now()->subMinutes(5)]);

        $this->actingAs($this->admin)->get('/admin/incidents')->assertOk()
            ->assertSee('action="'.url('/admin/incidents/'.$open->id.'/pin').'"', false);

        $viewer = User::factory()->create(['role' => UserRole::User]);
        $viewer->statusPages()->attach(StatusPage::defaultId(), ['role' => 'viewer']);
        $this->actingAs($viewer)->post('/admin/incidents/'.$open->id.'/pin', ['pinned' => '1'])->assertForbidden();
        $this->assertFalse($open->fresh()->pinned);
    }

    public function test_the_form_says_what_the_pin_does(): void
    {
        $this->actingAs($this->admin)->get('/admin/incidents/create')->assertOk()
            ->assertSee('Stays at the top of the incident log, also after it is resolved, until you unpin it.')
            ->assertDontSee('For an ongoing incident people should see first.');
    }
}
