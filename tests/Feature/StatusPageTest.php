<?php

namespace Tests\Feature;

use App\Enums\ComponentStatus;
use App\Models\Component;
use App\Models\ComponentGroup;
use App\Models\Incident;
use App\Models\IncidentUpdate;
use App\Enums\IncidentStatus;
use App\Models\UptimeDay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class StatusPageTest extends TestCase
{
    use RefreshDatabase;

    protected function makeComponent(array $attributes = []): Component
    {
        $group = ComponentGroup::create(['name' => 'Shared hosting', 'position' => 1]);

        return Component::create(array_merge([
            'component_group_id' => $group->id,
            'name' => 'web-01',
            'status' => ComponentStatus::Operational,
        ], $attributes));
    }

    public function test_it_renders_groups_and_components(): void
    {
        $this->makeComponent(['description' => 'Availability of web-01']);

        $this->get('/')
            ->assertOk()
            ->assertSee('Shared hosting')
            ->assertSee('web-01')
            ->assertSee('All systems operational');
    }

    public function test_headline_reflects_the_worst_component(): void
    {
        $this->makeComponent(['status' => ComponentStatus::MajorOutage]);

        $this->get('/')
            ->assertOk()
            ->assertSee('Major outage')
            ->assertDontSee('All systems operational');
    }

    public function test_a_day_without_incidents_says_so(): void
    {
        $this->makeComponent();

        $this->get('/')->assertOk()->assertSee('No incidents');
    }

    public function test_it_shows_the_incident_timeline_newest_first(): void
    {
        $component = $this->makeComponent();

        $incident = Incident::create([
            'name' => 'Outbound email delayed',
            'status' => IncidentStatus::Watching,
            'occurred_at' => now(),
        ]);
        $incident->components()->attach($component->id, ['status' => ComponentStatus::PartialOutage->value]);

        IncidentUpdate::create([
            'incident_id' => $incident->id,
            'status' => IncidentStatus::Investigating,
            'message' => 'Queue length above threshold.',
            'automatic' => true,
            'created_at' => now()->subHour(),
        ]);
        IncidentUpdate::create([
            'incident_id' => $incident->id,
            'status' => IncidentStatus::Watching,
            'message' => 'The queue is draining.',
            'created_at' => now(),
        ]);

        $response = $this->get('/')->assertOk()
            ->assertSee('Outbound email delayed')
            ->assertSee('The queue is draining.')
            ->assertSee('automatic');

        $html = $response->getContent();
        $this->assertLessThan(
            strpos($html, 'Queue length above threshold.'),
            strpos($html, 'The queue is draining.'),
            'The newest update must come first in the timeline.',
        );
    }

    public function test_internal_incidents_are_never_shown(): void
    {
        $this->makeComponent();

        $incident = Incident::create([
            'name' => 'Internal note about a customer',
            'status' => IncidentStatus::Investigating,
            'visibility' => 'internal',
            'occurred_at' => now(),
        ]);
        IncidentUpdate::create([
            'incident_id' => $incident->id,
            'status' => IncidentStatus::Investigating,
            'message' => 'Do not publish this.',
        ]);

        $this->get('/')->assertOk()
            ->assertDontSee('Internal note about a customer')
            ->assertDontSee('Do not publish this.');
    }

    public function test_uptime_percentage_is_rendered_from_daily_rollups(): void
    {
        $component = $this->makeComponent();

        for ($i = 0; $i < 90; $i++) {
            UptimeDay::create([
                'component_id' => $component->id,
                'day' => Carbon::today()->subDays($i)->format('Y-m-d'),
                'up_seconds' => $i === 0 ? 43200 : 86400,
                'down_seconds' => $i === 0 ? 43200 : 0,
            ]);
        }

        // 89 perfect days plus one at 50% = 99.44%
        $this->get('/')->assertOk()->assertSee('99.44');
    }

    public function test_the_footer_credit_can_be_hidden(): void
    {
        $this->makeComponent();
        $this->get('/')->assertSee('Powered by Pharos');

        \App\Models\Setting::put('brand.credit_hidden', '1');

        $this->get('/')->assertDontSee('Powered by Pharos');
    }
}
