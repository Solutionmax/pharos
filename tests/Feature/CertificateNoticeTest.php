<?php

namespace Tests\Feature;

use App\Enums\CheckType;
use App\Enums\UserRole;
use App\Models\Check;
use App\Models\Component;
use App\Models\Setting;
use App\Models\StatusPage;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\CheckRunner;
use App\Services\OutgoingWebhook;
use App\Services\Probe;
use App\Services\ProbeResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CertificateNoticeTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_certificate_is_announced_once_at_14_days_and_once_at_3(): void
    {
        $endpoint = WebhookEndpoint::create(['label' => 'Ops', 'url' => 'https://203.0.113.10/hook', 'format' => 'generic', 'enabled' => true, 'events' => ['certificate.expiring']]);
        $check = $this->check(now()->addDays(20)->addHour());
        $webhook = app(OutgoingWebhook::class);

        $webhook->certificateExpiring($check->fresh());
        $this->assertSame(0, WebhookDelivery::count(), 'twenty days out is not news yet');

        $this->travel(7)->days();
        $webhook->certificateExpiring($check->fresh());
        $webhook->certificateExpiring($check->fresh());
        $this->assertSame(1, WebhookDelivery::count(), 'every run sees it; it goes out once');
        $first = WebhookDelivery::sole();
        $this->assertSame('certificate.expiring', $first->event);
        $this->assertSame($endpoint->id, $first->webhook_endpoint_id);
        $this->assertSame('certificate.expiring', $first->payload['event']);
        $this->assertSame(['component_id' => $check->component_id, 'component' => 'Shop', 'host' => 'shop.example.net',
            'expires_at' => $check->fresh()->tls_expires_at->toIso8601String(), 'days_left' => 13, 'threshold_days' => 14], $first->payload['certificate']);

        $this->travel(5)->days();
        $webhook->certificateExpiring($check->fresh());
        $this->assertSame(1, WebhookDelivery::count(), 'eight days left: still the 14 day notice');

        $this->travel(5)->days();
        $webhook->certificateExpiring($check->fresh());
        $webhook->certificateExpiring($check->fresh());
        $this->assertSame(2, WebhookDelivery::count());
        $this->assertSame(['days_left' => 3, 'threshold_days' => 3], array_intersect_key(WebhookDelivery::latest('id')->first()->payload['certificate'], ['days_left' => 0, 'threshold_days' => 0]));

        // Renewed: a new expiry date far away is quiet, and its own 14 days are news again.
        $check->update(['tls_expires_at' => now()->addDays(90)->addHour()]);
        $webhook->certificateExpiring($check->fresh());
        $this->assertSame(2, WebhookDelivery::count());
        $this->travel(80)->days();
        $webhook->certificateExpiring($check->fresh());
        $this->assertSame(3, WebhookDelivery::count());
    }

    public function test_first_sight_inside_three_days_sends_only_the_three_day_notice(): void
    {
        WebhookEndpoint::create(['label' => 'Ops', 'url' => 'https://203.0.113.10/hook', 'format' => 'generic', 'enabled' => true]);
        $check = $this->check(now()->addDays(2));

        app(OutgoingWebhook::class)->certificateExpiring($check);
        app(OutgoingWebhook::class)->certificateExpiring($check->fresh());

        $this->assertSame(1, WebhookDelivery::count(), 'a destination saved before events existed hears it too');
        $this->assertSame(3, WebhookDelivery::sole()->payload['certificate']['threshold_days']);
        $this->assertStringEndsWith(':3', (string) $check->fresh()->tls_notice);
    }

    public function test_only_destinations_that_ticked_it_and_only_published_pages_hear_it(): void
    {
        WebhookEndpoint::create(['label' => 'Incidents', 'url' => 'https://203.0.113.10/a', 'format' => 'slack', 'enabled' => true, 'events' => ['incident.opened']]);
        WebhookEndpoint::create(['label' => 'Off', 'url' => 'https://203.0.113.10/b', 'format' => 'slack', 'enabled' => false, 'events' => ['certificate.expiring']]);
        $check = $this->check(now()->addDays(5));

        app(OutgoingWebhook::class)->certificateExpiring($check);
        $this->assertSame(0, WebhookDelivery::count());

        WebhookEndpoint::create(['label' => 'Certs', 'url' => 'https://203.0.113.10/c', 'format' => 'slack', 'enabled' => true, 'events' => ['certificate.expiring']]);
        StatusPage::default()->update(['is_published' => false]);
        $other = $this->check(now()->addDays(5), 'Blog', 'https://blog.example.net/');
        app(OutgoingWebhook::class)->certificateExpiring($other);
        $this->assertSame(0, WebhookDelivery::count());
        $this->assertNull($other->fresh()->tls_notice, 'nothing sent, nothing remembered');
    }

    public function test_every_destination_type_gets_its_own_shape_and_generic_is_signed(): void
    {
        Http::fake(['https://203.0.113.14/*' => Http::response(['ok' => true]), '*' => Http::response()]);
        Setting::put('integrations.webhook_secret', 'a-secret');
        $formats = [
            'generic' => [],
            'slack' => [],
            'teams' => [],
            'discord' => [],
            'telegram' => ['chat_id' => '-100123'],
            'signal' => ['number' => '+31600000000', 'recipient' => '+31611111111', 'token' => 'bridge-token'],
        ];
        $i = 10;
        foreach ($formats as $format => $options) {
            WebhookEndpoint::create(['label' => $format, 'url' => 'https://203.0.113.'.$i++.'/'.$format, 'format' => $format, 'enabled' => true, 'options' => $options ?: null, 'events' => ['certificate.expiring']]);
        }
        $check = $this->check(now()->addDays(12)->addHour());

        app(OutgoingWebhook::class)->certificateExpiring($check);
        $this->assertSame(6, WebhookDelivery::count());
        $this->assertSame(6, app(OutgoingWebhook::class)->sendPending());

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/generic')
            && json_decode($r->body(), true)['event'] === 'certificate.expiring'
            && $r->header('X-Pharos-Signature')[0] === hash_hmac('sha256', $r->body(), 'a-secret'));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/slack') && $r->header('X-Pharos-Signature') === []
            && $r['text'] === 'Certificate expiring: Shop'
            && str_contains($r['blocks'][1]['elements'][0]['text'], 'shop.example.net')
            && str_contains($r['blocks'][1]['elements'][0]['text'], '12 days left'));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/teams')
            && $r['attachments'][0]['content']['body'][0]['color'] === 'Warning'
            && $r['attachments'][0]['content']['body'][1]['facts'][0] === ['title' => 'Address', 'value' => 'shop.example.net']);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/discord') && str_starts_with($r['content'], "Certificate expiring: Shop\nshop.example.net") && $r['allowed_mentions'] === ['parse' => []]);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/telegram') && $r['chat_id'] === '-100123' && str_contains($r['text'], '12 days left'));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/signal') && $r['recipients'] === ['+31611111111'] && str_starts_with($r['message'], 'Certificate expiring: Shop'));
        Http::assertNotSent(fn ($r) => str_contains($r->body(), 'secret') || str_contains($r->body(), '/login'));
    }

    public function test_the_check_runner_sends_it_from_a_real_run_and_the_screen_offers_the_moment(): void
    {
        WebhookEndpoint::create(['label' => 'Ops', 'url' => 'https://203.0.113.10/hook', 'format' => 'generic', 'enabled' => true, 'events' => ['certificate.expiring']]);
        $check = $this->check(null);
        $probe = new class extends Probe
        {
            public function run(Check $check): ProbeResult
            {
                return new ProbeResult(true, 20, 'Certificate expires in 9 days', now()->addDays(9)->addHour());
            }
        };
        $runner = new CheckRunner($probe, app(OutgoingWebhook::class));

        $runner->runOne($check);
        $runner->runOne($check->fresh());

        $this->assertSame(1, WebhookDelivery::count());
        $this->assertSame(9, WebhookDelivery::sole()->payload['certificate']['days_left']);
        $this->assertNotNull($check->fresh()->tls_warning);

        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
            ->post('/admin/integrations/notifications', ['label' => 'Certs', 'url' => 'https://203.0.113.20/x', 'format' => 'generic', 'events_set' => '1', 'events' => ['certificate.expiring']])
            ->assertRedirect();
        $this->assertSame(['certificate.expiring'], WebhookEndpoint::where('label', 'Certs')->sole()->events);
    }

    private function check(?\DateTimeInterface $expires, string $name = 'Shop', string $target = 'https://user:secret@shop.example.net/login?next=1'): Check
    {
        $component = Component::create(['name' => $name]);

        return Check::create(['component_id' => $component->id, 'type' => CheckType::Http, 'target' => $target, 'tls_expires_at' => $expires]);
    }
}
