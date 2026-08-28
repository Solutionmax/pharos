<?php

namespace Tests\Feature;

use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\IncidentUpdate;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Storage is UTC, display is whatever zone the customer picked. Every test here
 * pins the clock, because "today" on the page depends on it.
 */
class TimezoneTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-28 12:00:00', 'UTC'));

        $this->user = User::create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('correct-horse-battery'),
        ]);
    }

    /** An incident with one update, both stamped at the given UTC moment. */
    private function incidentAt(string $utc, string $name = 'Mail queue backed up'): Incident
    {
        $moment = Carbon::parse($utc, 'UTC');

        $incident = Incident::create([
            'name' => $name,
            'status' => IncidentStatus::Investigating,
            'occurred_at' => $moment,
        ]);
        IncidentUpdate::create([
            'incident_id' => $incident->id,
            'status' => IncidentStatus::Investigating,
            'message' => 'Looking into it.',
            'created_at' => $moment,
        ]);

        return $incident;
    }

    public function test_an_update_stored_in_utc_is_shown_in_the_chosen_zone(): void
    {
        Setting::put('app.timezone', 'Europe/Amsterdam');
        $this->incidentAt('2026-08-28 08:24:00');

        $this->get('/')->assertOk()
            ->assertSee('<time>10:24</time>', false)
            ->assertDontSee('<time>08:24</time>', false);
    }

    public function test_the_day_group_follows_the_chosen_zone_around_midnight(): void
    {
        Setting::put('app.timezone', 'Europe/Amsterdam');
        // 22:30 UTC on the 27th is 00:30 on the 28th in Amsterdam.
        $this->incidentAt('2026-08-27 22:30:00', 'Late night outage');

        $html = $this->get('/')->assertOk()->getContent();

        $today = strpos($html, 'Today · 28 August');
        $incident = strpos($html, 'Late night outage');
        $yesterday = strpos($html, '27 August');

        $this->assertNotFalse($today);
        $this->assertNotFalse($yesterday);
        $this->assertTrue($today < $incident && $incident < $yesterday, 'the incident sits under 28 August, not 27');
        $this->assertStringContainsString('<time>00:30</time>', $html);
    }

    public function test_the_status_page_stamp_uses_the_chosen_zone(): void
    {
        Setting::put('app.timezone', 'Europe/Amsterdam');

        $this->get('/')->assertOk()->assertSee('updated 14:00');
    }

    public function test_a_local_time_typed_in_the_admin_form_is_stored_as_utc(): void
    {
        Setting::put('app.timezone', 'Europe/Amsterdam');

        $this->actingAs($this->user)->post('/admin/incidents', [
            'name' => 'Typed by hand',
            'message' => 'Started this morning.',
            'status' => 1,
            'impact' => 'minor',
            'visibility' => 'public',
            'occurred_at' => '2026-08-28T10:24',
        ])->assertRedirect();

        $this->assertDatabaseHas('incidents', ['name' => 'Typed by hand', 'occurred_at' => '2026-08-28 08:24:00']);
    }

    public function test_created_at_round_trips_through_the_cast_unchanged(): void
    {
        Setting::put('app.timezone', 'Europe/Amsterdam');
        $incident = $this->incidentAt('2026-08-28 08:24:00');

        $stored = DB::table('incidents')->where('id', $incident->id)->value('created_at');

        $this->assertSame('2026-08-28 12:00:00', $stored, 'freshTimestamp() is UTC and must land as UTC');
        $this->assertSame($stored, $incident->fresh()->created_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('14:00', $incident->fresh()->created_at->format('H:i'));
    }

    public function test_switching_the_zone_changes_the_display_but_not_the_stored_value(): void
    {
        $incident = $this->incidentAt('2026-08-28 08:24:00');
        $before = DB::table('incidents')->where('id', $incident->id)->first();

        $this->assertSame('08:24', $incident->fresh()->occurred_at->format('H:i'));

        Setting::put('app.timezone', 'Europe/Amsterdam');
        $incident->fresh()->save();

        $after = DB::table('incidents')->where('id', $incident->id)->first();

        $this->assertSame($before->occurred_at, $after->occurred_at);
        $this->assertSame($before->created_at, $after->created_at);
        $this->assertSame('10:24', $incident->fresh()->occurred_at->format('H:i'));
    }

    public function test_a_stamp_stays_utc_while_the_zone_is_utc(): void
    {
        $this->incidentAt('2026-08-28 08:24:00');

        $this->get('/')->assertOk()->assertSee('<time>08:24</time>', false);
    }
}
