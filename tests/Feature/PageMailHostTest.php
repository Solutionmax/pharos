<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Mail\TestMail;
use App\Models\StatusPage;
use App\Models\User;
use App\Services\MailConfig;
use App\Services\PageContext;
use App\Services\SafeHttp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Page SMTP set by a page administrator must not turn Send test into a blind
 * probe of the server's own networks. Installation administrators may still
 * point a page at an internal relay.
 */
class PageMailHostTest extends TestCase
{
    use RefreshDatabase;

    protected StatusPage $page;

    protected User $pageAdmin;

    protected User $globalAdmin;

    /** @var array<string, list<string>> what the fake DNS answers */
    protected array $dns = [
        'smtp.public.test' => ['203.0.113.25'],
        'smtp.internal.test' => ['10.1.2.3'],
        'smtp.mixed.test' => ['203.0.113.25', '192.168.1.5'],
        'smtp.cgnat.test' => ['100.64.8.9'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->page = StatusPage::create(['name' => 'Beta page', 'slug' => 'beta']);
        $this->globalAdmin = User::factory()->create(['role' => UserRole::Admin]);
        $this->pageAdmin = User::factory()->create(['role' => UserRole::User, 'email' => 'pageadmin@example.test']);
        $this->pageAdmin->statusPages()->attach($this->page->id, ['role' => 'admin']);

        $test = $this;
        $this->app->instance(SafeHttp::class, new class($test) extends SafeHttp
        {
            public function __construct(private PageMailHostTest $test)
            {
                parent::__construct();
            }

            public function addresses(string $host): array
            {
                return filter_var($host, FILTER_VALIDATE_IP) ? [$host] : ($this->test->answers()[$host] ?? []);
            }
        });
    }

    /** @return array<string, list<string>> */
    public function answers(): array
    {
        return $this->dns;
    }

    /** @param array<string, mixed> $overrides */
    protected function form(array $overrides = []): array
    {
        return array_merge([
            'mode' => 'custom',
            'host' => 'smtp.public.test',
            'port' => 587,
            'encryption' => 'tls',
            'username' => '',
            'password' => '',
            'from_address' => 'status@beta.example.test',
            'from_name' => '',
            'reply_to' => '',
        ], $overrides);
    }

    protected function save(User $user, array $overrides = [])
    {
        return $this->actingAs($user)->put('/admin/pages/'.$this->page->id.'/mail', $this->form($overrides));
    }

    protected function storedHost(): string
    {
        return app(PageContext::class)->run($this->page->id, fn () => app(MailConfig::class)->storedPage()['host']);
    }

    public static function internalHosts(): array
    {
        return [
            'loopback' => ['127.0.0.1'],
            'private 10.x' => ['10.0.0.5'],
            'private 192.168.x' => ['192.168.18.162'],
            'link local' => ['169.254.169.254'],
            'CGNAT' => ['100.64.1.1'],
            'IPv6 loopback' => ['::1'],
            'IPv6 loopback in brackets' => ['[::1]'],
            'IPv6 unique local' => ['fd00::25'],
            'IPv4 mapped IPv6' => ['::ffff:10.0.0.1'],
            'name on a private network' => ['smtp.internal.test'],
            'name with one private answer' => ['smtp.mixed.test'],
            'name on CGNAT' => ['smtp.cgnat.test'],
        ];
    }

    #[DataProvider('internalHosts')]
    public function test_a_page_admin_cannot_point_page_smtp_at_an_internal_host(string $host): void
    {
        $this->save($this->pageAdmin, ['host' => $host])
            ->assertSessionHasErrors(['host' => 'The SMTP host must be a public mail server. '.trim($host, '[]').' is on a private or local network, and only an installation administrator can use such a server here.'])
            ->assertSessionDoesntHaveErrors('port');

        $this->assertSame('', $this->storedHost());
    }

    public function test_a_page_admin_cannot_use_a_name_that_resolves_to_this_machine(): void
    {
        // The real resolver: localhost is 127.0.0.1 on every system.
        $this->app->forgetInstance(SafeHttp::class);
        $this->app->offsetUnset(SafeHttp::class);

        $this->save($this->pageAdmin, ['host' => 'localhost'])->assertSessionHasErrors('host');
        $this->assertSame('', $this->storedHost());
    }

    public function test_a_page_admin_cannot_use_a_name_that_does_not_resolve(): void
    {
        $this->save($this->pageAdmin, ['host' => 'smtp.nowhere.test'])
            ->assertSessionHasErrors(['host' => 'The SMTP host smtp.nowhere.test could not be resolved. Check the name.']);
        $this->assertSame('', $this->storedHost());
    }

    public function test_a_page_admin_can_use_a_public_host(): void
    {
        $this->save($this->pageAdmin)->assertSessionHasNoErrors()->assertRedirect('/admin/pages/'.$this->page->id.'/mail');
        $this->assertSame('smtp.public.test', $this->storedHost());

        $this->save($this->pageAdmin, ['host' => '203.0.113.40'])->assertSessionHasNoErrors();
        $this->assertSame('203.0.113.40', $this->storedHost());
    }

    public function test_a_page_admin_switching_to_central_mail_is_not_checked(): void
    {
        $this->save($this->pageAdmin, ['mode' => 'central', 'host' => '', 'port' => ''])->assertSessionHasNoErrors();
    }

    public function test_a_global_admin_may_use_an_internal_relay(): void
    {
        $this->save($this->globalAdmin, ['host' => '127.0.0.1'])->assertSessionHasNoErrors();
        $this->assertSame('127.0.0.1', $this->storedHost());

        $this->save($this->globalAdmin, ['host' => 'smtp.internal.test'])->assertSessionHasNoErrors();
        $this->assertSame('smtp.internal.test', $this->storedHost());
    }

    public function test_send_test_checks_the_host_again_when_a_page_admin_set_it(): void
    {
        Mail::fake();
        $this->save($this->pageAdmin)->assertSessionHasNoErrors();

        // The name moved to a private address after it was saved.
        $this->dns['smtp.public.test'] = ['10.9.9.9'];

        $this->actingAs($this->pageAdmin)->post('/admin/pages/'.$this->page->id.'/mail-test')
            ->assertRedirect('/admin/pages/'.$this->page->id.'/mail')
            ->assertSessionHasErrors(['mail' => 'The SMTP host must be a public mail server. smtp.public.test is on a private or local network, and only an installation administrator can use such a server here.']);
        Mail::assertNothingSent();
    }

    public function test_send_test_by_a_page_admin_works_on_a_relay_a_global_admin_set(): void
    {
        Mail::fake();
        $this->save($this->globalAdmin, ['host' => 'smtp.internal.test'])->assertSessionHasNoErrors();

        $this->actingAs($this->pageAdmin)->post('/admin/pages/'.$this->page->id.'/mail-test')
            ->assertSessionHas('status', 'Test email sent to pageadmin@example.test.');
        Mail::assertSent(TestMail::class);
    }

    public function test_a_page_admin_saving_again_takes_the_trust_away(): void
    {
        Mail::fake();
        $this->save($this->globalAdmin, ['host' => 'smtp.internal.test'])->assertSessionHasNoErrors();
        $this->save($this->pageAdmin, ['host' => 'smtp.internal.test'])->assertSessionHasErrors('host');
        $this->save($this->pageAdmin)->assertSessionHasNoErrors();
        $this->dns['smtp.public.test'] = ['127.0.0.1'];

        $this->actingAs($this->pageAdmin)->post('/admin/pages/'.$this->page->id.'/mail-test')->assertSessionHasErrors('mail');
        Mail::assertNothingSent();
    }

    public function test_send_test_is_throttled_per_user(): void
    {
        Mail::fake();
        $this->save($this->pageAdmin)->assertSessionHasNoErrors();

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($this->pageAdmin)->post('/admin/pages/'.$this->page->id.'/mail-test')->assertRedirect();
        }
        $this->actingAs($this->pageAdmin)->post('/admin/pages/'.$this->page->id.'/mail-test')->assertStatus(429);
        Mail::assertSentCount(5);

        // Someone else is not held up by it.
        $this->actingAs($this->globalAdmin)->post('/admin/pages/'.$this->page->id.'/mail-test')->assertRedirect();
    }
}
