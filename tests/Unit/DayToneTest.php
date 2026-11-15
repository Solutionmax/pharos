<?php

namespace Tests\Unit;

use App\Services\StatusTimeline;
use PHPUnit\Framework\TestCase;

/**
 * The one rule that colours a day, on the public page and in the admin alike:
 * minutes lost decide, an incident on the day sets the floor.
 */
class DayToneTest extends TestCase
{
    public function test_minutes_lost_decide_a_day_without_an_incident(): void
    {
        $this->assertSame('ok', StatusTimeline::dayTone(0));
        $this->assertSame('t', StatusTimeline::dayTone(0.2));
        $this->assertSame('t', StatusTimeline::dayTone(1));
        $this->assertSame('t', StatusTimeline::dayTone(4.99));
        $this->assertSame('p', StatusTimeline::dayTone(5));
        $this->assertSame('p', StatusTimeline::dayTone(14));
        $this->assertSame('p', StatusTimeline::dayTone(59.99));
        $this->assertSame('b', StatusTimeline::dayTone(60));
        $this->assertSame('b', StatusTimeline::dayTone(1440));
    }

    public function test_an_incident_on_the_day_sets_the_floor(): void
    {
        // Slow with nothing lost is slow; slow with a blip is still slow.
        $this->assertSame('w', StatusTimeline::dayTone(0, 'w'));
        $this->assertSame('w', StatusTimeline::dayTone(2, 'w'));
        // Slow that also lost a quarter of an hour shows what was lost.
        $this->assertSame('p', StatusTimeline::dayTone(15, 'w'));
        $this->assertSame('p', StatusTimeline::dayTone(0, 'p'));
        // A major outage reported for three minutes is an outage, not a blip.
        $this->assertSame('b', StatusTimeline::dayTone(3, 'b'));
        $this->assertSame('b', StatusTimeline::dayTone(90, 'p'));
    }

    public function test_maintenance_and_unknown_tones_leave_the_measurement_alone(): void
    {
        $this->assertSame('ok', StatusTimeline::dayTone(0, 'm'));
        $this->assertSame('t', StatusTimeline::dayTone(1, 'm'));
        $this->assertSame('ok', StatusTimeline::dayTone(0, 'ok'));
        $this->assertSame('t', StatusTimeline::dayTone(1, null));
    }

    public function test_the_worse_of_two_tones_wins(): void
    {
        $this->assertSame('t', StatusTimeline::worse('ok', 't'));
        $this->assertSame('w', StatusTimeline::worse('t', 'w'));
        $this->assertSame('b', StatusTimeline::worse('b', 'p'));
        $this->assertSame('ok', StatusTimeline::worse('ok', 'unknown'));
    }
}
