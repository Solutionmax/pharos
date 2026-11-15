<?php

namespace Tests\Feature;

use App\Enums\IncidentStatus;
use App\Enums\UserRole;
use App\Http\Middleware\SecurityHeaders;
use App\Models\Component;
use App\Models\ComponentGroup;
use App\Models\Incident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Vite;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Finder\Finder;
use Tests\Support\GrantsBrandPack;
use Tests\TestCase;

/**
 * Security review B2: a real Content-Security-Policy. Scripts run only from
 * this site or with the nonce of the response, so every <script> in the views
 * carries it and nothing relies on onclick= attributes.
 */
class ContentSecurityPolicyTest extends TestCase
{
    use GrantsBrandPack, RefreshDatabase;

    private User $admin;

    private Incident $incident;

    private Component $component;

    protected function setUp(): void
    {
        parent::setUp();
        $this->grantBrandPack();
        Http::fake();
        $this->admin = User::factory()->create(['role' => UserRole::Admin]);
        ComponentGroup::create(['name' => 'Core']);
        $this->component = Component::create(['name' => 'API']);
        $this->incident = Incident::create(['name' => 'Slow API', 'status' => IncidentStatus::Investigating, 'occurred_at' => now()]);
    }

    /** Returns the nonce after checking that the policy and every script agree on it. */
    private function assertScriptsCarryTheNonce(TestResponse $response, string $label): string
    {
        $policy = (string) $response->headers->get('Content-Security-Policy');
        $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-[A-Za-z0-9]{40}'(;|$)/", $policy, $label);
        $this->assertStringNotContainsString('unsafe-inline\' \'nonce', $policy);
        preg_match("/'nonce-([A-Za-z0-9]+)'/", $policy, $match);
        $nonce = $match[1];

        $html = (string) $response->getContent();
        preg_match_all('/<script\b[^>]*>/i', $html, $tags);
        $this->assertNotEmpty($tags[0], $label.' has no scripts to check');
        foreach ($tags[0] as $tag) {
            $this->assertStringContainsString('nonce="'.$nonce.'"', $tag, $label.': '.$tag);
        }
        // Handlers in attributes and javascript: links are blocked by the policy.
        $this->assertDoesNotMatchRegularExpression('/<[a-z][^>]*\son[a-z]+\s*=/i', $html, $label.' has an inline event handler');
        $this->assertDoesNotMatchRegularExpression('/(href|src|action)\s*=\s*["\']\s*javascript:/i', $html, $label);

        return $nonce;
    }

    public static function adminScreens(): array
    {
        return array_map(fn ($url) => [$url], [
            'overview' => '/admin/overview',
            'components' => '/admin/components',
            'add a component' => '/admin/components/create',
            'services' => '/admin/services',
            'incidents' => '/admin/incidents',
            'report an incident' => '/admin/incidents/create',
            'incident templates' => '/admin/incidents/templates',
            'new template' => '/admin/incidents/templates/create',
            'maintenance' => '/admin/maintenance',
            'schedule maintenance' => '/admin/maintenance/create',
            'status page' => '/admin/status-page',
            'subscribers' => '/admin/subscribers',
            'send out' => '/admin/integrations/send-out',
            'bring in' => '/admin/integrations/bring-in',
            'api tokens' => '/admin/integrations/tokens',
            'delivery log' => '/admin/integrations/log',
            'profile' => '/admin/profile',
            'users' => '/admin/users',
            'audit' => '/admin/audit',
            'updates' => '/admin/updates',
            'settings' => '/admin/settings',
            'branding' => '/admin/branding',
            'mail templates' => '/admin/mail-templates',
            'page mail' => '/admin/mail',
            'status pages' => '/admin/pages',
            'reports' => '/admin/reports',
        ]);
    }

    #[DataProvider('adminScreens')]
    public function test_every_admin_screen_runs_only_nonced_scripts(string $url): void
    {
        $this->assertScriptsCarryTheNonce($this->actingAs($this->admin)->get($url)->assertOk(), $url);
    }

    public function test_editing_screens_with_the_markdown_editor_run_only_nonced_scripts(): void
    {
        foreach (["/admin/incidents/{$this->incident->id}/update", "/admin/components/{$this->component->id}/edit"] as $url) {
            $this->assertScriptsCarryTheNonce($this->actingAs($this->admin)->get($url)->assertOk(), $url);
        }
    }

    public function test_public_and_signed_out_pages_run_only_nonced_scripts(): void
    {
        foreach (['/', '/reports', '/incidents/'.$this->incident->id, '/admin/login', '/admin/forgot-password'] as $url) {
            $this->assertScriptsCarryTheNonce($this->get($url)->assertOk(), $url);
        }
        // A 404 from a page route renders inside the middleware, with the policy.
        $this->assertScriptsCarryTheNonce($this->get('/incidents/999999')->assertNotFound(), 'not found');
    }

    public function test_the_nonce_is_new_for_every_response(): void
    {
        $first = $this->assertScriptsCarryTheNonce($this->get('/'), 'first');
        $second = $this->assertScriptsCarryTheNonce($this->get('/'), 'second');

        $this->assertNotSame($first, $second);
    }

    public function test_the_policy_is_strict_where_it_matters(): void
    {
        config(['app.url' => 'https://status.example.com']);
        $policy = (string) $this->get('/admin/login')->headers->get('Content-Security-Policy');

        foreach ([
            "default-src 'self'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: blob: https://status.example.com",
            "font-src 'self' data:",
            "connect-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self' https://status.example.com",
            "frame-ancestors 'none'",
        ] as $directive) {
            $this->assertStringContainsString($directive, $policy);
        }
        $this->assertDoesNotMatchRegularExpression("/script-src[^;]*'unsafe-(inline|eval)'/", $policy);
        $this->assertStringNotContainsString('https:;', $policy.';');
    }

    public function test_a_strange_app_url_never_ends_up_in_the_header(): void
    {
        config(['app.url' => "https://status.example.com\r\nX-Evil: 1"]);
        $policy = SecurityHeaders::policy();

        $this->assertStringNotContainsString('Evil', $policy);
        $this->assertStringContainsString("img-src 'self' data: blob:;", $policy);
    }

    public function test_the_two_framed_previews_get_the_full_policy_with_their_own_origin_as_frame_ancestor(): void
    {
        foreach (['/admin/status-page/preview?m[page.show_services]=1', '/admin/mail-templates/preview?template=incident_opened'] as $url) {
            $response = $this->actingAs($this->admin)->get($url)->assertOk()->assertHeader('X-Frame-Options', 'SAMEORIGIN');
            $policy = (string) $response->headers->get('Content-Security-Policy');
            $this->assertStringContainsString("frame-ancestors 'self'", $policy, $url);
            $this->assertStringContainsString("object-src 'none'", $policy, $url);
        }

        $this->assertScriptsCarryTheNonce($this->actingAs($this->admin)->get('/admin/status-page/preview?m[page.show_services]=1'), 'preview');
    }

    public function test_badges_and_uploads_keep_their_own_policy(): void
    {
        $this->get('/badges/components/'.$this->component->id.'.svg')->assertOk()
            ->assertHeader('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; sandbox allow-popups");
    }

    public function test_no_view_has_a_script_without_the_nonce_or_an_inline_handler(): void
    {
        $files = Finder::create()->files()->in(resource_path('views'))->name('*.blade.php');
        $this->assertGreaterThan(50, count($files));

        foreach ($files as $file) {
            foreach (preg_split('/\R/', $file->getContents()) as $number => $line) {
                $where = $file->getRelativePathname().':'.($number + 1);
                // A script shown as text (the embed snippet) is escaped output, not a tag.
                $tags = preg_replace('/\{\{.*?\}\}/', '', $line);
                if (preg_match_all('/<script\b[^>]*/i', $tags, $found)) {
                    foreach ($found[0] as $tag) {
                        $this->assertStringContainsString('@nonce', $tag, $where);
                    }
                }
                $this->assertDoesNotMatchRegularExpression('/<[a-z][^>]*\son(click|submit|change|input|load|error|keydown|keyup|focus|blur)\s*=/i', $tags, $where);
            }
        }
    }

    public function test_a_view_rendered_outside_a_request_prints_no_empty_nonce(): void
    {
        app(Vite::class)->useCspNonce('');
        $this->assertSame('', SecurityHeaders::nonceAttribute());
    }
}
