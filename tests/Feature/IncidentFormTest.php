<?php

namespace Tests\Feature;

use App\Models\Component;
use App\Models\Incident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class IncidentFormTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::create(['name' => 'Admin', 'email' => 'admin@example.net', 'password' => Hash::make('correct-horse-battery')]);
    }

    public function test_the_form_offers_only_public_and_internal_visibility(): void
    {
        $this->actingAs($this->user)->get('/admin/incidents/create')
            ->assertOk()
            ->assertSee('value="public"', false)
            ->assertSee('value="internal"', false)
            ->assertDontSee('value="authenticated"', false);
    }

    public function test_the_form_post_still_accepts_a_stored_authenticated_value(): void
    {
        $this->actingAs($this->user)->post('/admin/incidents', [
            'name' => 'Old habit', 'message' => 'Noted.', 'status' => 1, 'impact' => 'minor', 'visibility' => 'authenticated',
        ])->assertRedirect('/admin/incidents');

        $this->assertSame('authenticated', Incident::firstOrFail()->visibility);
    }

    public function test_a_message_is_bounded_at_twenty_thousand_characters(): void
    {
        $post = fn (int $length) => $this->actingAs($this->user)->post('/admin/incidents', [
            'name' => 'Long', 'message' => str_repeat('a', $length), 'status' => 1, 'impact' => 'minor', 'visibility' => 'public',
        ]);

        $post(20001)->assertSessionHasErrors('message');
        $this->assertSame(0, Incident::count());

        $post(20000)->assertSessionHasNoErrors();
        $this->assertSame(1, Incident::count());
    }

    public function test_an_update_message_is_bounded_at_twenty_thousand_characters(): void
    {
        $incident = Incident::create(['name' => 'Long', 'status' => 1, 'occurred_at' => now()]);
        $post = fn (int $length) => $this->actingAs($this->user)->post("/admin/incidents/{$incident->id}/update", [
            'status' => 2, 'message' => str_repeat('a', $length),
        ]);

        $post(20001)->assertSessionHasErrors('message');
        $this->assertSame(0, $incident->updates()->count());

        $post(20000)->assertSessionHasNoErrors();
        $this->assertSame(1, $incident->updates()->count());
    }

    public function test_a_component_id_that_does_not_exist_is_dropped_not_a_500(): void
    {
        $real = Component::create(['name' => 'Web', 'status' => 1, 'source' => 'manual', 'enabled' => true]);

        $this->actingAs($this->user)->post('/admin/incidents', [
            'name' => 'Partial', 'message' => 'Looking into it.', 'status' => 1, 'impact' => 'minor', 'visibility' => 'public',
            'components' => [$real->id => 4, 999 => 4],
        ])->assertRedirect('/admin/incidents');

        $incident = Incident::firstOrFail();
        $this->assertSame([$real->id], $incident->components()->pluck('components.id')->all());
        $this->assertSame(4, $real->fresh()->status->value);
    }

    /**
     * The form's per-component <select> always posts something, even the
     * "leave unchanged" option (value=""). Picking a real status for one
     * component while leaving others on "unchanged" must not fail the whole
     * request with "The components.N field must be an integer."
     */
    public function test_leaving_a_component_unchanged_does_not_fail_validation(): void
    {
        $affected = Component::create(['name' => 'API', 'status' => 1, 'source' => 'manual', 'enabled' => true]);
        $unchanged = Component::create(['name' => 'Website', 'status' => 1, 'source' => 'manual', 'enabled' => true]);

        $this->actingAs($this->user)->post('/admin/incidents', [
            'name' => 'API outage', 'message' => 'Investigating.', 'status' => 1, 'impact' => 'major', 'visibility' => 'public',
            'components' => [$affected->id => 4, $unchanged->id => ''],
        ])->assertRedirect('/admin/incidents');

        $incident = Incident::firstOrFail();
        $this->assertSame([$affected->id], $incident->components()->pluck('components.id')->all());
        $this->assertSame(4, $affected->fresh()->status->value);
        $this->assertSame(1, $unchanged->fresh()->status->value);
    }

    /** A validation failure for an unrelated field must not silently reset every component choice. */
    public function test_a_validation_failure_keeps_the_chosen_component_statuses(): void
    {
        $component = Component::create(['name' => 'API', 'status' => 1, 'source' => 'manual', 'enabled' => true]);

        $this->actingAs($this->user)->from('/admin/incidents/create')->followingRedirects()->post('/admin/incidents', [
            'name' => '', // required, so this fails validation
            'message' => 'Investigating.', 'status' => 1, 'impact' => 'major', 'visibility' => 'public',
            'components' => [$component->id => 4],
        ])
            ->assertSee('selected', false)
            ->assertSee('value="4" selected', false);

        $this->assertSame(0, Incident::count());
    }
}
