<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\Component;
use App\Models\Incident;
use App\Models\Maintenance;
use App\Models\StatusPage;
use App\Models\Subscriber;
use App\Models\SubscriberNotification;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\PageContext;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function onPage(StatusPage $page, callable $callback): mixed
    {
        return app(PageContext::class)->run($page->id, $callback);
    }

    /** @return array<string, int> */
    protected function counts(): array
    {
        $tables = ['status_pages', 'components', 'uptime_days', 'incidents', 'incident_updates', 'maintenances',
            'subscribers', 'subscriber_notifications', 'webhook_endpoints', 'webhook_deliveries', 'api_tokens', 'users', 'audit_log'];

        return collect($tables)->mapWithKeys(fn (string $t) => [$t => DB::table($t)->count()])->all();
    }

    public function test_the_demo_seeder_runs_twice_without_duplicating_anything(): void
    {
        Mail::fake();
        Http::fake();
        Http::preventStrayRequests();

        $this->seed(DemoSeeder::class);
        $first = $this->counts();
        $this->seed(DemoSeeder::class);

        $this->assertSame($first, $this->counts());
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Http::assertNothingSent();
    }

    public function test_demo_data_lands_on_more_than_one_page(): void
    {
        $this->seed(DemoSeeder::class);

        $default = StatusPage::default();
        $harbor = StatusPage::query()->where('slug', 'harbor')->firstOrFail();

        $this->assertGreaterThanOrEqual(3, StatusPage::query()->count());
        $this->assertSame('Northwind Hosting', $default->name);

        $onDefault = $this->onPage($default, fn () => [Component::query()->count(), Incident::query()->count(), Maintenance::query()->count()]);
        $onHarbor = $this->onPage($harbor, fn () => [Component::query()->count(), Incident::query()->count(), Maintenance::query()->count()]);

        $this->assertSame([12, 3, 2], $onDefault);
        $this->assertSame([4, 1, 1], $onHarbor);
        $this->assertTrue($this->onPage($default, fn () => Incident::query()->whereNull('resolved_at')->exists()));
    }

    public function test_demo_data_covers_the_screens_it_is_meant_to_show(): void
    {
        $this->seed(DemoSeeder::class);
        $default = StatusPage::default();

        $this->onPage($default, function () {
            $this->assertEqualsCanonicalizing(['slack', 'teams', 'generic'], WebhookEndpoint::query()->pluck('format')->all());
            $this->assertFalse(WebhookEndpoint::query()->where('format', 'generic')->value('enabled'));
            $this->assertSame(7, Subscriber::query()->active()->count());
            $this->assertSame(0, SubscriberNotification::query()->whereNull('sent_at')->count());
        });

        // Nothing is left for the delivery runner: every row is sent or given up on.
        $this->assertSame(0, WebhookDelivery::query()->whereNull('sent_at')->where('attempts', '<', 6)->count());
        $this->assertEqualsCanonicalizing(['read', 'write'], ApiToken::query()->distinct()->pluck('scope')->all());

        $roles = DB::table('status_page_user')->pluck('role')->unique()->values()->all();
        $this->assertEqualsCanonicalizing(['viewer', 'editor', 'admin'], $roles);
        $this->assertTrue(User::query()->where('email', DemoSeeder::ADMIN_EMAIL)->firstOrFail()->isAdmin());
        $this->assertTrue(User::query()->pluck('email')->every(fn (string $email) => str_ends_with($email, 'example.net')));
    }
}
