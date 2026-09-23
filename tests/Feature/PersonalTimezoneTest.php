<?php

namespace Tests\Feature;

use App\Enums\IncidentStatus;
use App\Enums\UserRole;
use App\Mail\IncidentNoticeMail;
use App\Mail\MaintenanceNoticeMail;
use App\Models\AuditEntry;
use App\Models\Component;
use App\Models\Incident;
use App\Models\IncidentUpdate;
use App\Models\Maintenance;
use App\Models\Setting;
use App\Models\Subscriber;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\Clock;
use App\Services\DisplayZone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A personal zone changes what one admin reads and what that admin's typing
 * means, and nothing else: storage, public pages, the API, mails, webhooks
 * and every other user keep the installation zone.
 *
 * The clock is pinned to 12:00 UTC: 14:00 in Madrid (the installation), 21:00
 * in Tokyo (the admin).
 */
class PersonalTimezoneTest extends TestCase
{
    use RefreshDatabase;

    protected User $tokyo;

    protected User $plain;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-28 12:00:00', 'UTC'));
        Setting::put('app.timezone', 'Europe/Madrid');

        $this->tokyo = User::factory()->create(['role' => UserRole::Admin, 'email' => 'tokyo@example.com']);
        $this->tokyo->forceFill(['timezone' => 'Asia/Tokyo'])->save();
        $this->plain = User::factory()->create(['role' => UserRole::Admin, 'email' => 'plain@example.com']);
    }

    private function incidentAt(string $utc, string $name = 'Mail queue backed up'): Incident
    {
        $moment = Carbon::parse($utc, 'UTC');
        $incident = Incident::create(['name' => $name, 'status' => IncidentStatus::Investigating, 'occurred_at' => $moment]);
        IncidentUpdate::create([
            'incident_id' => $incident->id,
            'status' => IncidentStatus::Investigating,
            'message' => 'Looking into it.',
            'created_at' => $moment,
        ]);

        return $incident;
    }

    private function maintenanceAt(string $startUtc, string $endUtc): Maintenance
    {
        return Maintenance::create([
            'title' => 'Database upgrade',
            'starts_at' => Carbon::parse($startUtc, 'UTC'),
            'ends_at' => Carbon::parse($endUtc, 'UTC'),
            'announce_minutes' => 1440,
        ]);
    }

    private function incidentForm(string $occurredAt, string $name): array
    {
        return [
            'name' => $name,
            'message' => 'Started this morning.',
            'status' => 1,
            'impact' => 'minor',
            'visibility' => 'public',
            'occurred_at' => $occurredAt,
        ];
    }

    // (1) Display in the user's zone in the admin.

    public function test_admin_forms_show_stored_times_in_the_users_own_zone(): void
    {
        $maintenance = $this->maintenanceAt('2026-08-30 20:00:00', '2026-08-30 22:00:00');

        $this->actingAs($this->tokyo)->get("/admin/maintenance/{$maintenance->id}/edit")->assertOk()
            ->assertSee('value="2026-08-31T05:00"', false)
            ->assertDontSee('value="2026-08-30T22:00"', false)
            ->assertSee('Times shown in Asia/Tokyo (UTC+09:00), your own zone. Customers see Europe/Madrid.');
    }

    public function test_the_new_incident_form_prefills_now_in_the_users_zone_and_the_public_card_in_the_installation_zone(): void
    {
        $html = $this->actingAs($this->tokyo)->get('/admin/incidents/create')->assertOk()->getContent();

        $this->assertStringContainsString('value="2026-08-28T21:00"', $html);
        $this->assertStringContainsString('Times shown in Asia/Tokyo (UTC+09:00), your own zone.', $html);
        // The live preview is the customers' card: their zone, and it says so.
        $this->assertStringContainsString('<time>14:00</time>', $html);
        $this->assertStringContainsString('As the status page shows it, in Europe/Madrid', $html);
    }

    // (2) What a user types is the instant they meant.

    public function test_a_time_typed_in_tokyo_is_stored_as_the_same_instant_as_its_madrid_equivalent(): void
    {
        $this->actingAs($this->tokyo)->post('/admin/incidents', $this->incidentForm('2026-08-28T17:24', 'Typed in Tokyo'))->assertRedirect();
        $this->actingAs($this->plain)->post('/admin/incidents', $this->incidentForm('2026-08-28T10:24', 'Typed in Madrid'))->assertRedirect();

        $tokyo = DB::table('incidents')->where('name', 'Typed in Tokyo')->value('occurred_at');
        $madrid = DB::table('incidents')->where('name', 'Typed in Madrid')->value('occurred_at');

        $this->assertSame('2026-08-28 08:24:00', $tokyo);
        $this->assertSame($madrid, $tokyo);
    }

    public function test_a_maintenance_window_typed_in_tokyo_is_stored_as_utc_and_shown_back_as_typed(): void
    {
        $this->actingAs($this->tokyo)->post('/admin/maintenance', [
            'title' => 'Database upgrade',
            'starts_at' => '2026-08-31T05:00',
            'ends_at' => '2026-08-31T07:00',
            'announce_minutes' => 1440,
        ])->assertRedirect('/admin/maintenance');

        $row = DB::table('maintenances')->first();
        $this->assertSame('2026-08-30 20:00:00', $row->starts_at);
        $this->assertSame('2026-08-30 22:00:00', $row->ends_at);

        $this->get('/admin/maintenance/'.$row->id.'/edit')->assertOk()
            ->assertSee('value="2026-08-31T05:00"', false)
            ->assertSee('value="2026-08-31T07:00"', false);
    }

    // (3) Everything for other people keeps the installation zone.

    public function test_the_public_page_and_the_api_ignore_a_signed_in_admins_zone(): void
    {
        $this->incidentAt('2026-08-28 08:24:00');

        $this->actingAs($this->tokyo)->get('/')->assertOk()
            ->assertSee('<time>10:24</time>', false)
            ->assertDontSee('<time>17:24</time>', false)
            ->assertSee('checked 14:00');

        $json = $this->actingAs($this->tokyo)->getJson('/api/v1/incidents')->assertOk()->json('data.0');
        $this->assertSame('2026-08-28T10:24:00+02:00', $json['occurred_at']);
    }

    public function test_the_status_page_preview_inside_the_admin_uses_the_installation_zone(): void
    {
        $this->incidentAt('2026-08-28 08:24:00');

        $this->actingAs($this->tokyo)->get('/admin/status-page')->assertOk()
            ->assertSee('Times are in Europe/Madrid, the zone customers see.');

        $query = http_build_query(['m' => ['page.show_incidents' => '1', 'page.show_services' => '1']]);
        $this->get('/admin/status-page/preview?'.$query)->assertOk()
            ->assertSee('<time>10:24</time>', false)
            ->assertDontSee('<time>17:24</time>', false);
    }

    public function test_subscriber_mails_rendered_during_a_tokyo_request_use_the_installation_zone(): void
    {
        $update = $this->incidentAt('2026-08-28 08:24:00')->updates()->first();
        $maintenance = $this->maintenanceAt('2026-08-30 20:00:00', '2026-08-30 22:00:00');
        $subscriber = Subscriber::create(['email' => 'ann@example.net', 'token' => Subscriber::freshToken(), 'verified_at' => now()]);

        [$incidentHtml, $maintenanceHtml] = Clock::withZone('Asia/Tokyo', function () use ($update, $maintenance, $subscriber) {
            // Read once in Tokyo first: a cached cast would carry that zone into the mail.
            $this->assertSame('17:24', $update->created_at->format('H:i'));
            $this->assertSame('05:00', $maintenance->starts_at->format('H:i'));

            return [
                (new IncidentNoticeMail($update, $subscriber))->render(),
                (new MaintenanceNoticeMail($maintenance, $subscriber))->render(),
            ];
        });

        $this->assertStringContainsString('28 August 2026, 10:24', $incidentHtml);
        $this->assertStringNotContainsString('17:24', $incidentHtml);
        $this->assertStringContainsString('30 August 2026, 22:00', $maintenanceHtml);
        $this->assertStringNotContainsString('31 August 2026, 05:00', $maintenanceHtml);
    }

    public function test_mail_template_previews_use_the_installation_zone(): void
    {
        $this->actingAs($this->tokyo)->get('/admin/mail-templates?template=incident_updated')->assertOk()
            ->assertSee('Times are in Europe/Madrid, like the mails subscribers get.');

        $this->get('/admin/mail-templates/preview?template=incident_updated')->assertOk()
            ->assertSee('28 August 2026, 14:00')
            ->assertDontSee('28 August 2026, 21:00');
    }

    public function test_webhook_payloads_built_in_a_tokyo_request_use_the_installation_zone(): void
    {
        WebhookEndpoint::create(['label' => 'Generic', 'url' => 'https://203.0.113.10/a', 'format' => 'generic', 'enabled' => true]);
        WebhookEndpoint::create(['label' => 'Chat', 'url' => 'https://203.0.113.10/b', 'format' => 'slack', 'enabled' => true]);

        $this->actingAs($this->tokyo)->post('/admin/incidents', $this->incidentForm('2026-08-28T17:24', 'Typed in Tokyo'))->assertRedirect();
        $generic = WebhookDelivery::whereHas('endpoint', fn ($q) => $q->where('format', 'generic'))->sole();
        $this->assertSame('2026-08-28T10:24:00+02:00', $generic->payload['incident']['occurred_at']);

        // Cancelling an announced window sends "maintenance cancelled" with its times as text.
        $maintenance = $this->maintenanceAt('2026-08-30 20:00:00', '2026-08-30 22:00:00');
        $maintenance->forceFill(['announced_at' => now()])->save();
        $this->post("/admin/maintenance/{$maintenance->id}/cancel")->assertRedirect();

        $slack = WebhookDelivery::where('event', 'maintenance')
            ->whereHas('endpoint', fn ($q) => $q->where('format', 'slack'))->sole();
        $text = json_encode($slack->payload);
        $this->assertStringContainsString('30 Aug 22:00 to 31 Aug 00:00 CEST', $text);
        $this->assertStringNotContainsString('JST', $text);
    }

    public function test_an_audit_entry_written_in_a_tokyo_request_records_installation_time(): void
    {
        $maintenance = $this->maintenanceAt('2026-08-30 20:00:00', '2026-08-30 22:00:00');

        $this->actingAs($this->tokyo)->put("/admin/maintenance/{$maintenance->id}", [
            'title' => 'Database upgrade',
            'starts_at' => '2026-08-31T05:00',
            'ends_at' => '2026-08-31T08:00',
            'announce_minutes' => 1440,
        ])->assertRedirect();

        $changes = AuditEntry::where('action', 'like', 'maintenance.%')->latest('id')->firstOrFail()->changes;
        // 08:00 in Tokyo is 23:00 UTC is 01:00 in Madrid; the old end, 22:00 UTC, was midnight there.
        $this->assertSame('2026-08-30 23:00:00', DB::table('maintenances')->value('ends_at'));
        $this->assertSame('2026-08-31 00:00:00', $changes['ends_at']['from']);
        $this->assertSame('2026-08-31 01:00:00', $changes['ends_at']['to']);
    }

    public function test_csv_exports_use_and_name_the_downloading_users_zone(): void
    {
        $csv = $this->actingAs($this->tokyo)->get('/admin/audit/export')->assertOk()->streamedContent();
        $this->assertStringContainsString('"when (Asia/Tokyo)"', $csv);

        $csv = $this->actingAs($this->plain)->get('/admin/audit/export')->assertOk()->streamedContent();
        $this->assertStringContainsString('"when (Europe/Madrid)"', $csv);
    }

    // (4) Null falls back; an unknown zone is refused.

    public function test_a_user_without_a_zone_reads_the_installation_zone(): void
    {
        $maintenance = $this->maintenanceAt('2026-08-30 20:00:00', '2026-08-30 22:00:00');

        $this->actingAs($this->plain)->get("/admin/maintenance/{$maintenance->id}/edit")->assertOk()
            ->assertSee('value="2026-08-30T22:00"', false)
            ->assertSee('Times shown in Europe/Madrid (UTC+02:00).')
            ->assertDontSee('your own zone');
    }

    public function test_the_preference_is_saved_cleared_and_validated(): void
    {
        $this->actingAs($this->plain)->get('/admin/profile')->assertOk()
            ->assertSee('Installation default (Europe/Madrid)')
            ->assertSee('<option value="Asia/Tokyo" >', false);

        $this->put('/admin/profile/preferences', ['theme' => 'system', 'timezone' => 'Asia/Tokyo'])
            ->assertRedirect(route('admin.profile'))->assertSessionHasNoErrors();
        $this->assertSame('Asia/Tokyo', $this->plain->fresh()->timezone);

        $this->put('/admin/profile/preferences', ['theme' => 'system', 'timezone' => 'Mars/Olympus_Mons'])->assertSessionHasErrors('timezone');
        $this->put('/admin/profile/preferences', ['theme' => 'system', 'timezone' => ['Asia/Tokyo']])->assertSessionHasErrors('timezone');
        $this->assertSame('Asia/Tokyo', $this->plain->fresh()->timezone);

        // The theme alone (the older form) leaves the zone as it is.
        $this->put('/admin/profile/preferences', ['theme' => 'dark'])->assertSessionHasNoErrors();
        $this->assertSame('Asia/Tokyo', $this->plain->fresh()->timezone);

        $this->put('/admin/profile/preferences', ['theme' => 'system', 'timezone' => ''])->assertSessionHasNoErrors();
        $this->assertNull($this->plain->fresh()->timezone);
    }

    public function test_a_stored_zone_php_no_longer_knows_falls_back_instead_of_breaking(): void
    {
        $this->plain->forceFill(['timezone' => 'Atlantis/Lost'])->save();

        $this->actingAs($this->plain->fresh())->get('/admin/incidents/create')->assertOk()
            ->assertSee('value="2026-08-28T14:00"', false);
    }

    public function test_settings_keep_showing_the_installation_zone_to_an_admin_with_a_personal_zone(): void
    {
        $this->actingAs($this->tokyo)->get('/admin/settings')->assertOk()
            ->assertSee('<option value="Europe/Madrid" selected>', false)
            ->assertSee('Europe/Madrid, UTC+02:00 now.')
            ->assertSee('Each user can pick a zone of their own for the admin screens');
    }

    // (5) One user's zone never reaches another request or user.

    public function test_one_users_zone_does_not_leak_into_the_next_request(): void
    {
        $maintenance = $this->maintenanceAt('2026-08-30 20:00:00', '2026-08-30 22:00:00');
        $this->incidentAt('2026-08-28 08:24:00');

        $this->actingAs($this->tokyo)->get("/admin/maintenance/{$maintenance->id}/edit")->assertSee('value="2026-08-31T05:00"', false);
        $this->assertNull(app(DisplayZone::class)->personal(), 'the zone is put back once the request is done');
        $this->assertSame('Europe/Madrid', Clock::timezone());

        $this->actingAs($this->plain)->get("/admin/maintenance/{$maintenance->id}/edit")
            ->assertSee('value="2026-08-30T22:00"', false)
            ->assertDontSee('Asia/Tokyo');

        $auckland = User::factory()->create(['role' => UserRole::Admin]);
        $auckland->forceFill(['timezone' => 'Pacific/Auckland'])->save();
        $this->actingAs($auckland)->get("/admin/maintenance/{$maintenance->id}/edit")
            ->assertSee('value="2026-08-31T08:00"', false);

        $this->app['auth']->forgetGuards();
        $this->get('/')->assertOk()->assertSee('<time>10:24</time>', false);
        $this->assertSame('2026-08-30 22:00', $maintenance->fresh()->starts_at->format('Y-m-d H:i'));
    }

    public function test_the_zone_is_put_back_even_when_the_request_fails(): void
    {
        Component::create(['name' => 'Database']);

        $this->actingAs($this->tokyo)->post('/admin/maintenance', ['title' => ''])->assertSessionHasErrors();
        $this->assertNull(app(DisplayZone::class)->personal());
    }
}
