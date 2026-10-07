<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\ApiToken;
use App\Models\Check;
use App\Models\Component;
use App\Models\StatusPage;
use App\Models\UptimeDay;
use App\Models\User;
use App\Services\PageHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ReportedLabelsTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected ApiToken $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => UserRole::Admin]);
        [$this->token] = ApiToken::issue('zabbix-intern', $this->admin, StatusPage::defaultId(), 'write');
    }

    protected function reported(string $name = 'Reported', string $source = 'webhook'): Component
    {
        $component = Component::create(['name' => $name, 'source' => $source, 'reported_at' => now(), 'reported_by_token_id' => $this->token->id]);
        UptimeDay::create(['component_id' => $component->id, 'day' => Carbon::today(), 'up_seconds' => 600, 'down_seconds' => 0]);

        return $component;
    }

    public function test_the_service_list_says_who_reports_and_how(): void
    {
        $this->reported();
        Component::create(['name' => 'Phone']);

        $html = $this->actingAs($this->admin)->get('/admin/components')->assertOk()
            ->assertSee('Set from outside · API')->assertSee('via zabbix-intern')
            ->assertSee('Set by hand')->assertSee('not measured')
            ->assertDontSee('Set by hand or API')->getContent();

        $this->assertStringContainsString('100.00%, reported', $html);
        $this->assertStringContainsString('· not measured"', $html);
        $this->assertStringNotContainsString('· no data"', $html);
    }

    public function test_the_service_list_names_kuma_and_upstream(): void
    {
        $this->reported('Kuma fed', 'kuma');
        $this->reported('Upstream fed', 'upstream');

        $this->actingAs($this->admin)->get('/admin/components')->assertOk()
            ->assertSee('Set from outside · Uptime Kuma')->assertSee('Set from outside · Upstream');
    }

    public function test_a_component_set_by_hand_shows_no_percentage(): void
    {
        Component::create(['name' => 'Phone']);

        $html = $this->actingAs($this->admin)->get('/admin/components')->assertOk()->getContent();

        $this->assertStringContainsString('<span class="op-pct"></span>', $html);
    }

    public function test_a_viewer_does_not_see_the_token_name(): void
    {
        $this->reported();
        $viewer = User::factory()->create(['role' => UserRole::User]);
        $viewer->statusPages()->attach(StatusPage::defaultId(), ['role' => 'viewer']);

        $this->actingAs($viewer)->get('/admin/components')->assertOk()
            ->assertSee('Set from outside · API')->assertDontSee('zabbix-intern');
    }

    public function test_a_deleted_token_leaves_the_label_without_the_via_line(): void
    {
        $this->reported();
        $this->token->delete();

        $this->actingAs($this->admin)->get('/admin/components')->assertOk()
            ->assertSee('Set from outside · API')->assertDontSee('via ');
    }

    public function test_the_overview_says_reported_and_not_measured(): void
    {
        $this->reported();
        $this->reported('Kuma fed', 'kuma');
        Component::create(['name' => 'Phone']);

        $html = $this->actingAs($this->admin)->get('/admin/overview')->assertOk()
            ->assertSee('api, reported')->assertSee('kuma, reported')
            ->assertSee('Not measured')
            ->assertSee('30 days, uptime: measured by Pharos or reported from outside')
            ->getContent();

        $this->assertStringContainsString('100.00% up, reported', $html);
    }

    public function test_the_public_page_colours_a_reported_day(): void
    {
        $component = $this->reported();
        $component->update(['show_uptime' => true]);

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString(Carbon::today()->format('j M').' · 100.00%', $html);
    }

    public function test_the_cards_say_so_when_nothing_is_left_to_a_person(): void
    {
        $this->reported('Zabbix one');

        $list = $this->actingAs($this->admin)->get('/admin/components')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#Watched</span>\s*<span class="v">1<span[^>]*>/1</span></span>\s*<span class="n">0 checked by Pharos, 1 reported from outside</span>#', $list);
        $this->assertMatchesRegularExpression('#Set by hand</span>\s*<span class="v">0</span>\s*<span class="n">Every component is checked or reported</span>#', $list);
    }

    public function test_every_screen_splits_components_the_same_way(): void
    {
        $checked = Component::create(['name' => 'Web', 'source' => 'check']);
        Check::create(['component_id' => $checked->id, 'type' => 'http', 'target' => 'https://example.test/', 'enabled' => true]);
        $this->reported('Zabbix one');
        $this->reported('Kuma one', 'kuma');
        $paused = $this->reported('Paused check', 'kuma');
        Check::create(['component_id' => $paused->id, 'type' => 'http', 'target' => 'https://example.test/', 'enabled' => false]);
        Component::create(['name' => 'Phone']);

        $list = $this->actingAs($this->admin)->get('/admin/components')->assertOk()->getContent();
        $this->assertSame(3, substr_count($list, 'Set from outside ·'));
        // One card says how much is watched at all, the next what nothing measures.
        $this->assertMatchesRegularExpression('#Watched</span>\s*<span class="v">4<span[^>]*>/5</span></span>\s*<span class="n">1 checked by Pharos, 3 reported from outside</span>#', $list);
        $this->assertMatchesRegularExpression('#Set by hand</span>\s*<span class="v">1</span>\s*<span class="n">Not measured: only a person changes them</span>#', $list);

        $overview = $this->actingAs($this->admin)->get('/admin/overview')->assertOk()->getContent();
        $this->assertSame(3, substr_count($overview, ', reported</span>'));

        $integrations = $this->actingAs($this->admin)->get('/admin/integrations/bring-in')->assertOk()->getContent();
        $this->assertSame(3, substr_count($integrations, '>Set from outside</span>'));

        $health = collect(app(PageHealth::class)->checks($this->admin))->firstWhere('key', 'checks');
        $this->assertSame('4 of 5 components; 1 relies on someone noticing', $health['detail']);
    }

    public function test_the_form_explains_that_pharos_labels_a_reported_service_itself(): void
    {
        $this->actingAs($this->admin)->get('/admin/components/create')->assertOk()
            ->assertSee('Pharos marks a service as set from outside by itself the first time the API or Uptime Kuma writes its status.');
    }
}
