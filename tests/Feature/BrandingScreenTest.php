<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Http\Controllers\Admin\BrandingController;
use App\Models\Setting;
use App\Models\StatusPage;
use App\Models\User;
use App\Services\License;
use App\Services\PageContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BrandingScreenTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected string $secret;

    protected function setUp(): void
    {
        parent::setUp();

        $pair = sodium_crypto_sign_keypair();
        $this->secret = sodium_crypto_sign_secretkey($pair);
        config(['pharos.license_public_key' => sodium_bin2hex(sodium_crypto_sign_publickey($pair))]);
        Cache::flush();
        Storage::fake('public');

        $this->admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => 'correct-horse-battery',
            'role' => UserRole::Admin,
        ]);
    }

    public function test_a_free_installation_explains_what_is_locked_and_what_unlocks_it(): void
    {
        $this->actingAs($this->admin)->get('/admin/branding')->assertOk()
            ->assertSeeInOrder(['What this installation has', 'Free', 'Brand pack', 'Not included', 'Multi page', 'Not included'])
            ->assertSee('No licence key yet.')
            ->assertSee('Unlocked by the <b>Brand pack</b>', false)
            ->assertSee('Enter a licence key')
            ->assertSee('href="'.config('pharos.buy_url').'"', false)
            ->assertSee('id="licence"', false)
            // Locked sections carry no inputs that the server would ignore anyway.
            ->assertDontSee('name="logo"', false)
            ->assertDontSee('name="credit_hidden"', false)
            ->assertSee('name="name"', false)
            ->assertSee('name="accent"', false);
    }

    public function test_the_upload_hints_come_from_the_validation_rules(): void
    {
        $this->license([License::FEATURE_BRAND_PACK]);

        $this->actingAs($this->admin)->get('/admin/branding')->assertOk()
            ->assertSee('PNG, JPG or WebP. Up to 512 KB, at most 1200 × 400 pixels.')
            ->assertSee('PNG, ICO or WebP. Up to 128 KB, at most 512 × 512 pixels.')
            ->assertSee('name="logo"', false)
            ->assertSee('name="logo_dark"', false)
            ->assertSee('name="favicon"', false)
            ->assertSee('name="credit_hidden"', false);

        $this->assertContains('max:512', BrandingController::uploadRules('logo'));
        $this->assertContains('dimensions:max_width=512,max_height=512', BrandingController::uploadRules('favicon'));
    }

    public function test_a_logo_says_whether_it_is_set_for_this_page(): void
    {
        $this->license([License::FEATURE_BRAND_PACK, License::FEATURE_MULTI_PAGES]);
        $other = StatusPage::query()->create(['name' => 'Harbor', 'slug' => 'harbor', 'is_published' => true]);

        $this->actingAs($this->admin)->put('/admin/branding', [
            'name' => 'Northwind', 'accent' => '#b8532f',
            'logo' => UploadedFile::fake()->image('logo.png', 400, 120),
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->admin)->get('/admin/branding')->assertOk()
            ->assertSee('Set for this page')->assertSee('Replace')->assertSee('name="remove_logo"', false);

        // The second page inherits nothing from the default one.
        $this->actingAs($this->admin)->get('/admin/pages/'.$other->id.'/branding')->assertOk()
            ->assertSee('Branding for Harbor')
            ->assertSee('Nothing is inherited from the default page')
            ->assertSee('Pharos default')
            ->assertDontSee('Set for this page');
    }

    public function test_the_plan_card_counts_pages_against_the_limit(): void
    {
        $this->license([License::FEATURE_MULTI_PAGES], limit: 5);
        StatusPage::query()->create(['name' => 'Harbor', 'slug' => 'harbor']);

        $this->actingAs($this->admin)->get('/admin/branding')->assertOk()
            ->assertSee('2 of 5 pages in use')
            ->assertSee('2 / 5');
    }

    public function test_the_four_plans_render_with_the_current_one_marked(): void
    {
        $cases = [
            'free' => [],
            'brand_pack' => [[License::FEATURE_BRAND_PACK]],
            'supported' => [[License::FEATURE_BRAND_PACK, License::FEATURE_MULTI_PAGES], 5],
            'commercial' => [[License::FEATURE_BRAND_PACK, License::FEATURE_MULTI_PAGES]],
        ];
        $portal = 'https://pharos.solutionmax.net/account/buy/';

        foreach ($cases as $current => $key) {
            $key === [] ? Setting::put('license.key', null) : $this->license(...$key);
            Cache::forget('license.payload');

            $html = $this->actingAs($this->admin)->get('/admin/branding')->assertOk()
                ->assertSeeInOrder(['Free', 'Always', 'Brand pack', 'One time', 'Supported', 'Yearly', 'Up to 5 status pages', 'Commercial', 'Yearly', 'Unlimited status pages'])
                ->assertSee('href="https://pharos.solutionmax.net/#pricing"', false)
                ->assertSee('Compare plans')
                ->getContent();

            preg_match_all('/data-plan="([a-z_]+)"\s+aria-current="true"/', $html, $marked);
            $this->assertSame([$current], $marked[1], "current plan for $current");
            $this->assertSame(1, substr_count($html, '>Current<'));

            // Only plans above the current one get a buy button, and it goes to the portal.
            $order = ['free', 'brand_pack', 'supported', 'commercial'];
            foreach (['brand_pack' => 'brand-pack', 'supported' => 'supported', 'commercial' => 'commercial'] as $plan => $slug) {
                $above = array_search($plan, $order) > array_search($current, $order);
                $this->assertSame($above, str_contains($html, 'href="'.$portal.$slug.'"'), "$plan button while $current");
            }
            $this->assertStringNotContainsString('€', $html);
        }
    }

    public function test_a_multi_page_only_key_with_a_ceiling_reads_as_supported(): void
    {
        $this->license([License::FEATURE_MULTI_PAGES], limit: 5);

        $html = $this->actingAs($this->admin)->get('/admin/branding')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/data-plan="supported"\s+aria-current="true"/', $html);
    }

    public function test_an_unlimited_multi_page_key_says_so(): void
    {
        $this->license([License::FEATURE_MULTI_PAGES]);

        $this->actingAs($this->admin)->get('/admin/branding')->assertOk()
            ->assertSee('1 page in use, no limit')
            ->assertSee('Unlimited');
    }

    public function test_a_multi_page_key_without_the_brand_pack_is_shown_as_active(): void
    {
        // Regression: the licence panel only knew the Brand pack, so a Multi page
        // key read as "Not activated" and could not be removed from the screen.
        $this->license([License::FEATURE_MULTI_PAGES]);

        $this->actingAs($this->admin)->get('/admin/branding')->assertOk()
            ->assertSee('Active for the whole installation')
            ->assertDontSee('Not activated')
            ->assertSee('admin/branding/deactivate');
    }

    public function test_activating_names_the_plan_instead_of_always_saying_brand_pack(): void
    {
        $this->actingAs($this->admin)->post('/admin/branding/activate', ['key' => $this->key([License::FEATURE_MULTI_PAGES])])
            ->assertSessionHas('status', 'Key activated for customer@example.com. Plan: Commercial.');
    }

    public function test_an_expired_key_reports_what_stayed_and_never_counts_negative_days(): void
    {
        // Regression: daysLeft() is negative once the date has passed, so the
        // screen said "Runs out in -53 days" for a key that had already ended.
        $this->license([License::FEATURE_BRAND_PACK, License::FEATURE_MULTI_PAGES], expiresAt: now()->subDays(20)->toDateString());
        StatusPage::query()->create(['name' => 'Harbor', 'slug' => 'harbor']);

        $this->assertFalse(app(License::class)->expiringSoon());

        $this->actingAs($this->admin)->get('/admin/branding')->assertOk()
            ->assertDontSee('Runs out')
            ->assertSee('Active, kept')
            ->assertSee('Ended')
            ->assertSee('The term ended on')
            ->assertSee('the Brand pack stays')
            ->assertSee('new pages cannot be created and archived pages cannot be reactivated');
    }

    public function test_a_page_administrator_sees_the_plan_but_not_the_key(): void
    {
        $this->license([License::FEATURE_BRAND_PACK, License::FEATURE_MULTI_PAGES]);
        $page = StatusPage::query()->create(['name' => 'Harbor', 'slug' => 'harbor']);
        $member = User::create(['name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'correct-horse-battery', 'role' => UserRole::User]);
        $member->statusPages()->attach($page->id, ['role' => 'admin']);

        $this->actingAs($member)->get('/admin/pages/'.$page->id.'/branding')->assertOk()
            ->assertSee('What this installation has')
            ->assertSee('Only an installation administrator can add or change the licence key.')
            ->assertDontSee('name="key"', false)
            ->assertDontSee('href="#licence"', false);
    }

    public function test_an_ico_favicon_is_accepted(): void
    {
        // Regression: the "image" rule knows no ICO, so the ICO the form offered was always refused.
        $this->license([License::FEATURE_BRAND_PACK]);

        $this->actingAs($this->admin)->put('/admin/branding', [
            'name' => 'Northwind', 'accent' => '#b8532f', 'favicon' => $this->ico(),
        ])->assertSessionHasNoErrors();

        $path = Setting::get('brand.favicon_path');
        $this->assertNotNull($path);
        Storage::disk('public')->assertExists($path);
    }

    public function test_a_favicon_can_be_removed(): void
    {
        $this->license([License::FEATURE_BRAND_PACK]);
        $this->actingAs($this->admin)->put('/admin/branding', [
            'name' => 'Northwind', 'accent' => '#b8532f', 'favicon' => UploadedFile::fake()->image('icon.png', 64, 64),
        ]);
        $path = Setting::get('brand.favicon_path');

        $this->actingAs($this->admin)->get('/admin/branding')->assertSee('name="remove_favicon"', false);
        $this->actingAs($this->admin)->put('/admin/branding', [
            'name' => 'Northwind', 'accent' => '#b8532f', 'remove_favicon' => '1',
        ])->assertSessionHasNoErrors();

        $this->assertNull(Setting::get('brand.favicon_path'));
        Storage::disk('public')->assertMissing($path);
        $this->get('/')->assertSee('pharos-favicon.svg');
    }

    public function test_a_favicon_that_is_not_an_icon_type_is_still_refused(): void
    {
        $this->license([License::FEATURE_BRAND_PACK]);

        $this->actingAs($this->admin)->put('/admin/branding', [
            'name' => 'Northwind', 'accent' => '#b8532f',
            'favicon' => UploadedFile::fake()->create('icon.svg', 2, 'image/svg+xml'),
        ])->assertSessionHasErrors('favicon');
        $this->actingAs($this->admin)->put('/admin/branding', [
            'name' => 'Northwind', 'accent' => '#b8532f',
            'favicon' => UploadedFile::fake()->image('big.png', 800, 800),
        ])->assertSessionHasErrors('favicon');
    }

    public function test_a_lapsed_logo_is_reported_as_saved_but_hidden(): void
    {
        Storage::disk('public')->put('brand/left.png', 'png');
        app(PageContext::class)->run(StatusPage::defaultId(), fn () => Setting::put('brand.logo_path', 'brand/left.png'));

        $this->actingAs($this->admin)->get('/admin/branding')->assertOk()
            ->assertSee('A logo is saved for this page. It shows again as soon as a key with the Brand pack is active.');
    }

    protected function ico(): UploadedFile
    {
        $image = imagecreatetruecolor(32, 32);
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();
        $path = tempnam(sys_get_temp_dir(), 'ico');
        // ICONDIR plus one entry that embeds the PNG, as modern icon files do.
        file_put_contents($path, pack('vvv', 0, 1, 1).pack('CCCCvvVV', 32, 32, 0, 0, 1, 32, strlen($png), 22).$png);

        return new UploadedFile($path, 'favicon.ico', null, null, true);
    }

    protected function license(array $features, ?int $limit = null, ?string $expiresAt = null): void
    {
        Setting::put('license.key', $this->key($features, $limit, $expiresAt));
        Cache::forget('license.payload');
    }

    /** @param list<string> $features */
    protected function key(array $features, ?int $limit = null, ?string $expiresAt = null): string
    {
        $payload = array_filter([
            'product' => 'pharos',
            'issued_to' => 'customer@example.com',
            'features' => $features,
            'limits' => $limit === null ? null : ['status_pages' => $limit],
            'expires_at' => $expiresAt,
        ], fn ($value) => $value !== null);

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $b64 = fn (string $raw) => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');

        return $b64($json).'.'.$b64(sodium_crypto_sign_detached($json, $this->secret));
    }
}
