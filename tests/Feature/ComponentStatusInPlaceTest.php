<?php

namespace Tests\Feature;

use App\Enums\ComponentStatus;
use App\Enums\UserRole;
use App\Models\AuditEntry;
use App\Models\Check;
use App\Models\Component;
use App\Models\ComponentGroup;
use App\Models\StatusPage;
use App\Models\User;
use App\Services\PageContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ComponentStatusInPlaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_editor_changes_a_status_in_place_and_it_is_audited(): void
    {
        User::factory()->create(['role' => UserRole::Admin]);
        $editor = $this->member('editor');
        $component = Component::create(['name' => 'API']);

        $this->actingAs($editor)->from('/admin/components')->put('/admin/components/'.$component->id.'/status', ['status' => 3])
            ->assertRedirect('/admin/components');

        $this->assertSame(ComponentStatus::PartialOutage, $component->fresh()->status);
        $this->assertSame('component.updated', AuditEntry::latest('id')->first()->action);
    }

    public function test_viewers_other_pages_and_bad_values_are_refused(): void
    {
        User::factory()->create(['role' => UserRole::Admin]);
        $viewer = $this->member('viewer');
        $component = Component::create(['name' => 'API']);
        $other = StatusPage::create(['name' => 'Other', 'slug' => 'other']);
        $foreign = app(PageContext::class)->run($other->id, fn () => Component::create(['name' => 'Foreign']));

        $this->actingAs($viewer)->put('/admin/components/'.$component->id.'/status', ['status' => 4])->assertForbidden();
        $this->actingAs($this->member('editor'))->put('/admin/components/'.$foreign->id.'/status', ['status' => 4])->assertNotFound();
        $this->put('/admin/components/'.$component->id.'/status', ['status' => 9])->assertSessionHasErrors('status');
        $this->put('/admin/components/'.$component->id.'/status', ['status' => 'x'])->assertSessionHasErrors('status');
        $this->assertSame(ComponentStatus::Operational, $component->fresh()->status);
    }

    public function test_the_list_groups_by_service_and_says_who_sets_each_status(): void
    {
        $editor = User::factory()->create(['role' => UserRole::Admin]);
        $mail = ComponentGroup::create(['name' => 'Mail service', 'position' => 1]);
        $checked = Component::create(['name' => 'SMTP relay', 'component_group_id' => $mail->id]);
        Check::create(['component_id' => $checked->id, 'type' => 'tcp', 'target' => 'smtp.example.test:25', 'enabled' => true]);
        Component::create(['name' => 'Kuma fed', 'component_group_id' => $mail->id, 'source' => 'kuma']);
        Component::create(['name' => 'Loose one']);

        $html = $this->actingAs($editor)->get('/admin/components')->assertOk()
            ->assertSee('Mail service')->assertSee('Ungrouped')
            ->assertSee('Checked by Pharos')->assertSee('TCP')->assertSee('Set from outside')->assertSee('Uptime Kuma')
            ->assertSee('/status', false)->getContent();
        $this->assertLessThan(strpos($html, 'Loose one'), strpos($html, 'SMTP relay'));
        $this->assertStringContainsString('name="status"', $html);
    }

    public function test_viewers_see_statuses_without_the_picker_or_check_targets(): void
    {
        User::factory()->create(['role' => UserRole::Admin]);
        $component = Component::create(['name' => 'SMTP relay']);
        Check::create(['component_id' => $component->id, 'type' => 'tcp', 'target' => 'secret-host.example.test:25', 'enabled' => true]);

        $this->actingAs($this->member('viewer'))->get('/admin/components')->assertOk()
            ->assertSee('Operational')->assertDontSee('secret-host.example.test')->assertDontSee('name="status"', false);
    }

    private function member(string $role): User
    {
        $user = User::factory()->create(['role' => UserRole::User]);
        $user->statusPages()->attach(StatusPage::defaultId(), ['role' => $role]);

        return $user;
    }
}
