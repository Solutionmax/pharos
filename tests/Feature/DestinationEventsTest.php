<?php

namespace Tests\Feature;

use App\Enums\IncidentStatus;
use App\Enums\UserRole;
use App\Models\Incident;
use App\Models\StatusPage;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\OutgoingWebhook;
use App\Services\PageContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DestinationEventsTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_destinations_without_a_choice_receive_every_event(): void
    {
        $legacy = WebhookEndpoint::create(['label' => 'Legacy', 'url' => 'https://203.0.113.10/a', 'format' => 'generic', 'enabled' => true]);
        $this->assertNull($legacy->events);

        $incident = $this->incident();
        app(OutgoingWebhook::class)->incidentChanged($incident, 'incident.created');
        $incident->update(['status' => IncidentStatus::Identified]);
        app(OutgoingWebhook::class)->incidentChanged($incident->fresh('components'), 'incident.updated');
        $incident->update(['status' => IncidentStatus::Resolved, 'resolved_at' => now()]);
        app(OutgoingWebhook::class)->incidentChanged($incident->fresh('components'), 'incident.updated');

        $this->assertSame(['incident.opened', 'incident.updated', 'incident.resolved'], WebhookDelivery::orderBy('id')->pluck('event')->all());
    }

    public function test_a_destination_only_receives_the_events_it_chose(): void
    {
        WebhookEndpoint::create(['label' => 'Resolutions only', 'url' => 'https://203.0.113.10/b', 'format' => 'slack', 'enabled' => true, 'events' => ['incident.resolved']]);

        $incident = $this->incident();
        app(OutgoingWebhook::class)->incidentChanged($incident, 'incident.created');
        $this->assertSame(0, WebhookDelivery::count());

        $incident->update(['status' => IncidentStatus::Resolved, 'resolved_at' => now()]);
        app(OutgoingWebhook::class)->incidentChanged($incident->fresh('components'), 'incident.updated');
        $this->assertSame(['incident.resolved'], WebhookDelivery::pluck('event')->all());
    }

    public function test_the_form_stores_chosen_events_and_rejects_unknown_or_empty_choices(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $base = ['label' => 'Ops', 'url' => 'https://203.0.113.10/c', 'format' => 'generic', 'events_set' => '1'];

        $this->post('/admin/integrations/notifications', $base + ['events' => ['incident.opened', 'maintenance']])->assertRedirect();
        $this->assertSame(['incident.opened', 'maintenance'], WebhookEndpoint::sole()->events);

        $this->post('/admin/integrations/notifications', $base + ['events' => ['root']])->assertSessionHasErrors('events.0');
        $this->post('/admin/integrations/notifications', $base)->assertSessionHasErrors('events');
        $this->assertSame(1, WebhookEndpoint::count());
    }

    public function test_a_form_without_the_events_marker_keeps_the_old_all_events_behaviour(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $this->post('/admin/integrations/notifications', ['label' => 'Old client', 'url' => 'https://203.0.113.10/d', 'format' => 'generic'])->assertRedirect();
        $this->assertNull(WebhookEndpoint::sole()->events);
    }

    public function test_editors_change_events_of_their_own_pages_destination_only(): void
    {
        User::factory()->create(['role' => UserRole::Admin]);
        $editor = User::factory()->create(['role' => UserRole::User]);
        $viewer = User::factory()->create(['role' => UserRole::User]);
        $editor->statusPages()->attach(StatusPage::defaultId(), ['role' => 'editor']);
        $viewer->statusPages()->attach(StatusPage::defaultId(), ['role' => 'viewer']);
        $endpoint = WebhookEndpoint::create(['label' => 'Ops', 'url' => 'https://203.0.113.10/e', 'format' => 'generic', 'enabled' => true]);
        $other = StatusPage::create(['name' => 'Other', 'slug' => 'other']);
        $foreign = app(PageContext::class)->run($other->id, fn () => WebhookEndpoint::create(['label' => 'Foreign', 'url' => 'https://203.0.113.10/f', 'format' => 'generic', 'enabled' => true]));

        $this->actingAs($viewer)->put('/admin/integrations/notifications/'.$endpoint->id.'/events', ['events' => ['incident.opened']])->assertForbidden();
        $this->actingAs($editor)->put('/admin/integrations/notifications/'.$endpoint->id.'/events', ['events' => ['incident.opened', 'incident.resolved']])->assertRedirect();
        $this->assertSame(['incident.opened', 'incident.resolved'], $endpoint->fresh()->events);
        $this->put('/admin/integrations/notifications/'.$foreign->id.'/events', ['events' => ['incident.opened']])->assertNotFound();
        $this->put('/admin/integrations/notifications/'.$endpoint->id.'/events', ['events' => []])->assertSessionHasErrors('events');
    }

    private function incident(): Incident
    {
        return Incident::create(['name' => 'API down', 'status' => IncidentStatus::Investigating, 'impact' => 'minor', 'visibility' => 'public', 'occurred_at' => now()]);
    }
}
