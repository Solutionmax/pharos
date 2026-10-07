<?php

namespace Tests\Feature;

use App\Models\Component;
use App\Models\ComponentGroup;
use App\Models\Incident;
use App\Models\IncidentUpdate;
use App\Models\Maintenance;
use App\Models\StatusPage;
use App\Services\PageContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicFeaturesTest extends TestCase
{
    use RefreshDatabase;

    public function test_badges_escape_text_and_reject_hidden_foreign_and_unpublished_entities(): void
    {
        $component = Component::create(['name' => '<script>alert("x")</script>']);
        $response = $this->get('/badges/components/'.$component->id.'.svg')->assertOk();
        $this->assertStringContainsString('image/svg+xml', $response->headers->get('Content-Type'));
        $response->assertSee('&lt;script&gt;', false)->assertDontSee('<script>', false);
        $this->assertNotFalse(simplexml_load_string($response->getContent()));
        $group = ComponentGroup::create(['name' => 'Secret', 'visible' => false]);
        $component->update(['component_group_id' => $group->id]);
        $this->get('/badges/components/'.$component->id.'.svg')->assertNotFound();
        $this->get('/badges/groups/'.$group->id.'.svg')->assertNotFound();
        $page = StatusPage::create(['name' => 'Elsewhere', 'slug' => 'elsewhere', 'is_published' => true]);
        $foreign = app(PageContext::class)->run($page->id, fn () => Component::create(['name' => 'Foreign']));
        $this->get('/badges/components/'.$foreign->id.'.svg')->assertNotFound();
        $this->get('/status/elsewhere/badges/components/'.$foreign->id.'.svg')->assertOk();
        $page->update(['is_published' => false]);
        $this->get('/status/elsewhere/badges/components/'.$foreign->id.'.svg')->assertNotFound();
    }

    public function test_feed_is_valid_xml_with_public_incident_links_and_maintenance_only(): void
    {
        $incident = Incident::create(['name' => 'A & B <outage>', 'status' => 1, 'occurred_at' => now(), 'visibility' => 'public']);
        IncidentUpdate::create(['incident_id' => $incident->id, 'status' => 1, 'message' => 'Safe <script>bad</script>']);
        Incident::create(['name' => 'PRIVATE', 'status' => 1, 'occurred_at' => now(), 'visibility' => 'internal']);
        Maintenance::create(['title' => 'Maintenance & work', 'message' => 'Planned', 'starts_at' => now()->addHour(), 'ends_at' => now()->addHours(2)]);
        $response = $this->get('/feed.xml')->assertOk()->assertDontSee('PRIVATE');
        $xml = simplexml_load_string($response->getContent());
        $this->assertNotFalse($xml);
        $this->assertCount(2, $xml->channel->item);
        $response->assertSee('/incidents/'.$incident->id, false);
        $this->get('/incidents/'.$incident->id)->assertOk()->assertSee('A &amp; B', false)->assertDontSee('<script>', false);
        $this->get('/incidents/'.Incident::where('name', 'PRIVATE')->value('id'))->assertNotFound();
        $other = StatusPage::create(['name' => 'Other', 'slug' => 'other', 'is_published' => true]);
        $this->get('/status/other/incidents/'.$incident->id)->assertNotFound();
        $this->get('/status/other/feed.xml')->assertOk()->assertDontSee('outage');
    }

    public function test_widget_is_scoped_public_json_and_safe_dom_script(): void
    {
        Component::create(['name' => '<img src=x onerror=bad()>', 'status' => 3]);
        $group = ComponentGroup::create(['name' => 'Secret', 'visible' => false]);
        Component::create(['name' => 'HIDDEN', 'component_group_id' => $group->id, 'status' => 4]);
        $this->get('/widget.json')->assertOk()->assertHeader('Access-Control-Allow-Origin', '*')
            ->assertJsonPath('status', 3)->assertJsonMissing(['name' => 'HIDDEN']);
        $this->get('/embed.js')->assertOk()->assertSee('textContent', false)->assertDontSee('innerHTML', false)->assertSee('unavailable', false);
        StatusPage::default()->update(['is_published' => false]);
        $this->get('/widget.json')->assertNotFound();
        $this->get('/embed.js')->assertNotFound();
    }
}
