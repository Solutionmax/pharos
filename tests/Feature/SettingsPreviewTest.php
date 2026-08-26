<?php

namespace Tests\Feature;

use App\Enums\IncidentStatus;
use App\Models\Component;
use App\Models\ComponentGroup;
use App\Models\Incident;
use App\Models\IncidentUpdate;
use App\Models\Setting;
use App\Models\User;
use App\Services\Branding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SettingsPreviewTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('correct-horse-battery'),
        ]);

        $group = ComponentGroup::create(['name' => 'Shared hosting']);
        Component::create(['component_group_id' => $group->id, 'name' => 'web-01']);

        $incident = Incident::create([
            'name' => 'Mail queue backed up',
            'status' => IncidentStatus::Investigating,
            'occurred_at' => now(),
        ]);
        IncidentUpdate::create([
            'incident_id' => $incident->id,
            'status' => IncidentStatus::Investigating,
            'message' => 'Looking into it.',
        ]);
    }

    /** @param array<string,bool> $on */
    protected function preview(array $on, array $extra = []): \Illuminate\Testing\TestResponse
    {
        $m = [];
        foreach (array_keys(Branding::MODULES) as $key) {
            $m[$key] = ! empty($on[$key]) ? '1' : '0';
        }

        return $this->actingAs($this->user)
            ->get(route('admin.settings.preview', array_merge(['m' => $m], $extra)));
    }

    public function test_the_preview_is_not_public(): void
    {
        $this->get(route('admin.settings.preview'))->assertRedirect('/admin/login');
    }

    public function test_it_renders_the_sections_that_are_ticked(): void
    {
        $this->preview([
            'page.show_overall' => true,
            'page.show_services' => true,
            'page.show_incidents' => true,
        ])->assertOk()
            ->assertSee('All systems operational')
            ->assertSee('web-01')
            ->assertSee('Mail queue backed up');
    }

    public function test_unticked_sections_disappear_from_the_preview(): void
    {
        $this->preview(['page.show_incidents' => true])
            ->assertOk()
            ->assertDontSee('All systems operational')
            ->assertDontSee('web-01')
            ->assertSee('Mail queue backed up');
    }

    public function test_a_query_key_with_dots_survives_the_round_trip(): void
    {
        // PHP turns a dot in a parameter name into an underscore, and Laravel
        // reads a dot as nesting, so these keys have to arrive as an array.
        $this->preview(['page.show_overall' => true])
            ->assertOk()
            ->assertSee('All systems operational');

        $this->preview(['page.show_overall' => false])
            ->assertOk()
            ->assertDontSee('All systems operational');
    }

    public function test_the_preview_theme_overrides_the_saved_one(): void
    {
        Setting::put('brand.theme', 'light');

        $html = $this->preview(['page.show_overall' => true], ['theme' => 'dark'])->getContent();
        $tag = substr($html, 0, strpos($html, '>', strpos($html, '<html')) + 1);

        $this->assertStringContainsString('data-theme="dark"', $tag);
        // Nothing was saved by looking at it.
        $this->assertSame('light', Setting::get('brand.theme'));
    }

    public function test_an_invalid_theme_falls_back_instead_of_failing(): void
    {
        $html = $this->preview(['page.show_overall' => true], ['theme' => 'neon'])->getContent();
        $tag = substr($html, 0, strpos($html, '>', strpos($html, '<html')) + 1);

        $this->assertStringNotContainsString('data-theme', $tag);
    }

    public function test_the_day_count_is_clamped(): void
    {
        // A hand-edited URL must not ask the database for ten years of history.
        $this->preview(['page.show_incidents' => true, 'page.show_empty_days' => true], ['days' => 9999])
            ->assertOk()
            ->assertDontSee(now()->subDays(31)->format('j F'));

        $this->preview(['page.show_incidents' => true], ['days' => -5])->assertOk();
    }

    public function test_the_preview_never_shows_the_admin_bar(): void
    {
        // It is an iframe of the customer-facing page; our own chrome inside it
        // would be misleading.
        $this->preview(['page.show_overall' => true])->assertDontSee('Back to admin');

        // While the real page, signed in, does show it.
        $this->actingAs($this->user)->get('/')->assertSee('Back to admin');
    }

    public function test_previewing_changes_nothing(): void
    {
        Setting::put('page.show_overall', '1');

        $this->preview([]);   // everything off

        $this->assertTrue(app(Branding::class)->module('page.show_overall'));
        $this->get('/')->assertSee('All systems operational');
    }

    public function test_the_settings_page_carries_the_preview_frame(): void
    {
        $this->actingAs($this->user)->get('/admin/settings')
            ->assertOk()
            ->assertSee('Live preview')
            ->assertSee('id="preview"', false)
            ->assertSee(route('admin.settings.preview'));
    }
}
