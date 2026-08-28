<?php

namespace Tests\Feature;

use App\Models\Component;
use App\Models\ComponentGroup;
use App\Models\UptimeDay;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ServicesTest extends TestCase
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
    }

    /** The component form adds services from a dialog and needs the row back. */
    public function test_a_service_can_be_created_over_json(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/admin/services', ['name' => 'Shared hosting', 'visible' => 1, 'collapsed' => 1]);

        $response->assertCreated()->assertJson(['name' => 'Shared hosting']);
        $this->assertSame(1, ComponentGroup::where('name', 'Shared hosting')->count());
        $this->assertSame($response->json('id'), ComponentGroup::sole()->id);
    }

    /**
     * The page used to carry a note calling "Shared hosting", "Email" and
     * "Network & DNS" seeded demo data. It rendered unconditionally, so on a
     * real install it told operators their own services were fake.
     */
    public function test_the_services_page_never_calls_real_services_demo_data(): void
    {
        $this->group('Shared hosting');

        $this->actingAs($this->user)->get('/admin/services')
            ->assertOk()
            ->assertSee('Shared hosting')
            ->assertDontSee('demo data')
            ->assertDontSee('Where the demo names came from');
    }

    public function test_a_service_can_be_deleted_over_json_without_taking_components(): void
    {
        $group = $this->group('Shared hosting');
        $component = Component::create(['component_group_id' => $group->id, 'name' => 'web-01']);

        $this->actingAs($this->user)
            ->deleteJson("/admin/services/{$group->id}")
            ->assertOk()
            ->assertJson(['deleted' => true, 'orphans' => 1]);

        $this->assertSame(0, ComponentGroup::count());
        $this->assertNull($component->fresh()->component_group_id);
    }

    public function test_a_nameless_service_over_json_is_refused_with_the_field_named(): void
    {
        $this->actingAs($this->user)
            ->postJson('/admin/services', ['name' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');

        $this->assertSame(0, ComponentGroup::count());
    }

    protected function group(string $name, int $position = 1, bool $visible = true): ComponentGroup
    {
        return ComponentGroup::create(['name' => $name, 'position' => $position, 'visible' => $visible]);
    }

    public function test_a_service_can_be_created_from_the_admin(): void
    {
        $this->actingAs($this->user)->post('/admin/services', [
            'name' => 'Shared hosting',
            'visible' => 1,
            'collapsed' => 1,
        ])->assertRedirect('/admin/services');

        $group = ComponentGroup::first();
        $this->assertSame('Shared hosting', $group->name);
        $this->assertTrue($group->visible);
        $this->assertTrue($group->collapsed);
    }

    public function test_a_service_can_be_renamed(): void
    {
        $group = $this->group('Webhosting');

        $this->actingAs($this->user)->put("/admin/services/{$group->id}", [
            'name' => 'Shared hosting',
            'visible' => 1,
        ])->assertRedirect();

        $this->assertSame('Shared hosting', $group->fresh()->name);
    }

    public function test_deleting_a_service_keeps_its_components_and_their_history(): void
    {
        // The uptime record is the thing a customer would miss; losing it because
        // a heading was renamed would be indefensible.
        $group = $this->group('Shared hosting');
        $component = Component::create(['component_group_id' => $group->id, 'name' => 'web-01']);
        UptimeDay::create([
            'component_id' => $component->id,
            'day' => Carbon::today(),
            'up_seconds' => 86400,
        ]);

        $this->actingAs($this->user)->delete("/admin/services/{$group->id}")->assertRedirect();

        $this->assertSame(0, ComponentGroup::count());
        $this->assertSame(1, Component::count());
        $this->assertNull($component->fresh()->component_group_id);
        $this->assertSame(1, UptimeDay::count());
    }

    public function test_services_can_be_reordered(): void
    {
        $first = $this->group('Email', 1);
        $second = $this->group('Shared hosting', 2);

        $this->actingAs($this->user)->post("/admin/services/{$second->id}/move", ['direction' => 'up'])
            ->assertRedirect();

        $this->assertSame(
            ['Shared hosting', 'Email'],
            ComponentGroup::orderBy('position')->pluck('name')->all(),
        );
    }

    public function test_moving_the_first_service_up_changes_nothing(): void
    {
        $first = $this->group('Email', 1);
        $this->group('Shared hosting', 2);

        $this->actingAs($this->user)->post("/admin/services/{$first->id}/move", ['direction' => 'up'])
            ->assertRedirect();

        $this->assertSame(
            ['Email', 'Shared hosting'],
            ComponentGroup::orderBy('position')->pluck('name')->all(),
        );
    }

    public function test_a_hidden_service_disappears_from_the_public_page(): void
    {
        $shown = $this->group('Email', 1);
        $hidden = $this->group('Internal', 2, visible: false);
        Component::create(['component_group_id' => $shown->id, 'name' => 'IMAP']);
        Component::create(['component_group_id' => $hidden->id, 'name' => 'Backups']);

        $this->get('/')->assertOk()->assertSee('IMAP')->assertDontSee('Backups');
    }

    public function test_service_visibility_is_saved_from_the_settings_form(): void
    {
        $a = $this->group('Email', 1);
        $b = $this->group('Shared hosting', 2);

        $this->actingAs($this->user)->put('/admin/settings', [
            'theme' => 'system',
            'incident_days' => 5,
            'modules' => ['page.show_services' => '1'],
            'groups' => [$a->id => '1'],
        ])->assertRedirect();

        $this->assertTrue($a->fresh()->visible);
        $this->assertFalse($b->fresh()->visible);
    }

    public function test_the_preview_can_hide_one_service_without_saving(): void
    {
        $a = $this->group('Email', 1);
        $b = $this->group('Shared hosting', 2);
        Component::create(['component_group_id' => $a->id, 'name' => 'IMAP']);
        Component::create(['component_group_id' => $b->id, 'name' => 'web-01']);

        $this->actingAs($this->user)->get(route('admin.settings.preview', [
            'm' => ['page.show_services' => '1'],
            'g' => [$a->id => '1', $b->id => '0'],
        ]))->assertOk()->assertSee('IMAP')->assertDontSee('web-01');

        // Nothing was written by looking.
        $this->assertTrue($b->fresh()->visible);
        $this->get('/')->assertSee('web-01');
    }

    public function test_the_settings_page_lists_each_service_as_its_own_switch(): void
    {
        $this->group('Email', 1);
        $this->group('Shared hosting', 2);

        $this->actingAs($this->user)->get('/admin/settings')
            ->assertOk()
            ->assertSee('Email')
            ->assertSee('Shared hosting')
            ->assertSee('name="groups[1]"', false);
    }

    public function test_the_services_screen_is_closed_to_strangers(): void
    {
        foreach (['/admin/services', '/admin/services/create'] as $url) {
            $this->get($url)->assertRedirect('/admin/login');
        }
    }

    public function test_the_add_and_edit_screens_render(): void
    {
        $this->actingAs($this->user)->get('/admin/services/create')
            ->assertOk()
            ->assertSee('Add a service')
            ->assertSee('Show on the status page');

        $group = $this->group('Email');

        $this->actingAs($this->user)->get("/admin/services/{$group->id}/edit")
            ->assertOk()
            ->assertSee('Edit Email')
            ->assertSee('value="Email"', false);
    }

    public function test_arriving_from_settings_leaves_a_way_back(): void
    {
        // Services is top level from the sidebar, but a detour when Settings sent
        // you. Landing there with no way back to the preview is what went wrong.
        $group = $this->group('Email');

        $this->actingAs($this->user)->get('/admin/services?from=settings')
            ->assertOk()
            ->assertSee('← Settings', false)
            ->assertSee(route('admin.settings'));

        $this->actingAs($this->user)->get('/admin/services')
            ->assertOk()
            ->assertDontSee('← Settings', false);
    }

    public function test_the_trail_survives_the_add_and_edit_screens(): void
    {
        $group = $this->group('Email');

        $this->actingAs($this->user)->get('/admin/services?from=settings')
            ->assertOk()
            ->assertSee('from=settings');

        $this->actingAs($this->user)->get('/admin/services/create?from=settings')
            ->assertOk()
            ->assertSee('from=settings');

        $this->actingAs($this->user)->get("/admin/services/{$group->id}/edit?from=settings")
            ->assertOk()
            ->assertSee('from=settings');
    }

    public function test_saving_returns_you_along_the_trail_you_came_by(): void
    {
        $group = $this->group('Email');

        $this->actingAs($this->user)->post('/admin/services?from=settings', ['name' => 'Backups', 'visible' => 1])
            ->assertRedirect(route('admin.groups', ['from' => 'settings']));

        $this->actingAs($this->user)->put("/admin/services/{$group->id}?from=settings", ['name' => 'Mail', 'visible' => 1])
            ->assertRedirect(route('admin.groups', ['from' => 'settings']));

        $this->actingAs($this->user)->delete("/admin/services/{$group->id}?from=settings")
            ->assertRedirect(route('admin.groups', ['from' => 'settings']));

        // Without the trail it stays where it always went.
        $other = $this->group('DNS');
        $this->actingAs($this->user)->put("/admin/services/{$other->id}", ['name' => 'DNS', 'visible' => 1])
            ->assertRedirect(route('admin.groups'));
    }

    public function test_the_settings_page_starts_the_trail(): void
    {
        $this->group('Email');

        $this->actingAs($this->user)->get('/admin/settings')
            ->assertOk()
            ->assertSee(route('admin.groups', ['from' => 'settings']));
    }

    public function test_the_list_links_to_the_edit_screen_for_each_service(): void
    {
        $group = $this->group('Email');

        $this->actingAs($this->user)->get('/admin/services')
            ->assertOk()
            ->assertSee(route('admin.groups.edit', $group))
            ->assertSee(route('admin.groups.create'));
    }
}
