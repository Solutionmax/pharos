<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A theme chosen with the quick toggle lives in localStorage. If it is applied
 * only at the end of the body, the page first paints in the system theme and
 * then flips (a dark flash in light mode). The choice has to be applied in the
 * head, before any stylesheet.
 */
class ThemeEarlyTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_admin_applies_the_remembered_theme_before_any_stylesheet(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $html = $this->actingAs($admin)->get('/admin/overview')->assertOk()->getContent();

        $this->assertEarlyThemeBeforeStyles($html);
    }

    public function test_the_public_status_page_applies_it_before_any_stylesheet(): void
    {
        User::factory()->create(['role' => UserRole::Admin]); // installed, so / is the status page

        $this->assertEarlyThemeBeforeStyles($this->get('/')->assertOk()->getContent());
    }

    private function assertEarlyThemeBeforeStyles(string $html): void
    {
        $head = substr($html, 0, (int) strpos($html, '</head>'));
        $early = strpos($head, 'data-theme-early');
        $firstStyle = min(array_filter([strpos($head, '<link rel="stylesheet"'), strpos($head, '<style')], fn ($p) => $p !== false));

        $this->assertNotFalse($early, 'no early theme script in the head');
        $this->assertLessThan($firstStyle, $early, 'the early theme script must come before the first stylesheet');
        $this->assertStringContainsString("localStorage.getItem('pharos-theme')", substr($head, $early, 600));
    }
}
