<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\Check;
use App\Models\Component;
use App\Models\User;
use App\Models\WebhookEndpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class IntegrationGuidesTest extends TestCase
{
    use RefreshDatabase;

    private function document(string $html): \DOMXPath
    {
        $document = new \DOMDocument;
        @$document->loadHTML($html);

        return new \DOMXPath($document);
    }

    public function test_each_destination_has_matching_server_rendered_examples(): void
    {
        $user = User::factory()->create();
        $this->assertSame(array_keys(WebhookEndpoint::FORMATS), array_keys(config('integrations.destinations')));
        foreach (config('integrations.destinations') as $format => $profile) {
            $html = $this->actingAs($user)->get(route('admin.integrations.out', ['destination' => $format]))->assertOk()->getContent();
            $xpath = $this->document($html);
            $this->assertSame($profile['name'], $xpath->evaluate('string(//input[@id="label"]/@placeholder)'));
            $this->assertSame($profile['title'], $xpath->evaluate('string(//*[@id="destination-title"])'));
            $this->assertSame($format === 'telegram', $xpath->query('//input[@id="url"]/@disabled')->length > 0);
            $this->assertSame($format !== 'signal', $xpath->query('//fieldset[@id="signal-fields"]/@disabled')->length > 0);
        }
    }

    public function test_setup_examples_use_only_eligible_components(): void
    {
        $user = User::factory()->create();
        $manual = Component::create(['name' => 'Manual service']);
        $automatic = Component::create(['name' => 'Checked service']);
        Check::create(['component_id' => $automatic->id, 'type' => 'http', 'target' => 'https://example.com', 'enabled' => true]);
        Component::create(['name' => 'Disabled service', 'enabled' => false]);
        $xpath = $this->document($this->actingAs($user)->get(route('admin.integrations.in'))->assertOk()->getContent());
        $this->assertSame(1, $xpath->query('//select[@id="integration-component"]/option')->length);
        $this->assertSame((string) $manual->id, $xpath->evaluate('string(//select[@id="integration-component"]/option/@value)'));
    }

    public function test_rendered_incident_example_is_accepted_by_the_api(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $component = Component::create(['name' => 'Example component']);
        $xpath = $this->document($this->actingAs($user)->get(route('admin.integrations.in'))->assertOk()->getContent());
        $payload = json_decode($xpath->evaluate('string(//*[@id="incident-example"])'), true, flags: JSON_THROW_ON_ERROR);
        [, $token] = ApiToken::issue('Guide test');
        $this->withHeader('Authorization', 'Bearer '.$token)->postJson('/api/v1/incidents', $payload)
            ->assertCreated()->assertJsonPath('data.name', 'Service unavailable');
        $this->assertSame(4, $component->fresh()->status->value);
    }

    public function test_no_eligible_component_uses_a_placeholder_instead_of_an_arbitrary_id(): void
    {
        $user = User::factory()->create();
        $xpath = $this->document($this->actingAs($user)->get(route('admin.integrations.in'))->assertOk()->getContent());
        $this->assertSame(1, $xpath->query('//select[@id="integration-component"]/@disabled')->length);
        $this->assertStringEndsWith('/integrations/kuma/COMPONENT_ID', $xpath->evaluate('string(//*[@id="kuma-url"])'));
    }
}
