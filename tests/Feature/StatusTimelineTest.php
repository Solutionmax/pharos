<?php

namespace Tests\Feature;

use App\Enums\ComponentStatus;
use App\Enums\IncidentStatus;
use App\Models\Component;
use App\Models\Incident;
use App\Models\IncidentUpdate;
use App\Models\Setting;
use App\Models\UptimeDay;
use App\Models\User;
use App\Services\StatusTimeline;
use App\Services\Uptime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class StatusTimelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_day_grows_with_the_time_it_lost(): void
    {
        $day = fn (string $tone, float $lost, bool $known = true) => ['tone' => $tone, 'lost' => $lost, 'known' => $known];

        $this->assertNull(StatusTimeline::height($day('unknown', 0, false)));
        $this->assertNull(StatusTimeline::height($day('ok', 0)));

        // A blip keeps one short height, below every real loss.
        $blip = StatusTimeline::height($day('t', 1));
        $this->assertSame($blip, StatusTimeline::height($day('t', 4.9)));
        $disruption = StatusTimeline::height($day('p', 6));
        $hour = StatusTimeline::height($day('b', 100));
        $whole = StatusTimeline::height($day('b', 1440));

        $this->assertLessThan(34, $blip);
        $this->assertGreaterThan(34, $disruption);
        $this->assertGreaterThan($disruption, $hour);
        $this->assertSame(100, $whole);
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
            1 => [['day' => '2026-11-14', 'tone' => 't', 'pct' => 99.93, 'known' => true, 'lost' => 1.0], ['day' => '2026-11-15', 'tone' => 'unknown', 'pct' => 0.0, 'known' => false, 'lost' => 0.0]],
            2 => [['day' => '2026-11-14', 'tone' => 'b', 'pct' => 90.0, 'known' => true, 'lost' => 144.0], ['day' => '2026-11-15', 'tone' => 'w', 'pct' => 99.5, 'known' => true, 'lost' => 7.2]],
        ];

        $overall = StatusTimeline::overall($bars);

        $this->assertSame(['day' => '2026-11-14', 'tone' => 'b', 'pct' => 90.0, 'known' => true, 'lost' => 144.0], $overall[0]);
        $this->assertSame(['day' => '2026-11-15', 'tone' => 'w', 'pct' => 99.5, 'known' => true, 'lost' => 7.2], $overall[1]);
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

    /** Review U1: one missed check is a blip, a quarter of an hour a disruption; the readout says which. */
    public function test_the_readout_names_blips_and_disruptions_by_their_minutes(): void
    {
        $this->seedAdmin();
        $docs = Component::create(['name' => 'Pharos docs']);
        $api = Component::create(['name' => 'API']);
        UptimeDay::create(['component_id' => $docs->id, 'day' => Carbon::today()->subDay(), 'up_seconds' => 86340, 'down_seconds' => 60]);
        UptimeDay::create(['component_id' => $api->id, 'day' => Carbon::today()->subDays(2), 'up_seconds' => 85680, 'down_seconds' => 720]);

        $body = $this->get('/')->assertOk()->getContent();
        $notes = $this->notes($body);

        $this->assertSame('A brief blip of about 1 min on Pharos docs. No incident.', $notes[Uptime::WINDOW_DAYS - 2]['text']);
        $this->assertSame('Disruption of 12 min on API. No incident.', $notes[Uptime::WINDOW_DAYS - 3]['text']);
        // A blip is calm and short; a disruption is orange and stands taller.
        $this->assertStringContainsString('<i class="t" style="--d:88;--h:30%"></i>', $body);
        $this->assertMatchesRegularExpression('/<i class="p" style="--d:87;--h:\d+%"><\/i>/', $body);
    }

    public function test_an_incident_colours_its_days_and_the_readout_names_services_not_titles(): void
    {
        $this->seedAdmin();
        $docs = Component::create(['name' => 'Pharos docs']);
        UptimeDay::create(['component_id' => $docs->id, 'day' => Carbon::today()->subDay(), 'up_seconds' => 86400, 'down_seconds' => 0]);
        $incident = Incident::create(['name' => 'Secret supplier trouble', 'status' => IncidentStatus::Resolved,
            'occurred_at' => Carbon::yesterday()->setTime(9, 0), 'resolved_at' => Carbon::yesterday()->setTime(9, 40)]);
        $incident->components()->attach($docs->id, ['status' => ComponentStatus::PerformanceIssues->value]);

        $body = $this->get('/')->assertOk()->getContent();
        $notes = $this->notes($body);

        // Nothing was lost, yet the day was reported slow: it is amber, not green.
        $this->assertStringContainsString('<i class="w" style="--d:88;--h:34%"></i>', $body);
        $this->assertSame('Pharos docs: degraded performance for 40 min.', $notes[Uptime::WINDOW_DAYS - 2]['text']);
        $this->assertStringNotContainsString('Secret supplier trouble', json_encode($notes));
    }

    /** @return list<array{label: string, text: string, day: string}> */
    private function notes(string $body): array
    {
        preg_match('#<script[^>]*data-notes[^>]*>(.*?)</script>#s', $body, $match);

        return json_decode($match[1], true);
    }
}
