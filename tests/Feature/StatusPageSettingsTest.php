<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The "no services yet" note sits inside the Services panel, which sits
 * inside #settings-form. A note's dismiss button used to bring its own
 * <form>, and a <form> inside a <form> is invalid HTML — the browser closes
 * the outer one at that point, silently detaching everything after it
 * (theme, incident days, Save/Undo) from #settings-form.
 */
class StatusPageSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_theme_incident_days_and_save_stay_inside_the_form_when_the_no_services_note_shows(): void
    {
        $admin = User::create([
            'name' => 'Admin', 'email' => 'admin@example.com',
            'password' => Hash::make('correct-horse-battery'),
        ]);

        // No ComponentGroup exists, so the Services panel falls back to the
        // "no services yet" note — the scenario that broke the form.
        $body = $this->actingAs($admin)->get('/admin/status-page')->assertOk()->getContent();

        $this->assertStringContainsString('data-note="status-page.no-services"', $body);

        libxml_use_internal_errors(true);
        $dom = new \DOMDocument;
        $dom->loadHTML($body);
        libxml_use_internal_errors(false);
        $xpath = new \DOMXPath($dom);

        foreach (['theme', 'incident_days'] as $fieldId) {
            $this->assertNotNull(
                $xpath->query("//*[@id='settings-form']//*[@id='{$fieldId}']")->item(0),
                "#{$fieldId} must be inside #settings-form",
            );
        }

        $this->assertNotNull(
            $xpath->query("//*[@id='settings-form']//button[@type='submit' and contains(., 'Save status page')]")->item(0),
            'The Save button must be inside #settings-form',
        );

        // The note's own dismiss button must still work — just from outside the form.
        $this->assertNotNull(
            $xpath->query("//button[@type='submit' and @form='note-dismiss-status-page.no-services']")->item(0),
        );
    }
}
