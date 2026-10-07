<?php

namespace Tests\Feature;

use Tests\TestCase;

class CachedApiRoutesTest extends TestCase
{
    public function test_feature_routes_compile_and_generate_independent_default_and_page_urls(): void
    {
        // Compilation follows route:cache's native Symfony route collection path.
        $this->assertNotEmpty(app('router')->getRoutes()->compile()['compiled']);
        $this->assertSame(url('/api/v1/metrics'), route('api.features.metrics'));
        $this->assertSame(url('/api/v1/pages/harbor/metrics'), route('page.api.features.metrics', ['slug' => 'harbor']));
        $this->assertSame(url('/api/v1/groups'), route('api.features.groups'));
        $this->assertSame(url('/api/v1/pages/harbor/groups'), route('page.api.features.groups', ['slug' => 'harbor']));
    }
}
