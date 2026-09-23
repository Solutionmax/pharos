<?php

namespace Tests\Feature;

use App\Enums\ComponentStatus;
use App\Enums\UserRole;
use App\Models\Component;
use App\Models\StatusPage;
use App\Models\User;
use App\Services\PageContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The sidebar offers exactly the screens a user may open: never a link that
 * answers 403 or 404, and groups only when there is something inside them.
 */
class AdminMenuTest extends TestCase
{
    use RefreshDatabase;

    protected function member(string $role, ?StatusPage $page = null): User
    {
        $user = User::factory()->create(['role' => UserRole::User]);
        $user->statusPages()->attach(($page ?? StatusPage::default())->id, ['role' => $role]);

        return $user;
    }

    /** @return list<string> every link in the sidebar */
    protected function sidebarLinks(string $html): array
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML($html);
        $xpath = new \DOMXPath($dom);
        $links = [];
        foreach ($xpath->query('//aside//nav//a') as $a) {
            $links[] = $a->getAttribute('href');
        }

        return $links;
    }

    public function test_an_administrator_sees_both_groups_with_expandable_sub_items(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($admin)->get('/admin/overview')->assertOk()
            ->assertSee('This page')->assertSee('Installation')
            ->assertSee('aria-expanded="false"', false)
            ->assertSeeInOrder(['Appearance', 'Layout', 'Branding'])
            ->assertSeeInOrder(['Email', 'Delivery', 'Templates'])
            ->assertSeeInOrder(['Settings', 'General', 'Central mail', 'Single sign on'])
            ->assertSee(route('admin.settings', ['tab' => 'sso']), false);
    }

    public function test_the_group_of_the_current_screen_is_open_and_marked(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $html = $this->actingAs($admin)->get('/admin/branding')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/class="navgroup open has-current"[^>]*>\s*<button[^>]*aria-expanded="true"[^>]*aria-controls="navkids-appearance"/', $html);
        $this->assertMatchesRegularExpression('/<a class="nav nav-sub" href="[^"]*\/admin\/branding"\s+aria-current="page"/', $html);
        $this->assertMatchesRegularExpression('/id="navkids-email"\s+hidden/', $html);
    }

    public function test_settings_sub_item_follows_the_tab(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $html = $this->actingAs($admin)->get('/admin/settings?tab=mail')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/tab=mail"\s+aria-current="page"\s*>Central mail/', $html);
        $this->assertDoesNotMatchRegularExpression('/tab=general"\s+aria-current="page"/', $html);
    }

    public function test_a_viewer_sees_only_operational_screens(): void
    {
        $viewer = $this->member('viewer');

        $response = $this->actingAs($viewer)->get('/admin/overview')->assertOk();
        $links = $this->sidebarLinks($response->getContent());

        foreach (['/admin/overview', '/admin/incidents', '/admin/services', '/admin/components', '/admin/subscribers', '/admin/maintenance', '/admin/integrations/send-out', '/admin/integrations/bring-in', '/admin/integrations/log'] as $path) {
            $this->assertContains(url($path), $links, $path);
        }
        foreach (['/admin/integrations/tokens', '/admin/status-page', '/admin/branding', '/admin/mail', '/admin/mail-templates', '/admin/users', '/admin/pages', '/admin/audit', '/admin/updates'] as $path) {
            $this->assertNotContains(url($path), $links, $path);
        }
        // Nothing left in Appearance or Email, so the groups themselves are gone.
        $response->assertDontSee('navkids-appearance')->assertDontSee('navkids-email')->assertDontSee('>Installation<', false);

        foreach ($links as $link) {
            if (str_starts_with($link, url('/admin'))) {
                $this->get($link)->assertOk();
            }
        }
    }

    public function test_an_editor_gets_layout_but_not_page_administration(): void
    {
        $editor = $this->member('editor');

        $links = $this->sidebarLinks($this->actingAs($editor)->get('/admin/overview')->getContent());

        $this->assertContains(url('/admin/status-page'), $links);
        $this->assertNotContains(url('/admin/branding'), $links);
        $this->assertNotContains(url('/admin/mail'), $links);
    }

    public function test_a_page_admin_gets_page_settings_but_no_installation_group(): void
    {
        $page = StatusPage::create(['name' => 'Harbor', 'slug' => 'harbor', 'is_published' => true]);
        $pageAdmin = $this->member('admin', $page);

        $response = $this->actingAs($pageAdmin)->get('/admin/pages/'.$page->id.'/overview')->assertOk();
        $links = $this->sidebarLinks($response->getContent());

        foreach (['branding', 'mail', 'mail-templates', 'status-page', 'components'] as $path) {
            $this->assertContains(url('/admin/pages/'.$page->id.'/'.$path), $links, $path);
        }
        // Page scoped links keep the page; nothing points at the default page.
        $this->assertEmpty(array_filter($links, fn ($l) => preg_match('#/admin/(components|branding|overview)$#', $l)));
        $response->assertDontSee('href="'.route('admin.users').'"', false)->assertDontSee('href="'.route('admin.pages.index').'"', false);

        foreach ($links as $link) {
            $this->get($link)->assertOk();
        }
    }

    public function test_a_user_without_access_to_the_current_page_gets_no_page_items(): void
    {
        $page = StatusPage::create(['name' => 'Harbor', 'slug' => 'harbor', 'is_published' => true]);
        $member = $this->member('editor', $page);

        $links = $this->sidebarLinks($this->actingAs($member)->get('/admin/profile')->assertOk()->getContent());

        $this->assertNotContains(url('/admin/components'), $links);
        $this->assertNotContains(url('/admin/overview'), $links);
    }

    public function test_the_page_selector_shows_each_pages_status_in_words(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $page = StatusPage::create(['name' => 'Harbor', 'slug' => 'harbor', 'is_published' => true]);
        Component::create(['name' => 'web', 'status' => ComponentStatus::Operational]);
        app(PageContext::class)->run($page->id, fn () => Component::create(['name' => 'api', 'status' => ComponentStatus::MajorOutage]));

        $html = $this->actingAs($admin)->get('/admin/overview')->assertOk()->getContent();
        $dom = new \DOMDocument;
        @$dom->loadHTML($html);
        $menu = (new \DOMXPath($dom))->query('//details[@aria-label="Choose a page to manage"]')->item(0);
        $text = preg_replace('/\s+/', ' ', $menu->textContent);

        $this->assertStringContainsString('Harbor Page Major outage, published', $text);
        $this->assertStringContainsString('Operational, published', $text);
        $this->assertStringContainsString('class="pdot s-b"', $dom->saveHTML($menu));
    }
}
