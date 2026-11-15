<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\StatusPage;
use App\Models\User;
use App\Services\Branding;
use App\Services\License;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use RuntimeException;
use Tests\TestCase;

class NotFoundPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_visitor_on_an_unknown_page_gets_the_branded_404(): void
    {
        $out = $this->get('/status/klantportaal')->assertNotFound();

        $out->assertSee('This page does not exist');
        $out->assertSee('The address may be mistyped, or the page was moved or removed.');
        $out->assertSee('Go to the status page');
        $out->assertSee('/status/klantportaal');
        $out->assertSee('assets/not-found/scene.js', false);
        $out->assertSee('data-nf-scene', false);
    }

    public function test_the_404_does_not_tell_a_removed_page_from_one_that_never_was(): void
    {
        StatusPage::create(['name' => 'Archived', 'slug' => 'archived', 'is_published' => false, 'archived_at' => now()]);
        StatusPage::create(['name' => 'Draft', 'slug' => 'draft', 'is_published' => false]);

        $body = fn (string $slug) => str_replace($slug, 'x', $this->get("/status/{$slug}")->assertNotFound()->getContent());

        $never = $this->withoutCsrfNoise($body('never-there'));
        $this->assertSame($never, $this->withoutCsrfNoise($body('archived')));
        $this->assertSame($never, $this->withoutCsrfNoise($body('draft')));
    }

    public function test_someone_signed_in_on_an_old_admin_link_is_shown_the_way_back(): void
    {
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'correct-horse-battery', 'role' => UserRole::Admin]);

        $out = $this->actingAs($admin)->get('/admin/pages/999/overview')->assertNotFound();

        $out->assertSee('This part of the admin is gone.');
        $out->assertSee('Back to Status pages');
        $out->assertSee('href="'.route('admin.pages.index').'"', false);
        // The 404 stands on its own: no sidebar around it, even when signed in.
        $out->assertDontSee('class="shell"', false);
        $out->assertDontSee('Sign out');
    }

    public function test_a_page_user_is_sent_back_to_the_admin_not_to_the_page_list(): void
    {
        $member = User::create(['name' => 'Member', 'email' => 'member@example.com', 'password' => 'correct-horse-battery', 'role' => UserRole::User]);
        StatusPage::default()->users()->attach($member);

        $out = $this->actingAs($member)->get('/admin/pages/999/overview')->assertNotFound();

        $out->assertSee('Back to the admin');
        $out->assertDontSee('Back to Status pages');
    }

    public function test_the_requested_address_is_escaped_and_kept_short(): void
    {
        $out = $this->get('/status/%3Cb%3Ebold')->assertNotFound();
        $out->assertDontSee('<b>bold', false);
        $out->assertSee('&lt;b&gt;bold', false);

        $long = str_repeat('a', 300);
        $this->get('/status/'.$long)->assertNotFound()->assertDontSee($long);
    }

    public function test_an_api_404_stays_json(): void
    {
        $this->getJson('/api/v1/nothing-here')
            ->assertNotFound()
            ->assertHeader('content-type', 'application/json')
            ->assertDontSee('This page does not exist');
    }

    /** The session token differs per request and says nothing about the page. */
    /** The CSRF token and the script nonce are new on every response by design. */
    protected function withoutCsrfNoise(string $html): string
    {
        return (string) preg_replace(['/<meta name="csrf-token" content="[^"]*">/', '/ nonce="[^"]*"/'], '', $html);
    }

    /** Review S7: light, so a stray link or a scanner costs little. */
    public function test_the_404_is_light(): void
    {
        $body = $this->get('/status/nothing-here')->assertNotFound()->getContent();

        $this->assertLessThan(15 * 1024, strlen($body));
        $this->assertStringNotContainsString('pharos-ui.css', $body);
        $this->assertStringNotContainsString('<i class="o">', $body);
    }

    /** Review S7: the other errors wear the same shell instead of the framework's bare page. */
    public function test_other_errors_are_branded_too(): void
    {
        User::factory()->create();

        $this->post('/')->assertStatus(405)
            ->assertSee('Error 405')->assertSee('This address does not work that way')
            ->assertSee('Go to the status page')->assertDontSee('Oops');

        foreach ([419 => 'This form has expired', 429 => 'Slow down for a moment', 500 => 'Something went wrong on our side', 503 => 'Back in a moment'] as $code => $heading) {
            Route::get('/__error-'.$code, fn () => abort($code));
            $out = $this->get('/__error-'.$code)->assertStatus($code);
            $out->assertSee('Error '.$code)->assertSee($heading)->assertSee('class="pa-wall"', false);
            $this->assertLessThan(15 * 1024, strlen((string) $out->getContent()), (string) $code);
        }
    }

    public function test_a_public_form_that_expired_gets_the_419_page_not_the_admin_sign_in(): void
    {
        User::factory()->create();
        $this->app['env'] = 'production';
        try {
            $this->post('/subscribe', ['email' => 'ann@example.net'])->assertStatus(419)->assertSee('This form has expired');
            $this->post('/admin/login', ['email' => 'a@example.net', 'password' => 'x'])->assertRedirect(route('admin.login'));
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    public function test_a_server_error_still_renders_when_the_branding_cannot_be_read(): void
    {
        // What a missing database looks like to the page: every branding read throws.
        View::share('branding', new class(app(License::class)) extends Branding
        {
            public function name(): string
            {
                throw new RuntimeException('database is gone');
            }

            public function accent(): string
            {
                throw new RuntimeException('database is gone');
            }

            public function theme(): string
            {
                throw new RuntimeException('database is gone');
            }

            public function creditHidden(): bool
            {
                throw new RuntimeException('database is gone');
            }

            public function faviconUrl(): string
            {
                throw new RuntimeException('database is gone');
            }
        });

        $html = view('errors.500')->render();

        $this->assertStringContainsString('Something went wrong on our side', $html);
        $this->assertStringContainsString('--brand:#0079d2;', $html);
        $this->assertStringContainsString('<title>Server error · Pharos</title>', $html);
    }
}
