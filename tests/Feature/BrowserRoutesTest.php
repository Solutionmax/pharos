<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\BrowserUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Js;
use Tests\TestCase;

class BrowserRoutesTest extends TestCase
{
    use RefreshDatabase;

    public function test_browser_requests_keep_the_public_origin_behind_tls_termination(): void
    {
        $user = User::create([
            'name' => 'Admin', 'email' => 'admin@example.test',
            'password' => 'local-test-password', 'role' => UserRole::Admin,
        ]);
        $this->actingAs($user);

        // PHP sees the internal HTTP origin, while the browser uses public HTTPS.
        // A subdirectory must survive when omitting that internal origin.
        URL::forceRootUrl('http://internal.example.test/pharos');
        try {
            $component = $this->get('http://internal.example.test/admin/components/create')->assertOk();
            $component->assertSee((string) Js::from('/pharos/admin/services'), false)
                ->assertSee((string) Js::from('/pharos/admin/components/tags/__TAG__'), false)
                ->assertDontSee('fetch("http:', false);

            $this->get('http://internal.example.test/admin/status-page')->assertOk()
                ->assertSee('var base = '.Js::from('/pharos/admin/status-page/preview').';', false);

            $this->get('http://internal.example.test/admin/mail-templates')->assertOk()
                ->assertSee('src="/pharos/admin/mail-templates/preview?template=', false)
                ->assertSee('var url = '.Js::from('/pharos/admin/mail-templates/preview').';', false);
        } finally {
            URL::forceRootUrl(null);
        }
    }

    public function test_browser_urls_keep_a_real_hosting_subdirectory_and_query_string(): void
    {
        $request = Request::create(
            'http://internal.example.test/pharos/admin/status-page',
            server: ['SCRIPT_NAME' => '/pharos/index.php', 'SCRIPT_FILENAME' => '/var/www/pharos/index.php'],
        );
        $this->assertSame('/pharos', $request->getBaseUrl());
        URL::setRequest($request);

        $this->assertSame('/pharos/admin/status-page/preview', BrowserUrl::route('admin.status-page.preview'));
        $this->assertSame('/pharos/admin/mail-templates/preview?template=incident_opened', BrowserUrl::route('admin.mail-templates.preview', ['template' => 'incident_opened']));
    }

    public function test_configured_proxy_headers_generate_https_form_actions(): void
    {
        User::create(['name' => 'Admin', 'email' => 'owner@example.test', 'password' => 'local-test-password']);
        config(['trustedproxy.proxies' => '10.10.0.0/24']);
        $this->withServerVariables([
            'REMOTE_ADDR' => '10.10.0.2',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_HOST' => 'status.example.test',
            'HTTP_X_FORWARDED_PORT' => '443',
        ])->get('http://internal.example.test/admin/login')->assertOk()
            ->assertSee('action="https://status.example.test/admin/login"', false);
    }

    public function test_an_untrusted_client_cannot_override_the_form_origin(): void
    {
        User::create(['name' => 'Admin', 'email' => 'owner@example.test', 'password' => 'local-test-password']);
        config(['trustedproxy.proxies' => '10.10.0.0/24']);
        $this->withServerVariables([
            'REMOTE_ADDR' => '192.0.2.15',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_HOST' => 'attacker.example.test',
        ])->get('http://internal.example.test/admin/login')->assertOk()
            ->assertSee('action="http://internal.example.test/admin/login"', false)
            ->assertDontSee('attacker.example.test');
    }
}
