<?php

namespace Tests\Feature;

use App\Enums\IncidentStatus;
use App\Enums\UserRole;
use App\Models\Component;
use App\Models\ComponentGroup;
use App\Models\Incident;
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
                Incident::create(['name' => 'relay outage '.$page->slug, 'status' => IncidentStatus::Investigating, 'occurred_at' => now()]);
            });
        }
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
}
