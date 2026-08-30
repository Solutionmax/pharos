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
}
