<?php

namespace Tests\Feature;

use App\Enums\CheckType;
use App\Enums\ComponentStatus;
use App\Enums\UserRole;
use App\Mail\MaintenanceNoticeMail;
use App\Models\Check;
use App\Models\Component;
use App\Models\Incident;
use App\Models\Maintenance;
use App\Models\MaintenanceNotification;
use App\Models\StatusPage;
use App\Models\Subscriber;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\CheckRunner;
use App\Services\MaintenanceScheduler;
use App\Services\PageContext;
use App\Services\Probe;
use App\Services\ProbeResult;
use App\Services\Subscriptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MaintenanceTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $start;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-01 08:00:00', 'UTC'));
        $this->start = Carbon::parse('2026-10-02 22:00:00', 'UTC');
    }

    public function test_editors_schedule_maintenance_for_their_page_and_viewers_only_read(): void
    {
        User::factory()->create(['role' => UserRole::Admin]);
        $editor = $this->member('editor');
        $viewer = $this->member('viewer');
        $db = Component::create(['name' => 'Database']);

        $this->actingAs($viewer)->get('/admin/maintenance')->assertOk()->assertDontSee('Schedule maintenance</a>', false);
        $this->post('/admin/maintenance', $this->form([$db->id]))->assertForbidden();
        $this->get('/admin/maintenance/create')->assertForbidden();

        $this->actingAs($editor)->get('/admin/maintenance/create')->assertOk()->assertSee('Database');
        $this->post('/admin/maintenance', $this->form([$db->id]))->assertRedirect('/admin/maintenance');
        $maintenance = Maintenance::sole();
        $this->assertSame('Database upgrade', $maintenance->title);
        $this->assertSame([$db->id], $maintenance->components->pluck('id')->all());
        $this->assertSame(1440, $maintenance->announce_minutes);
        $this->assertTrue($maintenance->starts_at->utc()->equalTo($this->start));
        $this->get('/admin/maintenance')->assertOk()->assertSee('Database upgrade')->assertSee('Scheduled');
    }

    public function test_input_is_validated_and_components_of_another_page_are_refused(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $other = StatusPage::create(['name' => 'Other', 'slug' => 'other']);
        $foreign = app(PageContext::class)->run($other->id, fn () => Component::create(['name' => 'Foreign']));

        $this->post('/admin/maintenance', $this->form([$foreign->id]))->assertSessionHasErrors('components.0');
        $this->post('/admin/maintenance', ['ends_at' => '2026-10-02 21:00'] + $this->form([]))->assertSessionHasErrors('ends_at');
        $this->post('/admin/maintenance', ['announce_minutes' => 7] + $this->form([]))->assertSessionHasErrors('announce_minutes');
        $this->post('/admin/maintenance', ['title' => ''] + $this->form([]))->assertSessionHasErrors('title');
        $this->assertSame(0, Maintenance::count());
    }

    public function test_maintenance_of_another_page_cannot_be_opened_or_cancelled(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $other = StatusPage::create(['name' => 'Other', 'slug' => 'other']);
        $foreign = app(PageContext::class)->run($other->id, fn () => $this->maintenance([], 'Foreign window'));

        $this->get('/admin/maintenance')->assertDontSee('Foreign window');
        $this->get('/admin/maintenance/'.$foreign->id.'/edit')->assertNotFound();
        $this->post('/admin/maintenance/'.$foreign->id.'/cancel')->assertNotFound();
        $this->get('/admin/pages/'.$other->id.'/maintenance')->assertOk()->assertSee('Foreign window');
    }

    public function test_announcement_waits_for_the_lead_time_and_is_sent_once(): void
    {
        $subscriber = Subscriber::create(['email' => 'a@example.test', 'token' => Subscriber::freshToken(), 'verified_at' => now()]);
        Subscriber::create(['email' => 'pending@example.test', 'token' => Subscriber::freshToken()]);
        WebhookEndpoint::create(['label' => 'All', 'url' => 'https://203.0.113.10/a', 'format' => 'generic', 'enabled' => true]);
        WebhookEndpoint::create(['label' => 'Incidents only', 'url' => 'https://203.0.113.10/b', 'format' => 'generic', 'enabled' => true, 'events' => ['incident.opened']]);
        $maintenance = $this->maintenance([]);

        $this->travelTo($this->start->copy()->subHours(25));
        $this->scheduler();
        $this->assertNull($maintenance->fresh()->announced_at);

        $this->travelTo($this->start->copy()->subHours(23));
        $this->scheduler();
        $this->scheduler();
        $this->assertNotNull($maintenance->fresh()->announced_at);
        $this->assertSame([$subscriber->id], MaintenanceNotification::pluck('subscriber_id')->all());
        $this->assertSame(['maintenance'], WebhookDelivery::pluck('event')->all());
        $this->assertSame('maintenance.scheduled', WebhookDelivery::sole()->payload['event']);
    }

    public function test_no_subscriber_mail_when_subscriptions_are_off_the_page_is_unpublished_or_no_announcement_is_wanted(): void
    {
        Subscriber::create(['email' => 'a@example.test', 'token' => Subscriber::freshToken(), 'verified_at' => now()]);
        Subscriptions::set(false);
        $off = $this->maintenance([]);
        $silent = $this->maintenance([], 'Quiet window', ['announce_minutes' => 0]);
        $draft = StatusPage::create(['name' => 'Draft', 'slug' => 'draft', 'is_published' => false]);
        app(PageContext::class)->run($draft->id, function () {
            Subscriber::create(['email' => 'b@example.test', 'token' => Subscriber::freshToken(), 'verified_at' => now()]);
            $this->maintenance([], 'Draft window');
        });

        $this->travelTo($this->start->copy()->subHours(1));
        $this->scheduler();

        $this->assertNotNull($off->fresh()->announced_at);
        $this->assertNull($silent->fresh()->announced_at);
        $this->assertSame(0, MaintenanceNotification::withoutGlobalScopes()->count());
    }

    public function test_start_sets_components_under_maintenance_and_end_restores_them(): void
    {
        $degraded = Component::create(['name' => 'Database', 'status' => ComponentStatus::PerformanceIssues]);
        $fine = Component::create(['name' => 'API']);
        $untouched = Component::create(['name' => 'Web']);
        $maintenance = $this->maintenance([$degraded->id, $fine->id]);

        $this->travelTo($this->start->copy()->addMinute());
        $this->scheduler();
        $this->scheduler();

        $this->assertSame(ComponentStatus::UnderMaintenance, $degraded->fresh()->status);
        $this->assertSame(ComponentStatus::UnderMaintenance, $fine->fresh()->status);
        $this->assertSame(ComponentStatus::Operational, $untouched->fresh()->status);
        $this->assertNotNull($maintenance->fresh()->started_at);
        $this->assertSame(2, (int) $maintenance->components()->where('component_id', $degraded->id)->first()->pivot->previous_status);

        // Someone fixed the API by hand during the window: that choice stands.
        $fine->update(['status' => ComponentStatus::MajorOutage]);

        $this->travelTo($this->start->copy()->addHours(3));
        $this->scheduler();
        $this->scheduler();

        $this->assertNotNull($maintenance->fresh()->completed_at);
        $this->assertSame(ComponentStatus::PerformanceIssues, $degraded->fresh()->status);
        $this->assertSame(ComponentStatus::MajorOutage, $fine->fresh()->status);
    }

    public function test_overlapping_windows_restore_the_status_from_before_the_first(): void
    {
        $component = Component::create(['name' => 'Database', 'status' => ComponentStatus::PerformanceIssues]);
        $first = $this->maintenance([$component->id]);
        $second = $this->maintenance([$component->id], 'Second window', ['starts_at' => $this->start->copy()->addHour(), 'ends_at' => $this->start->copy()->addHours(4)]);

        $this->travelTo($this->start->copy()->addMinute());
        $this->scheduler();
        $this->travelTo($this->start->copy()->addHours(1)->addMinute());
        $this->scheduler();
        $this->travelTo($this->start->copy()->addHours(2)->addMinute());
        $this->scheduler();
        $this->assertNotNull($first->fresh()->completed_at);
        $this->assertSame(ComponentStatus::UnderMaintenance, $component->fresh()->status);

        $this->travelTo($this->start->copy()->addHours(4)->addMinute());
        $this->scheduler();
        $this->assertNotNull($second->fresh()->completed_at);
        $this->assertSame(ComponentStatus::PerformanceIssues, $component->fresh()->status);
    }

    public function test_a_late_scheduler_never_starts_a_window_that_is_already_over(): void
    {
        $component = Component::create(['name' => 'Database']);
        $maintenance = $this->maintenance([$component->id]);

        $this->travelTo($this->start->copy()->addHours(5));
        $this->scheduler();

        $fresh = $maintenance->fresh();
        $this->assertNotNull($fresh->completed_at);
        $this->assertNull($fresh->announced_at);
        $this->assertSame(ComponentStatus::Operational, $component->fresh()->status);
        $this->assertSame(0, MaintenanceNotification::count());
    }

    public function test_cancel_stops_everything_and_restores_a_window_in_progress(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $component = Component::create(['name' => 'Database']);
        $running = $this->maintenance([$component->id]);
        $later = $this->maintenance([$component->id], 'Later window', ['starts_at' => $this->start->copy()->addDay(), 'ends_at' => $this->start->copy()->addDay()->addHour(), 'announce_minutes' => 60]);

        $this->travelTo($this->start->copy()->addMinute());
        $this->scheduler();
        $this->assertSame(ComponentStatus::UnderMaintenance, $component->fresh()->status);

        $this->post('/admin/maintenance/'.$running->id.'/cancel')->assertRedirect('/admin/maintenance');
        $this->post('/admin/maintenance/'.$later->id.'/cancel')->assertRedirect('/admin/maintenance');
        $this->assertSame(ComponentStatus::Operational, $component->fresh()->status);
        $this->assertNotNull($running->fresh()->cancelled_at);

        $this->travelTo($this->start->copy()->addDay()->addMinutes(5));
        $this->scheduler();
        $this->assertSame(ComponentStatus::Operational, $component->fresh()->status);
        $this->assertNull($later->fresh()->started_at);
        $this->assertNull($later->fresh()->announced_at);

        $this->post('/admin/maintenance/'.$later->id.'/cancel')->assertSessionHasErrors();
    }

    public function test_the_public_page_shows_upcoming_and_ongoing_maintenance_of_its_own_page_only(): void
    {
        User::factory()->create(['role' => UserRole::Admin]);
        $this->maintenance([], 'Own planned window');
        $other = StatusPage::create(['name' => 'Other', 'slug' => 'other', 'is_published' => true]);
        app(PageContext::class)->run($other->id, fn () => $this->maintenance([], 'Other planned window'));
        $this->maintenance([], 'Cancelled window', ['cancelled_at' => now()]);

        $this->get('/')->assertOk()->assertSee('Own planned window')->assertSee('Scheduled maintenance')
            ->assertDontSee('Other planned window')->assertDontSee('Cancelled window');
        $this->get('/status/other')->assertOk()->assertSee('Other planned window')->assertDontSee('Own planned window');

        $this->travelTo($this->start->copy()->addMinute());
        $this->get('/')->assertSee('Maintenance in progress');
        $this->travelTo($this->start->copy()->addHours(4));
        $this->get('/')->assertDontSee('Own planned window');
    }

    public function test_every_page_is_scheduled_in_its_own_context_and_archived_pages_are_skipped(): void
    {
        $other = StatusPage::create(['name' => 'Other', 'slug' => 'other', 'is_published' => true]);
        [$foreignComponent, $foreign] = app(PageContext::class)->run($other->id, function () {
            $component = Component::create(['name' => 'Foreign database']);

            return [$component, $this->maintenance([$component->id])];
        });
        $archived = StatusPage::create(['name' => 'Old', 'slug' => 'old', 'is_published' => true]);
        $archivedWindow = app(PageContext::class)->run($archived->id, fn () => $this->maintenance([]));
        $archived->forceFill(['archived_at' => now()])->save();

        $this->travelTo($this->start->copy()->addMinute());
        $this->artisan('pharos:maintenance')->assertSuccessful();

        app(PageContext::class)->run($other->id, function () use ($foreign, $foreignComponent) {
            $this->assertNotNull($foreign->fresh()->started_at);
            $this->assertSame(ComponentStatus::UnderMaintenance, $foreignComponent->fresh()->status);
        });
        $this->assertNull(Maintenance::withoutGlobalScopes()->find($archivedWindow->id)->started_at);
    }

    public function test_a_failing_check_does_not_open_an_outage_during_maintenance(): void
    {
        $component = Component::create(['name' => 'Web']);
        $check = Check::create(['component_id' => $component->id, 'type' => CheckType::Http, 'target' => 'https://example.test', 'retries' => 1, 'enabled' => true]);
        $this->maintenance([$component->id]);
        $this->travelTo($this->start->copy()->addMinute());
        $this->scheduler();

        $this->app->instance(Probe::class, new class extends Probe
        {
            public function run(Check $check): ProbeResult
            {
                return new ProbeResult(false, 5, 'down');
            }
        });
        app(CheckRunner::class)->runOne($check->fresh());

        $this->assertSame(ComponentStatus::UnderMaintenance, $component->fresh()->status);
        $this->assertSame(0, Incident::count());
    }

    public function test_notify_sends_the_announcement_mail(): void
    {
        Mail::fake();
        Subscriber::create(['email' => 'a@example.test', 'token' => Subscriber::freshToken(), 'verified_at' => now()]);
        $this->maintenance([Component::create(['name' => 'Database'])->id]);
        $this->travelTo($this->start->copy()->subHours(2));
        $this->scheduler();

        $this->artisan('pharos:notify')->assertSuccessful();

        Mail::assertSent(MaintenanceNoticeMail::class, function (MaintenanceNoticeMail $mail) {
            $html = $mail->render();

            return $mail->hasTo('a@example.test') && str_contains($html, 'Database upgrade') && str_contains($html, 'Database')
                && str_contains($html, '2 October 2026, 22:00') && $mail->envelope()->subject === '[Pharos] Scheduled maintenance: Database upgrade';
        });
        $this->assertNotNull(MaintenanceNotification::sole()->sent_at);
        $this->artisan('pharos:notify')->assertSuccessful();
        Mail::assertSent(MaintenanceNoticeMail::class, 1);
    }

    /** @param list<int> $components */
    private function form(array $components): array
    {
        return [
            'title' => 'Database upgrade',
            'message' => 'We upgrade the database. Expect short interruptions.',
            'starts_at' => '2026-10-02 22:00',
            'ends_at' => '2026-10-03 00:00',
            'announce_minutes' => 1440,
            'components' => $components,
        ];
    }

    /** @param list<int> $components */
    private function maintenance(array $components, string $title = 'Database upgrade', array $extra = []): Maintenance
    {
        $maintenance = Maintenance::create($extra + [
            'title' => $title,
            'message' => 'Planned work.',
            'starts_at' => $this->start,
            'ends_at' => $this->start->copy()->addHours(2),
            'announce_minutes' => 1440,
        ]);
        $maintenance->components()->sync($components);

        return $maintenance;
    }

    private function member(string $role): User
    {
        $user = User::factory()->create(['role' => UserRole::User]);
        $user->statusPages()->attach(StatusPage::defaultId(), ['role' => $role]);

        return $user;
    }

    private function scheduler(): void
    {
        app(MaintenanceScheduler::class)->run();
    }
}
