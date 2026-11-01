<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\StatusPage;
use App\Models\User;
use App\Support\AdminMenu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The menu can sit in the sidebar or in a top bar. The choice is a preference
 * (Profile), the page head has a quick switch, and both menus render from the
 * same rules so neither can offer a screen the other would refuse.
 */
class NavLayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_navigation_preference_is_saved_validated_and_applied(): void
    {
        $me = User::factory()->create(['role' => UserRole::Admin]);
        $this->assertSame('sidebar', $me->refresh()->nav_layout);

        $this->actingAs($me)->put('/admin/profile/preferences', ['theme' => 'system', 'nav_layout' => 'topbar'])
            ->assertRedirect(route('admin.profile'))->assertSessionHas('theme_saved');
        $this->assertSame('topbar', $me->fresh()->nav_layout);

        $html = $this->actingAs($me->fresh())->get('/admin/overview')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<html[^>]*data-layout="topbar"/', $html);

        $this->put('/admin/profile/preferences', ['theme' => 'system', 'nav_layout' => 'floating'])->assertSessionHasErrors('nav_layout');
        $this->assertSame('topbar', $me->fresh()->nav_layout);
    }

    public function test_the_sidebar_is_the_default_and_the_top_bar_follows_the_current_screen(): void
    {
        $me = User::factory()->create(['role' => UserRole::Admin]);

        $response = $this->actingAs($me)->get('/admin/components')->assertOk();
        $this->assertMatchesRegularExpression('/<html[^>]*data-layout="sidebar"/', $response->getContent());
        $response->assertSee('class="topnav"', false)
            ->assertSeeInOrder(['Overview', 'Services', 'Incidents', 'Audience', 'Appearance', 'Setup'])
            ->assertSee('data-layout-toggle', false)
            ->assertSee('data-layout-early', false);

        // The open section (Services) shows its screens on the second row, Components marked.
        $dom = new \DOMDocument;
        @$dom->loadHTML($response->getContent());
        $xpath = new \DOMXPath($dom);
        $labels = [];
        foreach ($xpath->query('//header[@class="topnav"]//nav[contains(@class,"subrow")]//a') as $a) {
            $labels[] = trim($a->textContent).($a->getAttribute('aria-current') === 'page' ? '*' : '');
        }
        $this->assertSame(['Services', 'Components*', 'Probe locations'], $labels);
    }

    public function test_the_top_bar_hides_what_the_sidebar_hides(): void
    {
        // A viewer on the default page: no Setup tab, no page administration screens, in either menu.
        $viewer = User::factory()->create(['role' => UserRole::User]);
        $viewer->statusPages()->attach(StatusPage::default()->id, ['role' => 'viewer']);

        $html = $this->actingAs($viewer)->get('/admin/overview')->assertOk()->getContent();
        $dom = new \DOMDocument;
        @$dom->loadHTML($html);
        $xpath = new \DOMXPath($dom);
        $tabs = [];
        foreach ($xpath->query('//header[@class="topnav"]//nav[contains(@class,"tabs")]//a') as $a) {
            $tabs[] = trim($a->textContent);
        }
        $this->assertNotContains('Setup', $tabs);
        $this->assertNotContains('Appearance', $tabs);
        $this->assertStringNotContainsString(route('admin.integrations.tokens'), $html);
        $this->assertStringNotContainsString(route('admin.users'), $html);
    }

    public function test_a_signed_out_screen_has_no_layout_script(): void
    {
        User::factory()->create(['role' => UserRole::Admin]);

        $this->get('/admin/login')->assertOk()->assertDontSee('data-layout-early', false)->assertDontSee('class="topnav"', false);
    }

    public function test_sections_group_the_menu_and_drop_what_is_not_there(): void
    {
        $link = fn (string $label, string $url, bool $active = false) => ['label' => $label, 'url' => $url, 'active' => $active, 'icon' => null, 'hint' => null, 'dot' => null, 'children' => []];
        $group = fn (string $label, array $children) => ['key' => strtolower($label), 'label' => $label, 'icon' => null, 'url' => $children[0]['url'],
            'active' => collect($children)->contains('active', true), 'hint' => null, 'dot' => null, 'children' => $children];

        $sections = AdminMenu::sections([
            'page' => [
                $link('Overview', '/o'),
                $group('Services', [$link('Services', '/s'), $link('Components', '/c', true)]),
                $link('Reports', '/r'),
                $group('Email', [$link('Delivery', '/d'), $link('Templates', '/t')]),
            ],
            'installation' => [$link('Users', '/u'), $group('Settings', [$link('General', '/g'), $link('Central mail', '/m', true)])],
        ]);

        $this->assertSame(['Overview', 'Services', 'Audience', 'Setup'], array_column($sections, 'label'));
        $this->assertSame([false, true, false, true], array_column($sections, 'active'));
        $this->assertSame(['/o', '/s', '/d', '/u'], array_column($sections, 'url'));
        $this->assertSame(['Overview', 'Reports'], array_column($sections[0]['items'], 'label'));
        $this->assertSame(['Email delivery', 'Email templates'], array_column($sections[2]['items'], 'label'));
        // An installation group is one screen with its own tabs, not two items; its link follows the open tab.
        $this->assertSame([['label' => 'Users', 'url' => '/u', 'active' => false], ['label' => 'Settings', 'url' => '/m', 'active' => true]], $sections[3]['items']);
    }
}
