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
