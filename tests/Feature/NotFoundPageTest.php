<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\StatusPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
    protected function withoutCsrfNoise(string $html): string
    {
        return (string) preg_replace('/<meta name="csrf-token" content="[^"]*">/', '', $html);
    }
}
