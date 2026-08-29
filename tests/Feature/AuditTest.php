<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\AuditEntry;
use App\Models\Component;
use App\Models\Incident;
use App\Models\Setting;
use App\Models\User;
use App\Services\Audit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AuditTest extends TestCase
{
    use RefreshDatabase;

    protected function admin(): User
    {
        return User::create([
            'name' => 'Anita',
            'email' => 'anita@example.net',
            'password' => 'secret-enough',
        ]);
    }

    public function test_it_records_who_added_and_removed_a_component(): void
    {
        $this->actingAs($this->admin());

        $this->post(route('admin.components.store'), [
            'name' => 'Mail server',
            'source' => 'manual',
            'status' => 1,
        ])->assertRedirect();

        $component = Component::where('name', 'Mail server')->firstOrFail();
        $this->delete(route('admin.components.destroy', $component))->assertRedirect();

        $actions = AuditEntry::pluck('action')->all();

        $this->assertContains('component.created', $actions);
        $this->assertContains('component.deleted', $actions);

        $entry = AuditEntry::where('action', 'component.deleted')->firstOrFail();
        $this->assertSame('Anita (anita@example.net)', $entry->actor);
        $this->assertSame('Mail server', $entry->subject_label);
    }

    public function test_an_update_records_the_old_and_the_new_value(): void
    {
        $this->actingAs($this->admin());
        $component = Component::create(['name' => 'Website']);

        $this->put(route('admin.components.update', $component), [
            'name' => 'Public website',
            'source' => 'manual',
            'status' => 1,
        ])->assertRedirect();

        $entry = AuditEntry::where('action', 'component.updated')->firstOrFail();

        $this->assertSame('Website', $entry->changes['name']['from']);
        $this->assertSame('Public website', $entry->changes['name']['to']);
    }

    public function test_the_check_runner_writes_nothing_because_nobody_made_it(): void
    {
        // No authenticated user and no API token: this is cron, and cron is not
        // an actor. Without this rule the table fills with a row a minute.
        Component::create(['name' => 'Database'])->update(['status' => 4]);

        $this->assertSame(0, AuditEntry::count());
    }

    public function test_an_api_token_is_named_as_the_actor(): void
    {
        [$token, $plain] = ApiToken::issue('n8n');
        $component = Component::create(['name' => 'API target']);

        $this->withHeader('Authorization', 'Bearer '.$plain)
            ->postJson('/api/v1/incidents', [
                'name' => 'Disk filling up',
                'status' => 'investigating',
                'message' => 'Looking into it.',
                'components' => [$component->id => 'partial_outage'],
            ])->assertCreated();

        $entry = AuditEntry::where('action', 'incident.created')->firstOrFail();

        $this->assertSame('API token: n8n', $entry->actor);
        $this->assertNull($entry->user_id);
    }

    public function test_a_secret_setting_records_the_change_but_not_the_value(): void
    {
        $this->actingAs($this->admin());

        Setting::put('integrations.webhook_secret', 'first-secret');
        Setting::put('integrations.webhook_secret', 'second-secret');

        $entry = AuditEntry::where('action', 'setting.updated')->firstOrFail();

        $this->assertSame('****', $entry->changes['value']['to']);
        $this->assertStringNotContainsString('second-secret', json_encode($entry->changes));
    }

    public function test_a_failed_login_is_recorded_without_the_password(): void
    {
        $this->admin();

        $this->post(route('admin.login.attempt'), [
            'email' => 'anita@example.net',
            'password' => 'wrong',
        ]);

        $entry = AuditEntry::where('action', 'auth.failed')->firstOrFail();

        $this->assertSame('anita@example.net', $entry->actor);
        $this->assertStringNotContainsString('wrong', json_encode($entry->toArray()));
    }

    public function test_the_audit_page_is_not_readable_without_signing_in(): void
    {
        $this->get(route('admin.audit'))->assertRedirect(route('admin.login'));

        $this->actingAs($this->admin())->get(route('admin.audit'))->assertOk()->assertSee('Audit log');
    }

    public function test_the_page_still_renders_once_there_is_more_than_one_page(): void
    {
        $user = $this->admin();

        for ($i = 0; $i < 60; $i++) {
            AuditEntry::create([
                'actor' => 'Anita (anita@example.net)',
                'action' => 'component.updated',
                'subject_label' => 'Component '.$i,
                'created_at' => now(),
            ]);
        }

        $this->actingAs($user)->get(route('admin.audit').'?page=2')->assertOk();
    }

    public function test_a_status_change_is_recorded_as_labels_on_both_sides(): void
    {
        $component = Component::create(['name' => 'Website']);
        $component->status = 4; // raw, as a form or the API hands it over

        $diff = Audit::diff($component);

        $this->assertSame('Operational', $diff['status']['from']);
        $this->assertSame('Major outage', $diff['status']['to']);
    }

    public function test_an_incident_update_is_labelled_by_its_incident(): void
    {
        $incident = Incident::create(['name' => 'Mail delayed', 'status' => 1, 'impact' => 'minor', 'occurred_at' => now()]);
        $update = $incident->updates()->create(['status' => 1, 'message' => 'Looking into it']);

        $this->assertSame('Update on "Mail delayed"', Audit::label($update));
    }

    public function test_the_audit_page_uses_its_own_pager_and_readable_field_names(): void
    {
        $user = $this->admin();

        for ($i = 0; $i < 60; $i++) {
            AuditEntry::create([
                'actor' => 'Anita (anita@example.net)',
                'action' => 'incident.updated',
                'subject_label' => 'Incident '.$i,
                'changes' => ['resolved_at' => ['from' => null, 'to' => '2026-08-27 22:56:05']],
                'created_at' => now(),
            ]);
        }

        $page = $this->actingAs($user)->get(route('admin.audit'))->assertOk();

        // Laravel's default pager is Tailwind markup; without Tailwind its SVG
        // arrows render at full size. Pharos ships its own.
        $page->assertSee('class="pager"', false)->assertDontSee('w-5 h-5', false)
            ->assertSee('Page 1 of 2')
            ->assertSee('Resolved at');
    }

    public function test_the_log_can_be_downloaded_as_csv_with_the_filter_applied(): void
    {
        $user = $this->admin();
        foreach (['Anita (anita@example.net)', 'API token: deploy'] as $actor) {
            AuditEntry::create([
                'actor' => $actor,
                'action' => 'component.updated',
                'subject_label' => 'Website',
                'changes' => ['status' => ['from' => 'Operational', 'to' => 'Major outage']],
                'ip' => '203.0.113.9',
                'created_at' => now(),
            ]);
        }

        $this->get(route('admin.audit.export'))->assertRedirect(route('admin.login'));

        $response = $this->actingAs($user)->get(route('admin.audit.export', ['actor' => 'deploy']));
        $response->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('attachment; filename=', $response->headers->get('content-disposition'));

        $csv = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBFwhen,actor,ip,action,subject,changes\n", $csv);
        $this->assertStringContainsString('"API token: deploy",203.0.113.9,component.updated,Website,"status: Operational → Major outage"', $csv);
        $this->assertStringNotContainsString('Anita', $csv, 'the filter applies to the download too');
    }

    public function test_the_csv_carries_the_chosen_zone_as_an_offset(): void
    {
        $user = $this->admin();
        Setting::put('app.timezone', 'Europe/Amsterdam');
        AuditEntry::create([
            'actor' => 'Anita (anita@example.net)',
            'action' => 'component.updated',
            'subject_label' => 'Website',
            'changes' => [],
            'ip' => '203.0.113.9',
            'created_at' => Carbon::parse('2026-08-28 08:24:00', 'UTC'),
        ]);

        $csv = $this->actingAs($user)->get(route('admin.audit.export'))->streamedContent();

        // Stored as 08:24 UTC; exported as the local wall time plus the offset,
        // so a spreadsheet reads it right and a script can still do the maths.
        $this->assertStringContainsString('2026-08-28T10:24:00+02:00,', $csv);
        $this->assertDatabaseHas('audit_log', ['created_at' => '2026-08-28 08:24:00']);
    }

    /** A backup line stores a plain name, not a from/to pair; it must not read as "— → —". */
    public function test_a_plain_value_change_is_shown_as_is_on_the_page_and_in_the_csv(): void
    {
        $user = $this->admin();
        AuditEntry::create(['actor' => 'Anita', 'action' => 'backup.created', 'changes' => ['name' => '1.0.0-20260828-120000', 'size' => '29.3 MB'], 'created_at' => now()]);

        $this->actingAs($user)->get(route('admin.audit'))->assertOk()
            ->assertSee('1.0.0-20260828-120000')->assertSee('29.3 MB')->assertDontSee('— → —');
        $csv = $this->actingAs($user)->get(route('admin.audit.export'))->streamedContent();
        $this->assertStringContainsString('name: 1.0.0-20260828-120000; size: 29.3 MB', $csv);
        $this->assertStringNotContainsString('— → —', $csv);
    }

    public function test_the_csv_starts_with_a_utf8_bom_so_excel_reads_it(): void
    {
        $csv = $this->actingAs($this->admin())->get(route('admin.audit.export'))->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
    }
}
