<?php

namespace Tests\Feature;

use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_page_carries_the_browser_hardening_headers(): void
    {
        foreach (['/', '/admin/login'] as $path) {
            $this->get($path)
                ->assertHeader('X-Content-Type-Options', 'nosniff')
                ->assertHeader('X-Frame-Options', 'DENY')
                ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
                ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()')
                ->assertHeader('Content-Security-Policy', "frame-ancestors 'none'");
        }
    }

    public function test_a_header_the_proxy_already_set_is_left_alone(): void
    {
        // A reverse proxy in front may carry its own policy; ours fills gaps only.
        $response = (new SecurityHeaders)->handle(
            Request::create('/'),
            fn () => response('ok')->header('X-Frame-Options', 'SAMEORIGIN'),
        );

        $this->assertSame('SAMEORIGIN', $response->headers->get('X-Frame-Options'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }
}
