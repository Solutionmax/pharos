<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * docs/openapi.json describes the API. A route added, renamed or removed
 * under api/v1 without the description following fails here.
 */
class OpenApiSpecTest extends TestCase
{
    private const METHODS = ['get', 'put', 'post', 'delete', 'patch', 'options', 'trace'];

    /** @return array<string, mixed> */
    private function spec(): array
    {
        $json = file_get_contents(base_path('docs/openapi.json'));
        $this->assertIsString($json);

        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    /** "api/v1/pages/{slug}/components/{component}" and "/api/v1/pages/{x}/components/{id}" compare equal. */
    private function normalise(string $method, string $uri): string
    {
        return strtoupper($method).' '.preg_replace('/\{[^}]+\}/', '{}', trim($uri, '/'));
    }

    /**
     * Every method and full path the description promises: the path of each
     * server an operation applies to, joined with the path of the operation.
     *
     * @param  array<string, mixed>  $spec
     * @return list<string>
     */
    private function documented(array $spec): array
    {
        $documented = [];
        foreach ($spec['paths'] as $path => $item) {
            foreach (self::METHODS as $method) {
                if (! isset($item[$method])) {
                    continue;
                }
                $servers = $item[$method]['servers'] ?? $item['servers'] ?? $spec['servers'];
                foreach ($servers as $server) {
                    $prefix = preg_replace('~^[a-z][a-z0-9+.-]*://[^/]*~i', '', $server['url']);
                    $documented[] = $this->normalise($method, $prefix.$path);
                }
            }
        }

        return $documented;
    }

    /** @return list<string> */
    private function registered(): array
    {
        $registered = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/')) {
                continue;
            }
            foreach ($route->methods() as $method) {
                if ($method !== 'HEAD') {
                    $registered[] = $this->normalise($method, $route->uri());
                }
            }
        }

        return $registered;
    }

    public function test_the_description_is_openapi_3_1_json(): void
    {
        $spec = $this->spec();

        $this->assertMatchesRegularExpression('/^3\.1\.\d+$/D', $spec['openapi'] ?? '');
        $this->assertNotEmpty($spec['info']['title'] ?? null);
        $this->assertNotEmpty($spec['paths'] ?? null);
    }

    public function test_every_api_route_is_described_and_nothing_else(): void
    {
        $registered = $this->registered();
        $documented = $this->documented($this->spec());
        $describedApi = array_values(array_filter($documented, fn ($entry) => str_contains($entry, ' api/v1/')));

        $this->assertNotEmpty($registered);
        $this->assertSame([], array_values(array_diff($registered, $documented)), 'Routes under api/v1 that docs/openapi.json does not describe.');
        $this->assertSame([], array_values(array_diff($describedApi, $registered)), 'Operations in docs/openapi.json that no api/v1 route answers.');
    }

    public function test_operation_ids_are_unique_and_every_reference_resolves(): void
    {
        $spec = $this->spec();
        $ids = [];
        foreach ($spec['paths'] as $item) {
            foreach (self::METHODS as $method) {
                if (isset($item[$method])) {
                    $ids[] = $item[$method]['operationId'] ?? null;
                }
            }
        }
        $this->assertNotContains(null, $ids, 'Every operation has an operationId.');
        $this->assertSame(array_unique($ids), $ids);

        array_walk_recursive($spec, function ($value, $key) use ($spec) {
            if ($key !== '$ref') {
                return;
            }
            $this->assertStringStartsWith('#/', $value);
            $node = $spec;
            foreach (explode('/', substr($value, 2)) as $part) {
                $this->assertIsArray($node);
                $this->assertArrayHasKey($part, $node, 'Unresolved reference '.$value);
                $node = $node[$part];
            }
        });
    }
}
