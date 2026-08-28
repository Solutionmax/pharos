<?php

namespace Tests\Feature;

use App\Models\Component;
use App\Models\ComponentGroup;
use App\Models\Incident;
use App\Enums\IncidentStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\GrantsBrandPack;
use Tests\TestCase;

/**
 * Every screen must answer "how do I get out of here" without the browser's
 * back button. Missing that on one page is how an admin starts feeling like a
 * maze.
 */
class NavigationTest extends TestCase
{
    use GrantsBrandPack, RefreshDatabase;

    protected User $user;

    protected Component $component;

    protected Incident $incident;

    protected function setUp(): void
    {
        parent::setUp();

        // Mail templates only shows its Save/Undo controls to a licensed install.
        $this->grantBrandPack();

        $this->user = User::create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('correct-horse-battery'),
        ]);

        ComponentGroup::create(['name' => 'Shared hosting']);
        $this->component = Component::create(['name' => 'web-01']);
        $this->incident = Incident::create([
            'name' => 'Mail queue backed up',
            'status' => IncidentStatus::Investigating,
            'occurred_at' => now(),
        ]);
    }

    /** Screens that genuinely sit inside another one. */
    public static function subPages(): array
    {
        return [
            'add a component' => ['/admin/components/create', 'Components'],
            'report an incident' => ['/admin/incidents/create', 'Incidents'],
            'add a service' => ['/admin/services/create', 'Services'],
        ];
    }

    /** Screens the sidebar reaches directly. A back link here invents a hierarchy. */
    public static function topLevelPages(): array
    {
        return [
            'services' => ['/admin/services'],
            'components' => ['/admin/components'],
            'incidents' => ['/admin/incidents'],
            'status page' => ['/admin/status-page'],
            'subscribers' => ['/admin/subscribers'],
            'settings' => ['/admin/settings'],
            'integrations' => ['/admin/integrations'],
            'branding' => ['/admin/branding'],
            'mail templates' => ['/admin/mail-templates'],
            'users' => ['/admin/users'],
        ];
    }

    #[DataProvider('topLevelPages')]
    public function test_a_top_level_screen_has_no_back_link(string $url): void
    {
        // Services sits beside Components in the sidebar, not inside it. A back
        // link there sends you somewhere you did not come from.
        $this->actingAs($this->user)->get($url)->assertOk()->assertDontSee('class="backlink"', false);
    }

    #[DataProvider('subPages')]
    public function test_a_sub_page_offers_a_way_back(string $url, string $parent): void
    {
        $this->actingAs($this->user)->get($url)
            ->assertOk()
            ->assertSee('class="backlink"', false)
            ->assertSee('← '.$parent, false);
    }

    public function test_editing_a_component_offers_a_way_back(): void
    {
        $this->actingAs($this->user)->get("/admin/components/{$this->component->id}/edit")
            ->assertOk()
            ->assertSee('← Components', false);
    }

    public function test_adding_an_incident_update_offers_a_way_back(): void
    {
        $this->actingAs($this->user)->get("/admin/incidents/{$this->incident->id}/update")
            ->assertOk()
            ->assertSee('← Incidents', false);
    }

    public static function editingScreens(): array
    {
        return [
            'add a component' => ['/admin/components/create'],
            'report an incident' => ['/admin/incidents/create'],
            'add a service' => ['/admin/services/create'],
            'status page' => ['/admin/status-page'],
            'settings' => ['/admin/settings'],
            'integrations' => ['/admin/integrations'],
            'branding' => ['/admin/branding'],
            'mail templates' => ['/admin/mail-templates'],
            'users' => ['/admin/users'],
        ];
    }

    #[DataProvider('editingScreens')]
    public function test_every_editing_screen_can_be_abandoned(string $url): void
    {
        $body = $this->actingAs($this->user)->get($url)->assertOk()->getContent();

        $abandon = str_contains($body, '>Cancel<')
            || str_contains($body, 'type="reset"');

        $this->assertTrue($abandon, "No way to abandon changes on {$url}");
    }

    #[DataProvider('editingScreens')]
    public function test_no_cancel_link_points_at_the_page_you_are_already_on(string $url): void
    {
        // This is what made Cancel on Services look broken: it was a link back to
        // Services. Clicking it reloaded the page and appeared to do nothing.
        $body = $this->actingAs($this->user)->get($url)->assertOk()->getContent();
        $here = url($url);

        preg_match_all('/<a[^>]+href="([^"]+)"[^>]*>(Cancel|Discard changes)</', $body, $matches, PREG_SET_ORDER);

        foreach ($matches as [$_, $href, $label]) {
            $this->assertNotSame(
                rtrim($here, '/'),
                rtrim($href, '/'),
                "\"{$label}\" on {$url} links to the page it is already on, so it does nothing.",
            );
        }
    }

    #[DataProvider('editingScreens')]
    public function test_admin_pages_are_never_kept_by_the_browser(string $url): void
    {
        // Laravel's default still allows the back/forward cache, which is how you
        // end up looking at a screen the server never rendered.
        $header = (string) $this->actingAs($this->user)->get($url)->assertOk()->headers->get('Cache-Control');

        $this->assertStringContainsString('no-store', $header, "{$url} may be cached by the browser");
    }

    #[DataProvider('editingScreens')]
    public function test_the_theme_script_is_included_exactly_once(string $url): void
    {
        // Twice, and the button in the header carries two click handlers: it
        // flips the theme and flips it straight back, so it looks dead.
        $body = $this->actingAs($this->user)->get($url)->assertOk()->getContent();

        $this->assertSame(1, substr_count($body, "localStorage.setItem('pharos-theme'"), "Theme script duplicated on {$url}");
        $this->assertSame(1, substr_count($body, 'class="theme-toggle"'), "Theme button duplicated on {$url}");
    }

    public function test_the_status_page_has_one_theme_script_too(): void
    {
        $body = $this->get('/')->assertOk()->getContent();

        $this->assertSame(1, substr_count($body, "localStorage.setItem('pharos-theme'"));
        $this->assertSame(1, substr_count($body, 'class="theme-toggle"'));
    }

    public function test_the_public_page_is_not_forced_to_revalidate(): void
    {
        // Only the admin needs this; the status page is read far more often than
        // it changes.
        $this->assertStringNotContainsString(
            'no-store',
            (string) $this->get('/')->headers->get('Cache-Control'),
        );
    }

    public function test_every_admin_screen_reaches_the_status_page(): void
    {
        foreach (array_merge(...array_values(self::editingScreens())) as $url) {
            $this->actingAs($this->user)->get($url)
                ->assertOk()
                ->assertSee('View status page');
        }
    }
}
