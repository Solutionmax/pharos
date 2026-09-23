<?php

namespace Tests\Feature;

use App\Enums\IncidentStatus;
use App\Enums\UserRole;
use App\Models\Component;
use App\Models\ComponentGroup;
use App\Models\Incident;
use App\Models\IncidentUpdate;
use App\Models\Maintenance;
use App\Models\StatusPage;
use App\Models\User;
use App\Services\PageContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Search must never become a side door: results only come from pages the
 * user holds a role on, and accounts only for installation administrators.
 */
class SearchTest extends TestCase
{
    use RefreshDatabase;

    protected StatusPage $harbor;

    protected StatusPage $secret;

    protected function setUp(): void
    {
        parent::setUp();
        $this->harbor = StatusPage::create(['name' => 'Harbor', 'slug' => 'harbor', 'is_published' => true]);
        $this->secret = StatusPage::create(['name' => 'Secret customer', 'slug' => 'secret', 'is_published' => true]);

        Component::create(['name' => 'relay default']);
        foreach ([$this->harbor, $this->secret] as $page) {
            app(PageContext::class)->run($page->id, function () use ($page) {
                ComponentGroup::create(['name' => 'relay services '.$page->slug]);
                Component::create(['name' => 'relay '.$page->slug]);
                $incident = Incident::create(['name' => 'relay outage '.$page->slug, 'status' => IncidentStatus::Investigating, 'occurred_at' => now()->subHours(2)]);
                IncidentUpdate::create(['incident_id' => $incident->id, 'status' => IncidentStatus::Investigating, 'message' => 'internal note about '.$page->slug]);
                Maintenance::create([
                    'title' => 'relay window '.$page->slug, 'message' => 'maintenance note about '.$page->slug,
                    'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHours(2), 'announce_minutes' => 0,
                ]);
            });
        }
    }

    protected function member(StatusPage $page, string $role): User
    {
        $user = User::factory()->create(['role' => UserRole::User]);
        $user->statusPages()->attach($page->id, ['role' => $role]);

        return $user;
    }

    /** @return list<array<string, mixed>> */
    protected function results(User $user, string $term, ?StatusPage $page = null): array
    {
        return $this->actingAs($user)
            ->getJson('/admin/search?q='.urlencode($term).($page ? '&page='.$page->id : ''))
            ->assertOk()->json('results');
    }

    /** @return list<array<string, mixed>> */
    protected function ofType(array $results, string $type): array
    {
        return array_values(array_filter($results, fn (array $r) => $r['type'] === $type));
    }

    /** @return list<string> */
    protected function labels(User $user, string $term): array
    {
        return collect($this->actingAs($user)->getJson('/admin/search?q='.urlencode($term))->assertOk()->json('results'))
            ->pluck('label')->all();
    }

    public function test_a_page_user_only_finds_items_on_their_own_page(): void
    {
        $viewer = User::factory()->create(['role' => UserRole::User, 'name' => 'Viewer']);
        $viewer->statusPages()->attach($this->harbor->id, ['role' => 'viewer']);

        $labels = $this->labels($viewer, 'relay');

        $this->assertContains('relay harbor', $labels);
        $this->assertContains('relay services harbor', $labels);
        $this->assertContains('relay outage harbor', $labels);
        foreach ($labels as $label) {
            $this->assertStringNotContainsString('secret', $label);
            $this->assertStringNotContainsString('default', $label);
        }
        $this->assertSame([], $this->labels($viewer, 'Secret'));
    }

    public function test_results_link_to_the_right_page_and_respect_the_role(): void
    {
        $viewer = User::factory()->create(['role' => UserRole::User]);
        $viewer->statusPages()->attach($this->harbor->id, ['role' => 'viewer']);
        $editor = User::factory()->create(['role' => UserRole::User]);
        $editor->statusPages()->attach($this->harbor->id, ['role' => 'editor']);

        $viewerHit = collect($this->actingAs($viewer)->getJson('/admin/search?q=relay%20harbor')->json('results'))->firstWhere('type', 'Component');
        $editorHit = collect($this->actingAs($editor)->getJson('/admin/search?q=relay%20harbor')->json('results'))->firstWhere('type', 'Component');

        $this->assertStringEndsWith('/admin/pages/'.$this->harbor->id.'/components', $viewerHit['url']);
        $this->assertMatchesRegularExpression('#/admin/pages/'.$this->harbor->id.'/components/\d+/edit$#', $editorHit['url']);
        $this->actingAs($editor)->get($editorHit['url'])->assertOk();
    }

    public function test_archived_pages_and_revoked_access_are_not_searched(): void
    {
        $user = User::factory()->create(['role' => UserRole::User]);
        $user->statusPages()->attach($this->harbor->id, ['role' => 'editor']);
        $this->assertContains('relay harbor', $this->labels($user, 'relay'));

        $this->harbor->update(['archived_at' => now()]);
        $this->assertSame([], $this->labels($user, 'relay'));

        $this->harbor->update(['archived_at' => null]);
        $user->statusPages()->detach();
        $this->assertSame([], $this->labels($user, 'relay'));
    }

    public function test_an_administrator_finds_every_page_and_accounts(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        User::factory()->create(['name' => 'Relay operator', 'email' => 'ops@example.net']);

        $labels = $this->labels($admin, 'relay');

        foreach (['relay default', 'relay harbor', 'relay secret', 'Relay operator'] as $expected) {
            $this->assertContains($expected, $labels);
        }
        $this->assertContains('Secret customer', $this->labels($admin, 'secret'));
    }

    public function test_page_users_never_find_accounts(): void
    {
        $user = User::factory()->create(['role' => UserRole::User]);
        $user->statusPages()->attach($this->harbor->id, ['role' => 'admin']);
        User::factory()->create(['name' => 'Relay operator', 'email' => 'ops@example.net']);

        $results = $this->actingAs($user)->getJson('/admin/search?q=relay')->json('results');

        $this->assertEmpty(array_filter($results, fn ($r) => $r['type'] === 'User'));
        $this->assertSame([], $this->labels($user, 'ops@example'));
    }

    public function test_short_or_odd_queries_return_nothing(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->assertSame([], $this->labels($admin, 'r'));
        $this->actingAs($admin)->getJson('/admin/search?q[]=relay')->assertOk()->assertExactJson(['results' => []]);
    }

    public function test_guests_cannot_search(): void
    {
        $this->getJson('/admin/search?q=relay')->assertUnauthorized();
    }

    public function test_the_search_dialog_is_on_every_admin_screen(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($admin)->get('/admin/overview')->assertOk()
            ->assertSee('id="pharos-search"', false)
            ->assertSee('role="dialog"', false)
            ->assertSee('data-search-open', false)
            ->assertSee(route('admin.search'), false);
    }

    public function test_rows_carry_their_group_page_and_status_in_words(): void
    {
        $viewer = $this->member($this->harbor, 'viewer');

        $component = $this->ofType($this->results($viewer, 'relay harbor'), 'Component')[0];
        $this->assertSame('Components', $component['group']);
        $this->assertSame('components', $component['icon']);
        $this->assertSame(['label' => 'Operational', 'tone' => 'ok'], $component['status']);
        $this->assertSame('Harbor', $component['page']['name']);
        $this->assertSame('Open', $component['hint']);

        $incident = $this->ofType($this->results($viewer, 'outage'), 'Incident')[0];
        $this->assertSame(['label' => 'Investigating', 'tone' => 'b'], $incident['status']);
        $this->assertStringStartsWith('Started 2 hours ago', $incident['meta']);
    }

    public function test_results_are_grouped_and_the_current_page_comes_first(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        User::factory()->create(['name' => 'Relay operator']);

        $groups = array_values(array_unique(array_column($this->results($admin, 'relay'), 'group')));
        $this->assertSame(['Components', 'Services', 'Incidents', 'Maintenance', 'Users'], array_values(array_intersect($groups, ['Components', 'Services', 'Incidents', 'Maintenance', 'Users'])));

        $this->assertSame('relay secret', $this->ofType($this->results($admin, 'relay', $this->secret), 'Component')[0]['label']);
        $this->assertTrue($this->ofType($this->results($admin, 'relay', $this->secret), 'Component')[0]['current']);
        $this->assertSame('relay harbor', $this->ofType($this->results($admin, 'relay', $this->harbor), 'Component')[0]['label']);
    }

    public function test_a_page_the_user_cannot_open_is_ignored_as_the_current_page(): void
    {
        $editor = $this->member($this->harbor, 'editor');

        $results = $this->results($editor, 'relay', $this->secret);

        $this->assertNotEmpty($results);
        foreach ($results as $row) {
            $this->assertStringNotContainsString('secret', strtolower($row['label']));
            $this->assertStringNotContainsString('/pages/'.$this->secret->id.'/', $row['url']);
        }
        $report = collect($this->results($editor, 'report incident', $this->secret))->firstWhere('label', 'Report an incident');
        $this->assertStringEndsWith('/admin/pages/'.$this->harbor->id.'/incidents/create', $report['url']);
    }

    public function test_maintenance_is_found_per_page_and_opens_by_role(): void
    {
        $viewer = $this->member($this->harbor, 'viewer');
        $editor = $this->member($this->harbor, 'editor');

        $viewerRows = $this->ofType($this->results($viewer, 'relay window'), 'Maintenance');
        $this->assertSame(['relay window harbor'], array_column($viewerRows, 'label'));
        $this->assertStringEndsWith('/admin/pages/'.$this->harbor->id.'/maintenance', $viewerRows[0]['url']);
        $this->assertSame(['label' => 'Scheduled', 'tone' => 'm'], $viewerRows[0]['status']);
        $this->assertSame([], $this->ofType($this->results($viewer, 'window secret'), 'Maintenance'));

        $editorRow = $this->ofType($this->results($editor, 'relay window'), 'Maintenance')[0];
        $this->assertMatchesRegularExpression('#/admin/pages/'.$this->harbor->id.'/maintenance/\d+/edit$#', $editorRow['url']);
        $this->actingAs($editor)->get($editorRow['url'])->assertOk();

        // A finished window cannot be edited, so even an editor lands on the list.
        app(PageContext::class)->run($this->harbor->id, fn () => Maintenance::query()->update(['cancelled_at' => now()]));
        $cancelled = $this->ofType($this->results($editor, 'relay window'), 'Maintenance')[0];
        $this->assertStringEndsWith('/maintenance', $cancelled['url']);
        $this->assertSame('off', $cancelled['status']['tone']);
    }

    public function test_messages_behind_incidents_and_maintenance_never_leave_the_server(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $body = $this->actingAs($admin)->getJson('/admin/search?q=relay')->assertOk()->getContent();

        $this->assertStringNotContainsString('internal note', $body);
        $this->assertStringNotContainsString('maintenance note', $body);
        $this->assertStringNotContainsString('note about', $this->actingAs($admin)->getJson('/admin/search?q=note')->getContent());
    }

    public function test_screens_are_found_by_name_and_keyword_exactly_as_the_menu_offers_them(): void
    {
        $pageAdmin = $this->member($this->harbor, 'admin');
        $viewer = $this->member($this->harbor, 'viewer');
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $tokens = collect($this->ofType($this->results($pageAdmin, 'tokens'), 'Screen'))->firstWhere('label', 'API tokens');
        $this->assertNotNull($tokens);
        $this->assertStringEndsWith('/admin/pages/'.$this->harbor->id.'/integrations/tokens', $tokens['url']);
        $this->actingAs($pageAdmin)->get($tokens['url'])->assertOk();
        $delivery = collect($this->ofType($this->results($pageAdmin, 'smtp'), 'Screen'))->firstWhere('label', 'Delivery');
        $this->assertNotNull($delivery);
        $this->actingAs($pageAdmin)->get($delivery['url'])->assertOk();

        // What the sidebar hides from a read only member, search hides too.
        $this->assertNotContains('API tokens', array_column($this->ofType($this->results($viewer, 'tokens'), 'Screen'), 'label'));
        $this->assertSame([], $this->ofType($this->results($viewer, 'smtp'), 'Screen'));
        $this->assertSame([], $this->ofType($this->results($pageAdmin, 'audit'), 'Screen'));
        $this->assertSame(['Audit log'], array_column($this->ofType($this->results($admin, 'audit'), 'Screen'), 'label'));
        $this->assertContains('Your profile', array_column($this->ofType($this->results($viewer, 'password'), 'Screen'), 'label'));

        foreach ($this->ofType($this->results($viewer, 'in'), 'Screen') as $screen) {
            $this->actingAs($viewer)->get($screen['url'])->assertOk();
        }
    }

    public function test_quick_actions_follow_the_page_role(): void
    {
        $viewer = $this->member($this->harbor, 'viewer');
        $editor = $this->member($this->harbor, 'editor');
        $pageAdmin = $this->member($this->harbor, 'admin');
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->assertSame([], $this->ofType($this->results($viewer, 'report'), 'Action'));
        $this->assertSame([], $this->ofType($this->results($viewer, 'schedule maintenance'), 'Action'));
        $this->assertSame([], $this->ofType($this->results($viewer, 'add a component'), 'Action'));

        foreach (['report' => 'Report an incident', 'schedule' => 'Schedule maintenance', 'add component' => 'Add a component'] as $term => $label) {
            $action = collect($this->ofType($this->results($editor, $term), 'Action'))->firstWhere('label', $label);
            $this->assertNotNull($action, $label);
            $this->actingAs($editor)->get($action['url'])->assertOk();
        }

        $this->assertSame([], $this->ofType($this->results($pageAdmin, 'invite'), 'Action'));
        $invite = $this->ofType($this->results($admin, 'invite'), 'Action');
        $this->assertSame('Invite someone', $invite[0]['label']);
        $this->assertStringEndsWith('/admin/users#user-add', $invite[0]['url']);
    }

    public function test_the_empty_palette_offers_only_what_the_user_may_do(): void
    {
        $third = StatusPage::create(['name' => 'Third', 'slug' => 'third', 'is_published' => true]);
        $viewer = $this->member($this->harbor, 'viewer');
        $viewer->statusPages()->attach($third->id, ['role' => 'viewer']);
        $editor = $this->member($this->harbor, 'editor');

        $viewerPage = $this->actingAs($viewer)->get('/admin/pages/'.$this->harbor->id.'/overview')->assertOk();
        $viewerPage->assertSee('data-page="'.$this->harbor->id.'"', false)
            ->assertSee('data-search-start', false)
            ->assertSee('Switch to Third')
            ->assertDontSee('Switch to Secret customer')
            ->assertDontSee('Report an incident', false)
            ->assertDontSee('\/incidents\/create', false)
            ->assertDontSee('Invite someone');

        $this->actingAs($editor)->get('/admin/pages/'.$this->harbor->id.'/overview')->assertOk()
            ->assertSee('Report an incident')
            ->assertSee('Schedule maintenance')
            ->assertSee('Add a component')
            ->assertDontSee('Invite someone')
            ->assertDontSee('Switch to Secret customer');

        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin)->get('/admin/pages/'.$this->harbor->id.'/overview')->assertOk()
            ->assertSee('Invite someone')
            ->assertSee('Switch to Secret customer');
    }

    public function test_search_stays_throttled(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        for ($i = 0; $i < 60; $i++) {
            $this->getJson('/admin/search?q=relay'.($i % 3))->assertOk();
        }
        $this->getJson('/admin/search?q=relay')->assertStatus(429);
    }
}
