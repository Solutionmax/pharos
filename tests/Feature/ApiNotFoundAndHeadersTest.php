<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\ApiToken;
use App\Models\Component;
use App\Models\ComponentGroup;
use App\Models\Incident;
use App\Models\StatusPage;
use App\Models\User;
use App\Services\PageContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Security review E3 and B4: an API 404 says nothing about what exists, and
 * the API and /up carry the same basic headers as the pages.
 */
class ApiNotFoundAndHeadersTest extends TestCase
{
    use RefreshDatabase;

    private const NOT_FOUND = ['message' => 'Not found.'];

    public function test_a_hidden_record_and_a_missing_one_read_the_same(): void
    {
        $hidden = Component::create(['name' => 'Secret', 'component_group_id' => ComponentGroup::create(['name' => 'Internal', 'visible' => false])->id]);
        $off = Component::create(['name' => 'Off', 'enabled' => false]);
        $other = StatusPage::create(['name' => 'Other', 'slug' => 'other', 'is_published' => true]);
        $foreign = app(PageContext::class)->run($other->id, fn () => Component::create(['name' => 'Foreign']));

        $bodies = [];
        foreach ([$hidden->id, $off->id, $foreign->id, 999999] as $id) {
            $response = $this->getJson('/api/v1/components/'.$id)->assertNotFound()->assertExactJson(self::NOT_FOUND);
            $this->assertStringNotContainsString('App\\Models', $response->getContent());
            $bodies[] = $response->getContent();
        }
        $this->assertCount(1, array_unique($bodies));

        // Also without asking for JSON, and for an address that does not exist at all.
        $this->get('/api/v1/components/999999')->assertNotFound()->assertExactJson(self::NOT_FOUND);
        $this->get('/api/v1/nothing-here')->assertNotFound()->assertExactJson(self::NOT_FOUND);
        $this->get('/api/v1/pages/no-such-page/components')->assertNotFound()->assertExactJson(self::NOT_FOUND);
    }

    public function test_a_write_with_a_token_to_another_pages_record_is_the_same_404(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        [, $token] = ApiToken::issue('ops', $admin, StatusPage::defaultId(), 'write');
        $other = StatusPage::create(['name' => 'Other', 'slug' => 'other', 'is_published' => true]);
        $incident = app(PageContext::class)->run($other->id, fn () => Incident::create(['name' => 'Elsewhere', 'status' => 1, 'occurred_at' => now()]));

        $this->withToken($token)->postJson('/api/v1/incidents/'.$incident->id.'/updates', ['status' => 2, 'message' => 'x'])
            ->assertNotFound()->assertExactJson(self::NOT_FOUND);
        $this->withToken($token)->putJson('/api/v1/components/424242', ['status' => 2])
            ->assertNotFound()->assertExactJson(self::NOT_FOUND);
    }

    public function test_pages_keep_their_own_not_found_page(): void
    {
        $this->get('/incidents/999999')->assertNotFound()->assertSee('This page does not exist');
    }

    public function test_api_and_health_responses_carry_the_basic_headers(): void
    {
        foreach (['/api/v1/components', '/api/v1/components/999999', '/api/v1/ping', '/up'] as $path) {
            $this->get($path)
                ->assertHeader('X-Content-Type-Options', 'nosniff')
                ->assertHeader('X-Frame-Options', 'DENY')
                ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        }

        $this->get('/api/v1/components')->assertHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
        $this->get('https://localhost/api/v1/components')->assertHeader('Strict-Transport-Security', 'max-age=31536000');
        $this->get('http://localhost/api/v1/components')->assertHeaderMissing('Strict-Transport-Security');
        // The health page draws itself with an outside stylesheet; it gets no policy that would break it.
        $this->get('/up')->assertOk()->assertHeaderMissing('Content-Security-Policy');
    }
}
