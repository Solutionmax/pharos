<?php

namespace Tests\Feature;

use App\Enums\IncidentStatus;
use App\Enums\UserRole;
use App\Http\Controllers\SubscribeController;
use App\Mail\SubscribeConfirmMail;
use App\Models\Component;
use App\Models\ComponentGroup;
use App\Models\Incident;
use App\Models\Setting;
use App\Models\StatusPage;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\PageContext;
use App\Services\Subscriptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
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

    /** Review S4: what a visitor reads can sit in a CDN, so it carries no cookie and says it may be kept. */
    public function test_visitor_pages_start_no_session_and_may_be_cached(): void
    {
        $incident = Incident::create(['name' => 'Short blip', 'status' => IncidentStatus::Resolved, 'occurred_at' => now()->subHour(), 'resolved_at' => now()]);
        $second = StatusPage::create(['name' => 'Second', 'slug' => 'second', 'is_published' => true]);
        $other = app(PageContext::class)->run($second->id, fn () => Incident::create(['name' => 'Elsewhere', 'status' => IncidentStatus::Resolved, 'occurred_at' => now()->subHour(), 'resolved_at' => now()]));

        foreach (['/', '/reports', '/incidents/'.$incident->id, '/status/second', '/status/second/incidents/'.$other->id] as $path) {
            $response = $this->get($path)->assertOk();
            $this->assertStateless($response, $path);
            $this->assertSame('max-age=30, public, stale-while-revalidate=300', $response->headers->get('Cache-Control'), $path);
            $this->assertContains('Cookie', $response->getVary(), $path);
        }
        $this->assertSame(0, DB::table('sessions')->count());
    }

    public function test_someone_with_a_session_keeps_it_and_the_admin_bar(): void
    {
        $admin = User::query()->firstOrFail();

        // A browser that has the session cookie gets the session back, and an answer no cache may share.
        $response = $this->actingAs($admin)->withUnencryptedCookie(config('session.cookie'), 'returning')->get('/')->assertOk();
        $cookies = collect($response->headers->getCookies())->map->getName();
        $this->assertContains(config('session.cookie'), $cookies);
        $this->assertStringNotContainsString('public', (string) $response->headers->get('Cache-Control'));
        $response->assertSee('Back to admin');
    }

    public function test_the_admin_sign_in_still_has_a_session_and_the_csrf_cookie(): void
    {
        $cookies = collect($this->get('/admin/login')->assertOk()->headers->getCookies())->map->getName();
        $this->assertContains('XSRF-TOKEN', $cookies);
        $this->assertContains(config('session.cookie'), $cookies);
        $this->assertGreaterThan(0, DB::table('sessions')->count());
    }

    /**
     * The page reaches a new visitor without a session, so "Get notified" asks
     * for its token when it opens. With CSRF checked for real, as on a server.
     */
    public function test_a_new_visitor_can_still_subscribe(): void
    {
        Mail::fake();
        config(['mail.default' => 'array']);
        $page = $this->get('/')->assertOk()->assertSee('Get notified')
            ->assertSee('data-token-url="'.url('/subscribe/token').'"', false);
        $this->assertStateless($page, '/');

        $this->app['env'] = 'production';
        try {
            // Without the token the form is refused, on a page that says what happened.
            $this->post('/subscribe', ['email' => 'ann@example.net'])->assertStatus(419)->assertSee('This form has expired');

            $answer = $this->getJson('/subscribe/token')->assertOk();
            $this->assertStringContainsString('no-store', (string) $answer->headers->get('Cache-Control'));
            $session = collect($answer->headers->getCookies())->first(fn ($cookie) => $cookie->getName() === config('session.cookie'));
            $this->assertNotNull($session, 'Asking for the token starts the session.');

            $this->withUnencryptedCookie(config('session.cookie'), (string) $session->getValue())
                ->post('/subscribe', ['_token' => $answer->json('token'), 'email' => 'ann@example.net'])
                ->assertRedirect('/')
                ->assertSessionHas('subscribed', SubscribeController::REPLY);
        } finally {
            $this->app['env'] = 'testing';
        }

        $this->assertSame(1, Subscriber::where('email', 'ann@example.net')->count());
        Mail::assertSent(SubscribeConfirmMail::class, 1);
    }

    public function test_the_token_is_not_handed_out_while_sign_up_is_off(): void
    {
        Setting::put(Subscriptions::KEY, '0');

        $this->getJson('/subscribe/token')->assertNotFound();
    }

    public function test_stateless_answers_keep_the_security_headers(): void
    {
        $this->get('/widget.json')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get('/feed.xml')->assertHeader('X-Frame-Options', 'DENY');
    }
}
