<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AuditEntry;
use App\Models\StatusPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditFiltersTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_log_filters_by_user_page_and_action_and_the_csv_follows(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin, 'name' => 'Anita']);
        $bob = User::factory()->create(['role' => UserRole::User, 'name' => 'Bob']);
        $other = StatusPage::create(['name' => 'Harbor', 'slug' => 'harbor']);
        $this->entry($admin, StatusPage::defaultId(), 'component.updated', 'Anita edits the default page');
        $this->entry($bob, StatusPage::defaultId(), 'incident.created', 'Bob opens an incident');
        $this->entry($bob, $other->id, 'component.updated', 'Bob edits Harbor');

        $this->actingAs($admin)->get('/admin/audit?user='.$bob->id)->assertOk()
            ->assertSee('Bob opens an incident')->assertSee('Bob edits Harbor')->assertDontSee('Anita edits the default page');
        $this->get('/admin/audit?page_id='.$other->id)->assertOk()
            ->assertSee('Bob edits Harbor')->assertDontSee('Bob opens an incident');
        $this->get('/admin/audit?action=component.updated&user='.$bob->id)->assertOk()
            ->assertSee('Bob edits Harbor')->assertDontSee('Bob opens an incident')->assertDontSee('Anita edits');
        $this->get('/admin/audit')->assertSee('Harbor')->assertSee('Bob');

        $csv = $this->get('/admin/audit/export?user='.$bob->id.'&page_id='.$other->id)->streamedContent();
        $this->assertStringContainsString('Bob edits Harbor', $csv);
        $this->assertStringNotContainsString('Bob opens an incident', $csv);

        $this->get('/admin/audit?user=abc')->assertSessionHasErrors('user');
    }

    public function test_changes_read_as_from_and_to(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        AuditEntry::create(['actor' => 'Anita', 'action' => 'component.updated', 'subject_label' => 'Website',
            'changes' => ['status' => ['from' => 'Operational', 'to' => 'Major outage'], 'description' => ['from' => null, 'to' => 'Main site']], 'created_at' => now()]);

        $this->actingAs($admin)->get('/admin/audit')->assertOk()
            ->assertSee('<del>Operational</del>', false)->assertSee('<ins>Major outage</ins>', false)->assertSee('empty');
    }

    private function entry(User $user, int $pageId, string $action, string $label): void
    {
        AuditEntry::create(['user_id' => $user->id, 'status_page_id' => $pageId, 'actor' => $user->name.' ('.$user->email.')',
            'action' => $action, 'subject_label' => $label, 'created_at' => now()]);
    }
}
