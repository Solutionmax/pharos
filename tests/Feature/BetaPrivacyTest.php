<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Check;
use App\Models\Component;
use App\Models\Setting;
use App\Models\StatusPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BetaPrivacyTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_viewer_sees_scheduler_health_but_no_server_commands_or_internal_errors(): void
    {
        $component = Component::create(['name' => 'Website', 'status' => 1, 'enabled' => true]);
        Check::create(['component_id' => $component->id, 'type' => 'http', 'target' => 'https://example.net', 'enabled' => true]);
        Setting::put('checks.last_error', 'PRIVATE_INTERNAL_DIAGNOSTIC');
        $viewer = User::factory()->create(['role' => UserRole::User]);
        $viewer->statusPages()->attach(StatusPage::defaultId(), ['role' => 'viewer']);
        $this->actingAs($viewer)->get('/admin/overview')->assertOk()
            ->assertSee('Nothing is being checked.')
            ->assertDontSee('* * * * *')
            ->assertDontSee('PRIVATE_INTERNAL_DIAGNOSTIC')
            ->assertDontSee(base_path());
    }
}
