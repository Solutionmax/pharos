<?php

namespace Tests\Feature;

use App\Enums\ComponentStatus;
use App\Enums\IncidentStatus;
use App\Models\Component;
use App\Models\Incident;
use App\Models\IncidentUpdate;
use App\Models\Setting;
use App\Models\User;
use App\Services\StatusTimeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class StatusTimelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_day_grows_with_the_time_it_lost(): void
    {
        $this->assertNull(StatusTimeline::height(null));
        $this->assertNull(StatusTimeline::height(100.0));
        $this->assertNull(StatusTimeline::height(99.995));

        $minute = StatusTimeline::height(100 - 100 / 1440);
        $hour = StatusTimeline::height(100 - 6000 / 1440);
        $day = StatusTimeline::height(0.0);

        $this->assertGreaterThan(34, $minute);
        $this->assertGreaterThan($minute, $hour);
        $this->assertSame(100, $day);
    }

    public function test_durations_read_like_a_person_would_say_them(): void
    {
        $this->assertSame('12 min', StatusTimeline::duration(12));
        $this->assertSame('1.5 h', StatusTimeline::duration(90));
        $this->assertSame('2 h', StatusTimeline::duration(120));
        $this->assertSame('3 d', StatusTimeline::duration(3 * 1440));
    }

    public function test_the_overall_row_takes_the_worst_of_each_day(): void
    {
        $bars = [
            1 => [['day' => '2026-11-14', 'tone' => 'ok', 'pct' => 100.0, 'known' => true], ['day' => '2026-11-15', 'tone' => 'unknown', 'pct' => 0.0, 'known' => false]],
            2 => [['day' => '2026-11-14', 'tone' => 'b', 'pct' => 90.0, 'known' => true], ['day' => '2026-11-15', 'tone' => 'w', 'pct' => 99.5, 'known' => true]],
        ];

        $overall = StatusTimeline::overall($bars);

        $this->assertSame(['day' => '2026-11-14', 'tone' => 'b', 'pct' => 90.0, 'known' => true], $overall[0]);
        $this->assertSame(['day' => '2026-11-15', 'tone' => 'w', 'pct' => 99.5, 'known' => true], $overall[1]);
    }

    public function test_messages_sit_on_the_strip_by_their_time(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-13 20:00:00'));
        $component = Component::create(['name' => 'Docs', 'status' => ComponentStatus::PerformanceIssues]);
        $incident = Incident::create(['name' => 'Docs is slow', 'status' => IncidentStatus::Identified, 'occurred_at' => now()->subMinutes(40)]);
        $incident->components()->attach($component->id, ['status' => ComponentStatus::PerformanceIssues->value]);
        IncidentUpdate::create(['incident_id' => $incident->id, 'status' => IncidentStatus::Investigating, 'message' => 'Looking.', 'created_at' => now()->subMinutes(40)]);
        IncidentUpdate::create(['incident_id' => $incident->id, 'status' => IncidentStatus::Identified, 'message' => 'Disk full.', 'created_at' => now()->subMinutes(20)]);

        $incident = $incident->fresh(['updates', 'components']);
        $strip = StatusTimeline::strip($incident, now());

        // Open: the line runs on to now, so the second message sits halfway.
        $this->assertSame([0.0, 0.5], $strip['points']);
        $this->assertTrue($strip['open']);
        $this->assertSame('w', StatusTimeline::tone($incident));
        $this->assertSame('40 min', StatusTimeline::duration(StatusTimeline::incidentMinutes($incident, now())));
    }

    public function test_the_page_shows_an_open_incident_as_the_headline_with_its_course(): void
    {
        $this->seedAdmin();
        $component = Component::create(['name' => 'Docs', 'status' => ComponentStatus::PerformanceIssues]);
        $incident = Incident::create(['name' => 'Docs is slow', 'status' => IncidentStatus::Identified, 'occurred_at' => now()->subMinutes(40)]);
        $incident->components()->attach($component->id, ['status' => ComponentStatus::PerformanceIssues->value]);
        IncidentUpdate::create(['incident_id' => $incident->id, 'status' => IncidentStatus::Investigating, 'message' => 'Looking.', 'created_at' => now()->subMinutes(40)]);
        IncidentUpdate::create(['incident_id' => $incident->id, 'status' => IncidentStatus::Identified, 'message' => 'Disk full.', 'created_at' => now()->subMinutes(20)]);

        $this->get('/')->assertOk()
            ->assertSee('<h1>Docs is slow</h1>', false)
            ->assertSee('Everything else works.')
            ->assertSee('class="dot cur beat"', false)
            ->assertSee('1 earlier message');
    }

    public function test_a_day_with_an_open_incident_is_not_called_quiet(): void
    {
        $this->seedAdmin();
        Setting::put('page.show_empty_days', '1');
        $incident = Incident::create(['name' => 'Fresh trouble', 'status' => IncidentStatus::Investigating, 'occurred_at' => now()]);
        IncidentUpdate::create(['incident_id' => $incident->id, 'status' => IncidentStatus::Investigating, 'message' => 'Looking.']);

        $this->get('/')->assertOk()
            ->assertSee('id="incident-'.$incident->id.'"', false)
            ->assertDontSee('class="entry quiet" data-day="'.now()->format('Y-m-d').'"', false);
    }

    public function test_an_open_incident_alone_does_not_offer_older_pages(): void
    {
        $this->seedAdmin();
        Incident::create(['name' => 'Long running', 'status' => IncidentStatus::Investigating, 'occurred_at' => now()->subDays(100)]);

        $this->get('/')->assertOk()->assertSee('Long running')->assertDontSee('Older incidents');
    }

    public function test_an_incident_from_an_earlier_day_names_its_date(): void
    {
        $this->seedAdmin();
        Carbon::setTestNow(Carbon::parse('2026-11-14 12:00:00'));
        $incident = Incident::create(['name' => 'Long running', 'status' => IncidentStatus::Investigating, 'occurred_at' => Carbon::parse('2026-11-01 22:03:00')]);
        IncidentUpdate::create(['incident_id' => $incident->id, 'status' => IncidentStatus::Investigating, 'message' => 'Check failed.', 'created_at' => Carbon::parse('2026-11-01 22:03:00')]);

        $this->get('/')->assertOk()->assertSee('Since 1 Nov 22:03')->assertSee('Latest message, 1 Nov 22:03');
    }

    private function seedAdmin(): void
    {
        User::factory()->create();
    }
}
