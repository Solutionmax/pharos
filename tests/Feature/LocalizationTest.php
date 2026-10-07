<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Mail\IncidentNoticeMail;
use App\Mail\MaintenanceNoticeMail;
use App\Mail\SubscribeConfirmMail;
use App\Models\Check;
use App\Models\Component;
use App\Models\Incident;
use App\Models\Maintenance;
use App\Models\Setting;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\CheckRunner;
use App\Services\OutgoingWebhook;
use App\Services\Probe;
use App\Services\ProbeResult;
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

    public function test_incident_mail_has_localized_status_and_signed_preference_link(): void
    {
        User::factory()->create(['role' => UserRole::Admin]);
        Setting::put('page.locale', 'nl');
        $incident = Incident::create(['name' => 'Database', 'status' => 1, 'visibility' => 'public', 'occurred_at' => now()]);
        $update = $incident->updates()->create(['status' => 1, 'message' => 'Unchanged customer message']);
        $subscriber = Subscriber::create(['email' => 'visitor@example.net', 'token' => Subscriber::freshToken(), 'verified_at' => now()]);

        $html = (new IncidentNoticeMail($update, $subscriber))->render();
        $this->assertStringContainsString('In onderzoek', $html);
        $this->assertStringContainsString('Unchanged customer message', $html);
        $this->assertStringContainsString('/subscribe/preferences/'.$subscriber->id.'?', $html);
        $this->assertStringContainsString('Abonnement beheren', $html);
    }

    public function test_validation_uses_the_admin_language_and_readable_field_names(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin, 'locale' => 'nl']);
        $this->actingAs($admin)->put('/admin/profile/preferences', ['theme' => 'invalid'])
            ->assertSessionHasErrors(['theme' => 'Thema is ongeldig.']);
    }

    public function test_automatic_incident_text_uses_page_language_with_customer_name_unchanged(): void
    {
        User::factory()->create(['role' => UserRole::Admin]);
        Setting::put('page.locale', 'nl');
        $component = Component::create(['name' => 'Website Ω', 'status' => 1, 'enabled' => true]);
        $check = Check::create(['component_id' => $component->id, 'type' => 'http', 'target' => 'https://example.net', 'retries' => 1, 'interval_seconds' => 60]);
        $probe = new class extends Probe
        {
            public function run(Check $check): ProbeResult
            {
                return new ProbeResult(false, 20, 'No response');
            }
        };
        (new CheckRunner($probe, app(OutgoingWebhook::class)))->runOne($check);
        $incident = Incident::firstOrFail();
        $this->assertSame('Website Ω onbereikbaar', $incident->name);
        $this->assertSame('Automatische controle mislukt: Geen antwoord.', $incident->updates()->firstOrFail()->message);
        $this->assertSame('en', app()->getLocale());
    }

    public function test_maintenance_mail_translates_month_names_in_the_page_language(): void
    {
        User::factory()->create(['role' => UserRole::Admin]);
        Setting::put('page.locale', 'nl');
        $subscriber = Subscriber::create(['email' => 'visitor@example.net', 'token' => Subscriber::freshToken(), 'verified_at' => now()]);
        $maintenance = Maintenance::create(['title' => 'Database', 'starts_at' => '2026-10-08 10:00:00', 'ends_at' => '2026-10-08 12:00:00']);
        $html = (new MaintenanceNoticeMail($maintenance, $subscriber))->render();
        $this->assertStringContainsString('oktober 2026', $html);
        $this->assertStringNotContainsString('October 2026', $html);
    }

    public function test_component_counts_use_the_account_language_plural(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        Component::create(['name' => 'Website Ω', 'status' => 1, 'enabled' => true]);
        Component::create(['name' => 'Database Ω', 'status' => 1, 'enabled' => true]);

        foreach (['en' => '2 components', 'nl' => '2 componenten', 'de' => '2 Komponenten', 'es' => '2 componentes'] as $locale => $caption) {
            $admin->forceFill(['locale' => $locale])->save();
            $this->actingAs($admin)->get('/admin/components')->assertOk()->assertSee($caption)->assertSee('Website Ω');
        }
    }
}
