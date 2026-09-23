<?php

namespace Tests\Feature;

use App\Enums\ComponentStatus;
use App\Enums\IncidentStatus;
use App\Enums\UserRole;
use App\Models\ApiToken;
use App\Models\Component;
use App\Models\Incident;
use App\Models\IncidentTemplate;
use App\Models\IncidentUpdate;
use App\Models\StatusPage;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\PageContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncidentOperationsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => UserRole::Admin]);
    }

    public function test_resolve_closes_the_incident_restores_components_and_notifies(): void
    {
        $component = Component::create(['name' => 'Mail', 'status' => ComponentStatus::MajorOutage]);
        $incident = $this->incident('Mail down');
        $incident->components()->attach($component->id, ['status' => 4]);
        WebhookEndpoint::create(['label' => 'Ops', 'url' => 'https://203.0.113.10/a', 'format' => 'generic', 'enabled' => true]);

        $this->actingAs($this->admin)->post('/admin/incidents/'.$incident->id.'/resolve')->assertRedirect('/admin/incidents');

        $fresh = $incident->fresh();
        $this->assertSame(IncidentStatus::Resolved, $fresh->status);
        $this->assertNotNull($fresh->resolved_at);
        $this->assertSame(ComponentStatus::Operational, $component->fresh()->status);
        $this->assertSame('This incident has been resolved. Everything is working normally again.', IncidentUpdate::latest('id')->first()->message);
        $this->assertSame(['incident.resolved'], WebhookDelivery::pluck('event')->all());
    }

    public function test_resolve_takes_an_optional_message_and_refuses_viewers_and_other_pages(): void
    {
        $viewer = User::factory()->create(['role' => UserRole::User]);
        $viewer->statusPages()->attach(StatusPage::defaultId(), ['role' => 'viewer']);
        $incident = $this->incident('API slow');
        $other = StatusPage::create(['name' => 'Other', 'slug' => 'other']);
        $foreign = app(PageContext::class)->run($other->id, fn () => $this->incident('Foreign'));

        $this->actingAs($viewer)->post('/admin/incidents/'.$incident->id.'/resolve')->assertForbidden();
        $this->actingAs($this->admin)->post('/admin/incidents/'.$foreign->id.'/resolve')->assertNotFound();
        $this->post('/admin/incidents/'.$incident->id.'/resolve', ['message' => 'Fixed by a cache flush.'])->assertRedirect();
        $this->assertSame('Fixed by a cache flush.', IncidentUpdate::latest('id')->first()->message);
        $this->post('/admin/incidents/'.$incident->id.'/resolve', ['message' => str_repeat('x', 20001)])->assertSessionHasErrors('message');
    }

    public function test_the_list_shows_open_incidents_as_cards_above_the_history(): void
    {
        $this->incident('Mail queue backed up');
        Incident::create(['name' => 'web-06 unreachable', 'status' => IncidentStatus::Resolved, 'occurred_at' => now()->subDay(), 'resolved_at' => now()]);

        $html = $this->actingAs($this->admin)->get('/admin/incidents')->assertOk()
            ->assertSee('Open now')->assertSee('History')->assertSee('Post update')->assertSee('Resolve')->getContent();
        $this->assertLessThan(strpos($html, 'web-06 unreachable'), strpos($html, 'Mail queue backed up'));

        $this->get('/admin/incidents?state=resolved')->assertSee('web-06 unreachable')->assertDontSee('Mail queue backed up');
    }

    public function test_editors_manage_templates_on_their_own_page_only(): void
    {
        $editor = User::factory()->create(['role' => UserRole::User]);
        $editor->statusPages()->attach(StatusPage::defaultId(), ['role' => 'editor']);
        $viewer = User::factory()->create(['role' => UserRole::User]);
        $viewer->statusPages()->attach(StatusPage::defaultId(), ['role' => 'viewer']);
        $other = StatusPage::create(['name' => 'Other', 'slug' => 'other']);
        $foreign = app(PageContext::class)->run($other->id, fn () => IncidentTemplate::create(['name' => 'Foreign template', 'slug' => 'mail-down', 'title_template' => 'x', 'body_template' => 'y']));

        $this->actingAs($viewer)->get('/admin/incidents/templates')->assertForbidden();
        $this->post('/admin/incidents/templates', ['name' => 'Nope', 'title_template' => 'x', 'body_template' => 'y'])->assertForbidden();

        $this->actingAs($editor)->post('/admin/incidents/templates', [
            'name' => 'Mail down', 'title_template' => '{{component}} is not delivering mail', 'body_template' => 'We are looking into delays on {{component}}.',
        ])->assertRedirect('/admin/incidents/templates');
        $template = IncidentTemplate::sole();
        $this->assertSame('mail-down', $template->slug);
        $this->get('/admin/incidents/templates')->assertOk()->assertSee('Mail down')->assertDontSee('Foreign template');

        $this->put('/admin/incidents/templates/'.$template->id, ['name' => 'Mail down', 'title_template' => 'Mail delayed', 'body_template' => 'Delays.'])->assertRedirect();
        $this->assertSame('Mail delayed', $template->fresh()->title_template);
        $this->get('/admin/incidents/templates/'.$foreign->id.'/edit')->assertNotFound();
        $this->delete('/admin/incidents/templates/'.$foreign->id)->assertNotFound();

        $this->post('/admin/incidents/templates', ['name' => 'Mail down', 'title_template' => 'x', 'body_template' => 'y'])->assertRedirect();
        $this->assertSame(['mail-down', 'mail-down-2'], IncidentTemplate::orderBy('id')->pluck('slug')->all());

        $this->delete('/admin/incidents/templates/'.$template->id)->assertRedirect();
        $this->assertSame(1, IncidentTemplate::count());
        $this->assertSame(1, app(PageContext::class)->run($other->id, fn () => IncidentTemplate::count()));
    }

    public function test_the_report_form_offers_templates_and_the_api_uses_the_same_slug(): void
    {
        IncidentTemplate::create(['name' => 'Mail down', 'slug' => 'mail-down', 'title_template' => '{{component}} delayed', 'body_template' => 'Mail on {{component}} is delayed.']);
        $this->actingAs($this->admin)->get('/admin/incidents/create')->assertOk()
            ->assertSee('Use a template')->assertSee('data-template', false)->assertSee('Mail down');

        [, $token] = ApiToken::issue('n8n', $this->admin, StatusPage::defaultId(), 'write');
        $this->withToken($token)->postJson('/api/v1/incidents', ['template' => 'mail-down', 'vars' => ['component' => 'SMTP'], 'status' => 'investigating'])
            ->assertCreated()->assertJsonPath('data.name', 'SMTP delayed');
    }

    private function incident(string $name): Incident
    {
        return Incident::create(['name' => $name, 'status' => IncidentStatus::Investigating, 'impact' => 'major', 'visibility' => 'public', 'occurred_at' => now()]);
    }
}
