<?php

namespace Tests\Feature;

use App\Enums\ComponentStatus;
use App\Enums\IncidentStatus;
use App\Models\Component;
use App\Models\Incident;
use App\Models\Setting;
use App\Models\User;
use App\Services\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The signed out screens ("Pulse"): what the server renders, which is all a
 * browser without JavaScript gets. The fetch flow itself lives in pharos-auth.js.
 */
class SignInPageTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'name' => 'Raymon', 'email' => 'raymon@example.com',
            'password' => Hash::make('correct-horse-battery'),
        ]);
    }

    /** Visible text only: what a person reads, without scripts, styles and attributes. */
    protected function visibleText(TestResponse $response): string
    {
        return strip_tags(preg_replace('~<(script|style)\b[^>]*>.*?</\1>~si', '', $response->getContent()));
    }

    protected function toTwoFactor(): void
    {
        $this->user->forceFill(['totp_secret' => (new Totp)->secret(), 'totp_confirmed_at' => now()])->save();
        $this->post('/admin/login', ['email' => 'raymon@example.com', 'password' => 'correct-horse-battery'])
            ->assertRedirect('/admin/two-factor');
    }

    public function test_the_sign_in_page_carries_the_brand_name_and_logo(): void
    {
        $this->get('/admin/login')->assertOk()
            ->assertSee('Sign in to Pharos')
            ->assertSee('brand/pharos-logo.svg', false)
            ->assertSee('Powered by Pharos');

        Setting::put('brand.name', 'Acme Hosting');
        Setting::put('brand.accent', '#aa3366');

        $this->get('/admin/login')->assertOk()
            ->assertSee('Sign in to Acme Hosting')
            ->assertSee('brand/pharos-mark.svg', false)
            ->assertSee('--brand:#aa3366', false);
    }

    public function test_the_auth_assets_are_loaded_with_a_cache_buster(): void
    {
        $this->get('/admin/login')->assertOk()
            ->assertSee('assets/pharos-auth.css?v='.filemtime(public_path('assets/pharos-auth.css')), false)
            ->assertSee('assets/pharos-auth.js?v='.filemtime(public_path('assets/pharos-auth.js')), false);
    }

    public function test_the_public_sign_in_screens_show_no_status_data(): void
    {
        Component::create(['name' => 'Synology archive', 'status' => ComponentStatus::MajorOutage]);
        Component::create(['name' => 'Billing portal', 'status' => ComponentStatus::Operational]);
        Incident::create(['name' => 'Mail queue backed up', 'status' => IncidentStatus::Investigating, 'occurred_at' => now()]);

        $pages = ['/admin/login', '/admin/forgot-password'];
        foreach ($pages as $url) {
            $text = $this->visibleText($this->get($url)->assertOk());

            foreach (['Synology archive', 'Billing portal', 'Mail queue backed up', 'operational', 'major outage', 'all systems', 'components up', 'up right now'] as $leak) {
                $this->assertStringNotContainsStringIgnoringCase($leak, $text, "$url must not show \"$leak\"");
            }
            $this->assertDoesNotMatchRegularExpression('~\b\d+\s*/\s*\d+\b~', $text, "$url must not show a count like 12/12");
        }

        $this->toTwoFactor();
        $text = $this->visibleText($this->get('/admin/two-factor')->assertOk());
        $this->assertStringNotContainsStringIgnoringCase('Synology archive', $text);
        $this->assertStringNotContainsStringIgnoringCase('operational', $text);
    }

    public function test_the_sign_in_form_still_posts_without_javascript(): void
    {
        $this->get('/admin/login')->assertOk()
            ->assertSee('method="POST" action="'.route('admin.login.attempt').'"', false)
            ->assertSee('name="_token"', false)
            ->assertSee('name="email"', false)
            ->assertSee('name="password"', false)
            ->assertSee('name="remember" value="1"', false)
            ->assertSee('<label for="email">Email</label>', false)
            ->assertSee('<label for="password">Password</label>', false)
            ->assertSee('data-pa-fail="'.route('admin.login').'"', false)
            ->assertSee('data-pa-step="'.route('admin.two-factor').'"', false)
            ->assertSee('Stay signed in')
            ->assertSee('Forgot password?');
    }

    public function test_the_error_summary_is_an_alert_and_is_filled_after_a_refusal(): void
    {
        $this->get('/admin/login')->assertOk()
            ->assertSee('role="alert" data-pa-errors hidden', false);

        $this->from('/admin/login')
            ->post('/admin/login', ['email' => 'raymon@example.com', 'password' => 'nope'])
            ->assertRedirect('/admin/login');

        // The page the redirect ends on is what the script parses its messages from.
        $this->get('/admin/login')->assertOk()
            ->assertSee('role="alert" data-pa-errors', false)
            ->assertDontSee('data-pa-errors hidden', false)
            ->assertSee('<li>Those details do not match an account.</li>', false)
            ->assertSee('value="raymon@example.com"', false);
    }

    public function test_the_status_flash_and_the_rollback_notice_still_show(): void
    {
        $this->withSession(['status' => 'Your password has been reset.'])
            ->get('/admin/login')->assertOk()
            ->assertSee('data-pa-flash>Your password has been reset.', false);

        $this->get('/admin/login?after=rollback')->assertOk()
            ->assertSee('Rolled back to a backup.');
    }

    public function test_the_two_factor_page_has_six_boxes_a_recovery_toggle_and_a_plain_code_field(): void
    {
        $this->toTwoFactor();

        $response = $this->get('/admin/two-factor')->assertOk()
            ->assertSee('role="group" aria-labelledby="pa-codes-label"', false)
            ->assertSee('id="pa-codes-label">Six digit code', false)
            ->assertSee('data-pa-mode hidden>Use a recovery code</button>', false)
            ->assertSee('data-pa-fail="'.route('admin.two-factor').'"', false)
            ->assertSee('data-pa-back="'.route('admin.login').'"', false);

        $html = $response->getContent();
        for ($i = 1; $i <= 6; $i++) {
            $this->assertStringContainsString('aria-label="Digit '.$i.' of 6"', $html);
        }
        $this->assertSame(6, substr_count($html, 'data-pa-digit'));

        // Without JavaScript the one real field does the work: the boxes carry no name.
        $this->assertSame(1, preg_match_all('~<input[^>]*\bname="code"~', $html));
        $this->assertMatchesRegularExpression('~<input id="code" name="code" type="text"[^>]*required~', $html);
        $this->assertDoesNotMatchRegularExpression('~<input[^>]*data-pa-digit[^>]*name=~', $html);
        $this->assertStringContainsString('<label for="code" data-pa-code-label>Code</label>', $html);
    }

    public function test_the_signed_out_screens_use_no_dashes_in_their_words(): void
    {
        $texts = [$this->visibleText($this->get('/admin/login')), $this->visibleText($this->get('/admin/forgot-password'))];
        $this->toTwoFactor();
        $texts[] = $this->visibleText($this->get('/admin/two-factor'));

        foreach ($texts as $text) {
            $this->assertStringNotContainsString('—', $text);
            $this->assertStringNotContainsString('–', $text);
            // Hosts like web-01 are names, not words joined by a hyphen.
            $this->assertDoesNotMatchRegularExpression('~\b[A-Za-z]{2,}-[A-Za-z]{2,}\b~', $text);
        }
    }
}
