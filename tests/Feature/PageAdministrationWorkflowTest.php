<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\ApiToken;
use App\Models\StatusPage;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\PageContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PageAdministrationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_can_be_created_with_page_access_and_reassigned_without_cross_page_access(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $page = StatusPage::create(['name' => 'Customer B', 'slug' => 'customer-b']);
        $this->actingAs($admin)->post('/admin/users', [
            'name' => 'Operator', 'email' => 'operator@example.test', 'role' => 'user',
            'password' => 'long-enough-password', 'password_confirmation' => 'long-enough-password',
            'status_page_ids' => [$page->id],
        ])->assertRedirect('/admin/users');
        $member = User::where('email', 'operator@example.test')->sole();
        $this->assertSame([$page->id], $member->statusPages()->pluck('status_pages.id')->all());
        $this->flushSession();
        $this->actingAs($member)->get('/admin/pages/'.$page->id.'/components')->assertOk();
        $this->get('/admin/components')->assertNotFound();
        $this->put('/admin/users/'.$member->id.'/pages', ['status_page_ids' => [StatusPage::defaultId()]])->assertForbidden();

        $this->flushSession();
        $this->actingAs($admin)->put('/admin/users/'.$member->id.'/pages', ['status_page_ids' => [StatusPage::defaultId()]])->assertRedirect('/admin/users');
        $this->flushSession();
        $this->actingAs($member)->get('/admin/pages/'.$page->id.'/components')->assertNotFound();
        $this->get('/admin/components')->assertOk();
    }

    public function test_page_access_editor_rejects_archived_pages_and_does_not_restrict_administrators(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $member = User::factory()->create(['role' => UserRole::User]);
        $archived = StatusPage::create(['name' => 'Archived', 'slug' => 'archived', 'archived_at' => now()]);
        $this->actingAs($admin)->get('/admin/users/'.$member->id.'/pages')->assertOk()->assertSee('Page access for '.$member->name);
        $this->put('/admin/users/'.$member->id.'/pages', ['status_page_ids' => [$archived->id]])->assertSessionHasErrors('status_page_ids.0');
        $this->assertSame(0, $member->statusPages()->count());
        $this->put('/admin/users/'.$admin->id.'/pages', [])->assertForbidden();
    }

    public function test_integration_lists_paginate_independently_inside_the_selected_page(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $page = StatusPage::create(['name' => 'Beta integration', 'slug' => 'beta-integration']);
        app(PageContext::class)->run($page->id, function () use ($page, $admin) {
            for ($i = 1; $i <= 12; $i++) {
                $endpoint = WebhookEndpoint::create(['label' => 'Beta destination '.$i, 'url' => 'https://example.test/hook/'.$i, 'format' => 'slack', 'enabled' => false]);
                WebhookDelivery::create(['status_page_id' => $page->id, 'webhook_endpoint_id' => $endpoint->id, 'event_key' => hash('sha256', (string) $i), 'payload' => [], 'attempts' => 1, 'sent_at' => now()]);
                ApiToken::issue('Beta token '.$i, $admin, $page->id);
            }
        });
        $response = $this->actingAs($admin)->get('/admin/pages/'.$page->id.'/integrations?deliveries_page=2&endpoints_page=2')->assertOk();
        $response->assertSee('Integrations for Beta integration');
        foreach (['deliveries', 'endpoints'] as $list) {
            $this->assertSame(12, $response->viewData($list)->total());
            $this->assertCount(5, $response->viewData($list));
            $this->assertSame(2, $response->viewData($list)->currentPage());
        }
        $this->assertCount(10, $response->viewData('tokens'));
        $this->assertStringContainsString('endpoints_page=2', $response->viewData('deliveries')->nextPageUrl());
        $this->assertStringContainsString('/admin/pages/'.$page->id.'/integrations?', $response->viewData('deliveries')->nextPageUrl());
        $default = $this->get('/admin/integrations')->assertOk();
        $this->assertSame(0, $default->viewData('deliveries')->total());
        $default->assertDontSee('Beta destination');
    }
}
