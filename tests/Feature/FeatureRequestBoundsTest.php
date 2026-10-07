<?php

namespace Tests\Feature;

use App\Http\Middleware\FeatureRequestLimits;
use App\Models\ApiToken;
use App\Models\StatusPage;
use App\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class FeatureRequestBoundsTest extends TestCase
{
    use RefreshDatabase;

    private function request(string $path, string $method, string $body, ?string $token = null): Request
    {
        $request = new class extends Request
        {
            public bool $parsed = false;

            public function json($key = null, $default = null)
            {
                $this->parsed = true;

                return parent::json($key, $default);
            }
        };
        $server = ['REQUEST_URI' => $path, 'REQUEST_METHOD' => $method, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_HOST' => parse_url(config('app.url'), PHP_URL_HOST)];
        if ($token !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        }
        $request->initialize([], [], [], [], [], $server, $body);

        return $request;
    }

    public function test_new_feature_api_limits_precede_json_parsing_without_content_length(): void
    {
        foreach (['/api/v1', '/api/v1/pages/other'] as $prefix) {
            foreach (['groups' => 'POST', 'groups/1' => 'PUT', 'subscribers' => 'POST', 'subscribers/1' => 'PUT', 'maintenance' => 'POST', 'maintenance/1' => 'PUT', 'components' => 'POST', 'components/1' => 'DELETE', 'incidents/1' => 'DELETE', 'metrics' => 'GET'] as $path => $method) {
                $request = $this->request($prefix.'/'.$path, $method, '{"padding":"'.str_repeat('x', 262145).'"}');
                $response = app(Kernel::class)->handle($request);
                $this->assertSame(413, $response->getStatusCode(), $method.' '.$prefix.'/'.$path);
                $this->assertFalse($request->parsed, 'Oversized JSON was parsed before the feature limit');
            }
        }
    }

    public function test_exact_limit_is_preserved_for_controller_and_token_authority(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $page = StatusPage::create(['name' => 'Private', 'slug' => 'other']);
        foreach (['/api/v1' => StatusPage::defaultId(), '/api/v1/pages/other' => $page->id] as $prefix => $pageId) {
            [, $token] = ApiToken::issue('read', $owner, $pageId, 'read');
            $body = json_encode(['padding' => str_repeat('x', 262130)], JSON_THROW_ON_ERROR);
            $this->assertSame(262144, strlen($body));
            $request = $this->request($prefix.'/groups', 'GET', $body, $token);
            $this->assertSame(200, app(Kernel::class)->handle($request)->getStatusCode());
            $this->assertTrue($request->parsed, 'Accepted body must remain readable by global transformers');
            $this->assertSame(262130, strlen($request->json('padding')));
        }
        $request = $this->request('/api/v1/groups', 'GET', $body);
        $this->assertSame(401, app(Kernel::class)->handle($request)->getStatusCode());
    }

    public function test_one_byte_over_limit_fails_before_parsing_and_cannot_mutate(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        [, $token] = ApiToken::issue('write', $owner);
        $data = ['name' => 'Too large', 'padding' => ''];
        $data['padding'] = str_repeat('x', 262145 - strlen(json_encode($data, JSON_THROW_ON_ERROR)));
        $body = json_encode($data, JSON_THROW_ON_ERROR);
        $this->assertSame(262145, strlen($body));
        $request = $this->request('/api/v1/groups', 'POST', $body, $token);
        $this->assertSame(413, app(Kernel::class)->handle($request)->getStatusCode());
        $this->assertFalse($request->parsed);
        $this->assertDatabaseCount('component_groups', 0);
    }

    public function test_alternate_kernel_entry_uses_probe_decoded_path_limits_before_parsing(): void
    {
        foreach (['/api/v1/probe/results', '/api/v1/%70robe/results/'] as $path) {
            $request = $this->request($path, 'POST', '{"padding":"'.str_repeat('x', 16371).'"}');
            $this->assertSame(413, app(Kernel::class)->handle($request)->getStatusCode());
            $this->assertFalse($request->parsed);
        }
    }

    public function test_legacy_and_unrelated_paths_keep_their_original_processing(): void
    {
        foreach (['/api/v1/components' => 'GET', '/api/v1/incidents' => 'POST', '/api/v1/incidents/1' => 'PUT', '/api/v1/ping' => 'GET', '/subscribe' => 'POST'] as $path => $method) {
            $request = $this->request($path, $method, '{"padding":"'.str_repeat('x', 262145).'"}');
            $response = app(Kernel::class)->handle($request);
            $this->assertNotSame(413, $response->getStatusCode(), $method.' '.$path);
            $this->assertTrue($request->parsed);
        }
        $request = $this->request('/api/v1/components/1', 'POST', '{"status":1}');
        $this->assertNotSame(413, app(Kernel::class)->handle($request)->getStatusCode());
        $this->assertTrue($request->parsed);
        $request = $this->request('/api/v1/probe/results', 'POST', str_repeat('x', 262145));
        $response = app(FeatureRequestLimits::class)->handle($request, fn () => response('Other middleware owns probe limits'));
        $this->assertSame(200, $response->getStatusCode());
    }
}
