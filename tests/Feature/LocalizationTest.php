<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Mail\SubscribeConfirmMail;
use App\Models\Component;
use App\Models\Setting;
use App\Models\Subscriber;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsBrandPack;
use Tests\TestCase;

class LocalizationTest extends TestCase
{
    use GrantsBrandPack, RefreshDatabase;

    public function test_admin_language_is_saved_independently_of_page_language(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        Setting::put('page.locale', 'de');

        $this->actingAs($admin)->put('/admin/profile/preferences', ['theme' => 'system', 'locale' => 'nl'])
            ->assertSessionHasNoErrors();
        $this->assertSame('nl', $admin->fresh()->locale);
        $this->get('/admin/profile')->assertOk()->assertSee('lang="nl"', false)->assertSee('Voorkeuren');
        $this->get('/')->assertOk()->assertSee('lang="de"', false);
        $this->assertSame('en', app()->getLocale(), 'a completed request must restore its locale');
    }

    public function test_page_language_is_saved_and_unknown_languages_are_refused(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin)->put('/admin/status-page', ['theme' => 'system', 'incident_days' => 5, 'locale' => 'es'])
            ->assertSessionHasNoErrors();
        $this->assertSame('es', Setting::get('page.locale'));
        $this->put('/admin/status-page', ['theme' => 'system', 'incident_days' => 5, 'locale' => '../../secret'])
            ->assertSessionHasErrors('locale');
        $this->put('/admin/profile/preferences', ['theme' => 'system', 'locale' => 'xx'])
            ->assertSessionHasErrors('locale');
        $this->assertSame('es', Setting::get('page.locale'));
    }

    public function test_all_public_languages_render_and_api_status_names_stay_english(): void
    {
        User::factory()->create(['role' => UserRole::Admin]);
        Component::create(['name' => 'Website', 'status' => 1, 'enabled' => true]);
        foreach (['nl' => 'Alle systemen werken', 'de' => 'Alle Systeme funktionieren', 'es' => 'Todos los sistemas funcionan'] as $locale => $headline) {
            Setting::put('page.locale', $locale);
            $this->get('/')->assertOk()->assertSee('lang="'.$locale.'"', false)->assertSee($headline);
            $this->getJson('/api/v1/components')->assertOk()->assertJsonPath('data.0.status_name', 'Operational');
        }
    }

    public function test_default_subscriber_mail_uses_page_language_without_overwriting_custom_templates(): void
    {
        User::factory()->create(['role' => UserRole::Admin]);
        $subscriber = Subscriber::create(['email' => 'visitor@example.net', 'token' => Subscriber::freshToken()]);
        Setting::put('page.locale', 'nl');
        $html = (new SubscribeConfirmMail($subscriber))->render();
        $this->assertStringContainsString('Bevestig je abonnement', $html);
        $this->assertSame('en', app()->getLocale());

        $this->grantBrandPack();
        Setting::put('mail.template.subscribe_confirm.body', '# Custom message kept intact');
        $this->assertStringContainsString('Custom message kept intact', (new SubscribeConfirmMail($subscriber))->render());
    }

    public function test_missing_or_corrupt_stored_language_falls_back_safely(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        Setting::put('page.locale', '../en');
        $this->actingAs($admin)->get('/')->assertOk()->assertSee('lang="en"', false);
    }
}
