<?php

namespace Tests\Feature;

use App\Models\Component;
use App\Models\Incident;
use App\Models\IncidentUpdate;
use App\Models\StatusPage;
use App\Services\PageContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class IncidentPageTest extends TestCase
{
    use RefreshDatabase;

    private function incident(string $start, ?string $end, array $messages): Incident
    {
        $incident = Incident::create(['name' => 'Website down', 'status' => $end ? 4 : 1, 'occurred_at' => $start, 'resolved_at' => $end]);
        $incident->components()->attach(Component::create(['name' => 'Website'])->id, ['status' => 4]);
        foreach ($messages as [$status, $at, $text]) {
            IncidentUpdate::create(['incident_id' => $incident->id, 'status' => $status, 'message' => $text, 'created_at' => $at]);
        }

        return $incident;
    }

    public function test_a_resolved_incident_reads_like_the_status_page_with_every_message_newest_first(): void
    {
        Carbon::setTestNow('2026-11-02 09:00:00');
        $incident = $this->incident('2026-10-28 18:40:00', '2026-10-28 18:44:00', [
            [1, '2026-10-28 18:40:00', 'We are looking into it.'],
            [2, '2026-10-28 18:42:00', 'A disk filled up.'],
            [4, '2026-10-28 18:44:00', 'Space freed, all good.'],
        ]);

        $page = $this->get('/incidents/'.$incident->id)->assertOk()
            ->assertSee('← Status page', false)
            ->assertSee('Resolved</b> · 28 Oct, 18:40 to 18:44 · 4 min · Affected Website', false)
            ->assertSeeInOrder(['Space freed, all good.', 'A disk filled up.', 'We are looking into it.'])
            ->assertDontSee('2026-10-28 18:40')
            ->assertDontSee('document-card');
        $this->assertSame(3, substr_count($page->getContent(), 'class="dot'));
        $this->assertStringContainsString('data-theme-toggle', $page->getContent());
    }

    public function test_an_open_incident_says_since_when_and_dates_messages_of_another_day(): void
    {
        Carbon::setTestNow('2026-11-02 18:52:00');
        $incident = $this->incident('2026-11-02 18:40:00', null, [[1, '2026-11-02 18:40:00', 'Looking.']]);
        $this->get('/incidents/'.$incident->id)->assertOk()
            ->assertSee('Ongoing since 18:40</b> · 12 min · Affects Website', false);

        Carbon::setTestNow('2026-11-03 09:00:00');
        IncidentUpdate::create(['incident_id' => $incident->id, 'status' => 2, 'message' => 'Found it.', 'created_at' => '2026-11-03 08:30:00']);
        $this->get('/incidents/'.$incident->id)->assertOk()
            ->assertSee('Ongoing since 2 Nov, 18:40')
            ->assertSee('3 Nov 08:30')
            ->assertSee('>18:40</time>', false);
    }

    public function test_the_page_route_shows_the_incident_of_that_page(): void
    {
        $other = StatusPage::create(['name' => 'Other', 'slug' => 'other', 'is_published' => true]);
        $incident = app(PageContext::class)->run($other->id, fn () => $this->incident('2026-10-28 18:40:00', '2026-10-28 18:44:00', [[4, '2026-10-28 18:44:00', 'Fixed.']]));

        $this->get('/status/other/incidents/'.$incident->id)->assertOk()->assertSee('Fixed.')->assertSee('Other');
        $this->get('/incidents/'.$incident->id)->assertNotFound();
    }
}
