<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Component;
use App\Models\ComponentGroup;
use App\Models\StatusPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Security review E2: what machines and other sites read starts no session
 * and sets no cookie. Before, every widget poll was a row in the sessions
 * table and a Set-Cookie on an answer marked Cache-Control: public.
 */
class StatelessEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private string $upload = '';

    protected function setUp(): void
    {
        parent::setUp();
        // Database sessions, as an install runs them, so a started session is a row we can count.
        config(['session.driver' => 'database']);
        User::factory()->create(['role' => UserRole::Admin]);
    }

    protected function tearDown(): void
    {
        if ($this->upload !== '' && is_file($this->upload)) {
            unlink($this->upload);
        }
        parent::tearDown();
    }

    private function assertStateless(TestResponse $response, string $path): void
    {
        $this->assertSame([], $response->headers->getCookies(), $path.' set a cookie');
        $this->assertFalse($response->headers->has('Set-Cookie'), $path.' sent Set-Cookie');
    }

    public function test_machine_read_endpoints_start_no_session_and_set_no_cookie(): void
    {
        $component = Component::create(['name' => 'API']);
        $group = ComponentGroup::create(['name' => 'Core', 'visible' => true]);
        StatusPage::create(['name' => 'Second', 'slug' => 'second', 'is_published' => true]);
        $name = 'stateless-'.bin2hex(random_bytes(4)).'.png';
        $this->upload = storage_path('app/public/'.$name);
        file_put_contents($this->upload, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMB/axfL9kAAAAASUVORK5CYII='));

        $paths = [
            '/widget.json' => 200,
            '/embed.js' => 200,
            '/feed.xml' => 200,
            '/badges/components/'.$component->id.'.svg' => 200,
            '/badges/groups/'.$group->id.'.svg' => 200,
            '/reports.csv?month=2026-09' => 200,
            '/status/second/widget.json' => 200,
            '/status/second/feed.xml' => 200,
            '/status/second/embed.js' => 200,
            '/storage/'.$name => 200,
            '/metrics' => 401,
            '/cron/run' => 404,
        ];
        foreach ($paths as $path => $status) {
            // A browser that already has cookies for this host sends them along.
            $response = $this->withUnencryptedCookie('laravel_session', 'abc')->get($path);
            $this->assertSame($status, $response->getStatusCode(), $path);
            $this->assertStateless($response, $path);
        }
        $this->assertSame(0, DB::table('sessions')->count());

        // The cached answers stay cacheable, now without a cookie in them.
        $this->get('/widget.json')->assertHeader('Cache-Control', 'max-age=30, public');
    }

    public function test_the_pdf_download_is_stateless_too(): void
    {
        Component::create(['name' => 'API']);

        $this->assertStateless($this->get('/reports.pdf?month=2026-09')->assertOk(), '/reports.pdf');
        $this->assertSame(0, DB::table('sessions')->count());
    }

    public function test_pages_still_have_a_session_and_the_csrf_cookie(): void
    {
        foreach (['/', '/reports', '/admin/login'] as $path) {
            $cookies = collect($this->get($path)->assertOk()->headers->getCookies())->map->getName();
            $this->assertContains('XSRF-TOKEN', $cookies, $path);
            $this->assertContains(config('session.cookie'), $cookies, $path);
        }
        $this->assertGreaterThan(0, DB::table('sessions')->count());
    }

    public function test_stateless_answers_keep_the_security_headers(): void
    {
        $this->get('/widget.json')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get('/feed.xml')->assertHeader('X-Frame-Options', 'DENY');
    }
}
