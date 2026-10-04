<?php

namespace Tests\Feature;

use App\Enums\IncidentStatus;
use App\Enums\UserRole;
use App\Models\ApiToken;
use App\Models\AuditEntry;
use App\Models\Component;
use App\Models\ComponentGroup;
use App\Models\Incident;
use App\Models\IncidentTemplate;
use App\Models\IncidentUpdate;
use App\Models\Maintenance;
use App\Models\MaintenanceNotification;
use App\Models\Setting;
use App\Models\StatusPage;
use App\Models\Subscriber;
use App\Models\SubscriberNotification;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\PageContext;
use App\Services\PageDeletion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PageDeletionTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => 'correct-horse-battery',
            'role' => UserRole::Admin,
        ]);
    }

    public function test_deleting_a_page_removes_it_and_everything_it_owns(): void
    {
        Storage::fake('public');
        $page = $this->pageWithEverything('Customer B', 'customer-b');
        $kept = Component::create(['name' => 'Default service']);
        Storage::disk('public')->put("brand/pages/{$page->id}/logo.png", 'logo');
        Storage::disk('public')->put('brand/pages/'.StatusPage::defaultId().'/logo.png', 'logo');

        $this->actingAs($this->admin)
            ->delete("/admin/pages/{$page->id}")
            ->assertRedirect('/admin/pages')
            ->assertSessionHas('status', 'Customer B deleted.');

        $this->assertDatabaseMissing('status_pages', ['id' => $page->id]);
        foreach ($this->tablesOwnedByPages() as $table) {
            $this->assertSame(0, DB::table($table)->where('status_page_id', $page->id)->count(), "Rows left in {$table}.");
        }
        $this->assertDatabaseHas('components', ['id' => $kept->id]);
        Storage::disk('public')->assertMissing("brand/pages/{$page->id}/logo.png");
        Storage::disk('public')->assertExists('brand/pages/'.StatusPage::defaultId().'/logo.png');
    }

    public function test_a_deleted_page_leaves_no_cached_settings_behind(): void
    {
        $page = StatusPage::create(['name' => 'Customer B', 'slug' => 'customer-b']);
        $context = app(PageContext::class);
        $context->run($page->id, function (): void {
            Setting::put('brand.name', 'Customer brand');
            $this->assertSame('Customer brand', Setting::get('brand.name'));
        });

        $this->actingAs($this->admin)->delete("/admin/pages/{$page->id}")->assertRedirect('/admin/pages');

        $this->assertNull($context->run($page->id, fn () => Setting::get('brand.name')));
    }

    public function test_the_default_page_cannot_be_deleted(): void
    {
        $default = StatusPage::default();

        $this->actingAs($this->admin)->delete("/admin/pages/{$default->id}")->assertForbidden();

        $this->assertDatabaseHas('status_pages', ['id' => $default->id]);
    }

    public function test_only_administrators_can_delete_a_page(): void
    {
        $page = StatusPage::create(['name' => 'Customer B', 'slug' => 'customer-b']);
        $member = User::create([
            'name' => 'Member', 'email' => 'member@example.com',
            'password' => 'correct-horse-battery', 'role' => UserRole::User,
        ]);
        $page->users()->attach($member);

        $this->actingAs($member)->delete("/admin/pages/{$page->id}")->assertForbidden();
        $this->assertDatabaseHas('status_pages', ['id' => $page->id]);
    }

    public function test_an_archived_page_can_be_deleted(): void
    {
        $page = StatusPage::create(['name' => 'Old shop', 'slug' => 'old-shop', 'archived_at' => now()->subWeek()]);

        $this->actingAs($this->admin)->delete("/admin/pages/{$page->id}")->assertRedirect('/admin/pages');

        $this->assertDatabaseMissing('status_pages', ['id' => $page->id]);
    }

    public function test_deleting_a_page_tells_nobody_outside(): void
    {
        Mail::fake();
        Http::fake();
        $page = $this->pageWithEverything('Customer B', 'customer-b');

        $this->actingAs($this->admin)->delete("/admin/pages/{$page->id}")->assertRedirect('/admin/pages');

        Mail::assertNothingOutgoing();
        Http::assertNothingSent();
    }

    public function test_the_audit_log_keeps_its_lines_and_gains_one_for_the_deletion(): void
    {
        $page = $this->pageWithEverything('Customer B', 'customer-b');
        $earlier = AuditEntry::create([
            'status_page_id' => $page->id, 'actor' => 'Admin (admin@example.com)',
            'action' => 'subscriber.removed', 'created_at' => now()->subDay(),
        ]);

        $this->actingAs($this->admin)->delete("/admin/pages/{$page->id}");

        $this->assertNull($earlier->fresh()->status_page_id);
        $line = AuditEntry::where('action', 'page.deleted')->sole();
        $this->assertSame('Customer B', $line->subject_label);
        $this->assertNull($line->status_page_id);
        $this->assertSame(['services' => 1, 'incidents' => 1, 'subscribers' => 1], $line->changes);
    }

    public function test_the_pages_list_offers_delete_and_says_what_goes_with_it(): void
    {
        $page = $this->pageWithEverything('Customer B', 'customer-b');
        $default = StatusPage::default();

        $out = $this->actingAs($this->admin)->get('/admin/pages')->assertOk();

        $out->assertSee('action="'.route('admin.pages.destroy', $page).'"', false);
        $out->assertSee('aria-label="Delete Customer B"', false);
        $out->assertSee('<strong>1 service, 1 incident and 1 subscriber</strong>', false);
        $out->assertDontSee('action="'.route('admin.pages.destroy', $default).'"', false);
    }

    public function test_the_summary_names_only_what_exists(): void
    {
        $this->assertSame('4 services, 12 incidents and 48 subscribers', PageDeletion::summary(['services' => 4, 'incidents' => 12, 'subscribers' => 48]));
        $this->assertSame('1 service and 2 subscribers', PageDeletion::summary(['services' => 1, 'incidents' => 0, 'subscribers' => 2]));
        $this->assertSame('3 incidents', PageDeletion::summary(['incidents' => 3]));
        $this->assertSame('', PageDeletion::summary([]));
    }

    /** One of every kind of record a page can own. */
    protected function pageWithEverything(string $name, string $slug): StatusPage
    {
        $page = StatusPage::create(['name' => $name, 'slug' => $slug, 'is_published' => true]);

        app(PageContext::class)->run($page->id, function () use ($page): void {
            $group = ComponentGroup::create(['name' => 'Hosting']);
            Component::create(['name' => 'web-01', 'component_group_id' => $group->id]);
            $incident = Incident::create(['name' => 'Mail queue backed up', 'status' => IncidentStatus::Investigating, 'occurred_at' => now()]);
            $update = IncidentUpdate::create(['incident_id' => $incident->id, 'status' => IncidentStatus::Investigating, 'message' => 'Looking into it.']);
            IncidentTemplate::create([
                'name' => 'Server unreachable', 'slug' => 'server-unreachable',
                'title_template' => '{{server}} unreachable', 'body_template' => 'Outage on {{server}}.',
            ]);
            $subscriber = Subscriber::create(['email' => 'ann@example.net', 'token' => Subscriber::freshToken(), 'verified_at' => now()]);
            SubscriberNotification::create(['subscriber_id' => $subscriber->id, 'incident_update_id' => $update->id]);
            $maintenance = Maintenance::create(['title' => 'Database upgrade', 'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour(), 'announce_minutes' => 1440]);
            MaintenanceNotification::create(['subscriber_id' => $subscriber->id, 'maintenance_id' => $maintenance->id]);
            $endpoint = WebhookEndpoint::create(['label' => 'Chat', 'url' => 'https://example.test/hook', 'format' => 'slack', 'enabled' => true]);
            WebhookDelivery::create(['status_page_id' => $page->id, 'webhook_endpoint_id' => $endpoint->id, 'event_key' => hash('sha256', 'one'), 'payload' => [], 'attempts' => 1, 'sent_at' => now()]);
            ApiToken::issue('Deploy token', $this->admin, $page->id);
            Setting::put('brand.name', 'Customer brand');
        });
        $page->users()->attach($this->admin);

        return $page;
    }

    /**
     * Every table that carries a page id, read from the schema so a table added
     * later is checked too. The audit log is the one that outlives its page.
     *
     * @return list<string>
     */
    protected function tablesOwnedByPages(): array
    {
        $tables = array_filter(
            array_map(fn (array $table) => $table['name'], Schema::getTables()),
            fn (string $table) => $table !== 'audit_log' && Schema::hasColumn($table, 'status_page_id'),
        );
        $this->assertContains('components', $tables);

        return array_values($tables);
    }
}
