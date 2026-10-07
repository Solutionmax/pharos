<?php

namespace Tests\Feature;

use App\Models\Component;
use App\Models\Incident;
use App\Models\IncidentUpdate;
use App\Models\Maintenance;
use App\Models\StatusPage;
use App\Models\Subscriber;
use App\Models\SubscriberNotification;
use App\Services\MaintenanceNotifier;
use App\Services\PageContext;
use App\Services\SubscriberNotifier;
use App\Services\SubscriberPreferences;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ServiceSubscriptionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_selected_services_filter_incidents_and_maintenance_and_keep_legacy_all(): void
    {
        $a = Component::create(['name' => 'A']);
        $b = Component::create(['name' => 'B']);
        $legacy = Subscriber::create(['email' => 'all@example.com', 'token' => Subscriber::freshToken(), 'verified_at' => now()]);
        $selected = Subscriber::create(['email' => 'a@example.com', 'token' => Subscriber::freshToken(), 'verified_at' => now(), 'all_services' => false]);
        $selected->components()->sync([$a->id]);
        $incident = Incident::create(['name' => 'B down', 'status' => 1, 'occurred_at' => now()]);
        $incident->components()->attach($b->id, ['status' => 4]);
        $update = IncidentUpdate::create(['incident_id' => $incident->id, 'status' => 1, 'message' => 'B down']);
        $this->assertEquals([$legacy->id], SubscriberNotification::where('incident_update_id', $update->id)->pluck('subscriber_id')->all());
        $incident->components()->sync([$a->id => ['status' => 4]]);
        $next = IncidentUpdate::create(['incident_id' => $incident->id, 'status' => 2, 'message' => 'A affected too']);
        $this->assertSame(2, SubscriberNotification::where('incident_update_id', $next->id)->count());
        $incident->components()->sync([$b->id => ['status' => 1]]);
        $resolved = IncidentUpdate::create(['incident_id' => $incident->id, 'status' => 4, 'message' => 'Resolved']);
        $this->assertSame(2, SubscriberNotification::where('incident_update_id', $resolved->id)->count());
        $maintenance = Maintenance::create(['title' => 'B work', 'starts_at' => now()->addHour(), 'ends_at' => now()->addHours(2)]);
        $maintenance->components()->attach($b->id);
        $this->assertSame(1, app(MaintenanceNotifier::class)->queue($maintenance));
    }

    public function test_signed_preferences_require_current_token_and_scoped_public_components(): void
    {
        $a = Component::create(['name' => 'A']);
        $subscriber = Subscriber::create(['email' => 'a@example.com', 'token' => Subscriber::freshToken(), 'verified_at' => now()]);
        $url = $subscriber->preferencesUrl();
        $this->get($url)->assertOk()->assertSee('A');
        $this->post($url, ['all_services' => false, 'component_ids' => [$a->id]])->assertRedirect();
        $this->assertFalse($subscriber->fresh()->all_services);
        $this->assertEquals([$a->id], $subscriber->components()->pluck('components.id')->all());
        $page = StatusPage::create(['name' => 'Other', 'slug' => 'other', 'is_published' => true]);
        $foreign = app(PageContext::class)->run($page->id, fn () => Component::create(['name' => 'Foreign']));
        $this->post($url, ['all_services' => false, 'component_ids' => [$foreign->id]])->assertSessionHasErrors('component_ids.0');
        $this->post($url, ['all_services' => false, 'component_ids' => []])->assertSessionHasErrors('component_ids');
        $subscriber->update(['token' => Subscriber::freshToken()]);
        $this->get($url)->assertForbidden();
        $this->get('/subscribe/preferences/'.$subscriber->id)->assertForbidden();
    }

    public function test_preferences_changed_after_queueing_skip_irrelevant_mail(): void
    {
        Mail::fake();
        $a = Component::create(['name' => 'A']);
        $b = Component::create(['name' => 'B']);
        $subscriber = Subscriber::create(['email' => 'pending@example.com', 'token' => Subscriber::freshToken(), 'verified_at' => now()]);
        $incident = Incident::create(['name' => 'A down', 'status' => 1, 'occurred_at' => now()]);
        $incident->components()->attach($a->id, ['status' => 4]);
        IncidentUpdate::create(['incident_id' => $incident->id, 'status' => 1, 'message' => 'A down']);
        SubscriberPreferences::save($subscriber, ['all_services' => false, 'component_ids' => [$b->id]]);
        app(SubscriberNotifier::class)->sendPending();
        Mail::assertNothingSent();
        $resolved = IncidentUpdate::create(['incident_id' => $incident->id, 'status' => 4, 'message' => 'Resolved']);
        $this->assertSame(0, SubscriberNotification::where('incident_update_id', $resolved->id)->count());
    }

    public function test_guessed_email_cannot_change_an_active_subscribers_preferences(): void
    {
        $a = Component::create(['name' => 'A']);
        $subscriber = Subscriber::create(['email' => 'victim@example.com', 'token' => Subscriber::freshToken(), 'verified_at' => now()]);
        $token = $subscriber->token;
        $this->post('/subscribe', ['email' => 'victim@example.com', 'all_services' => false, 'component_ids' => [$a->id]])->assertRedirect();
        $this->assertTrue($subscriber->fresh()->all_services);
        $this->assertSame($token, $subscriber->fresh()->token);
        $this->assertSame(0, $subscriber->components()->count());
    }

    public function test_signup_rejects_cross_page_choices_before_sending_mail(): void
    {
        $page = StatusPage::create(['name' => 'Other', 'slug' => 'other', 'is_published' => true]);
        $foreign = app(PageContext::class)->run($page->id, fn () => Component::create(['name' => 'Foreign']));
        $this->post('/subscribe', ['email' => 'new@example.com', 'all_services' => false, 'component_ids' => [$foreign->id]])->assertSessionHasErrors('component_ids.0');
        $this->assertDatabaseCount('subscribers', 0);
    }
}
