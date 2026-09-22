<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\Component;
use App\Models\Incident;
use App\Models\StatusPage;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\OutgoingWebhook;
use App\Services\SafeHttp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DeliverySecurityTest extends TestCase
{
    use RefreshDatabase;

    private function endpoint(string $format = 'generic'): WebhookEndpoint
    {
        return WebhookEndpoint::create(['label' => 'Ops', 'url' => 'https://203.0.113.10/v2/send', 'format' => $format, 'enabled' => true,
            'options' => $format === 'signal' ? ['number' => '+31612345678', 'recipient' => '+31687654321', 'token' => 'test-bridge-secret'] : null]);
    }

    private function incident(): Incident
    {
        return Incident::create(['name' => 'Test @everyone', 'status' => 1, 'impact' => 'major', 'occurred_at' => now()]);
    }

    public function test_incident_is_queued_without_network_and_repeated_event_is_deduplicated(): void
    {
        Http::fake();
        $this->endpoint();
        $incident = $this->incident();
        $service = app(OutgoingWebhook::class);
        $service->incidentChanged($incident, 'incident.created');
        $service->incidentChanged($incident, 'incident.created');
        Http::assertNothingSent();
        $this->assertDatabaseCount('webhook_deliveries', 1);
        $this->assertSame(1, $service->sendPending());
        $this->assertSame(0, $service->sendPending());
        Http::assertSentCount(1);
    }

    public function test_rate_limited_delivery_waits_for_retry_after(): void
    {
        Http::fake(['*' => Http::sequence()->push('', 429, ['Retry-After' => '600'])->push('', 204)]);
        $this->endpoint();
        $service = app(OutgoingWebhook::class);
        $service->incidentChanged($this->incident(), 'incident.created');
        $this->assertSame(0, $service->sendPending());
        $this->assertSame(0, $service->sendPending());
        Http::assertSentCount(1);
        $this->travel(601)->seconds();
        $this->assertSame(1, $service->sendPending());
        $this->assertNotNull(WebhookDelivery::sole()->sent_at);
    }

    public function test_bad_credentials_stop_without_infinite_retry(): void
    {
        Http::fake(['*' => Http::response('', 401)]);
        $this->endpoint();
        $service = app(OutgoingWebhook::class);
        $service->incidentChanged($this->incident(), 'incident.created');
        $service->sendPending();
        $this->travel(2)->hours();
        $service->sendPending();
        Http::assertSentCount(1);
        $this->assertSame(6, WebhookDelivery::sole()->attempts);
    }

    public function test_discord_disables_mentions_and_signal_uses_bridge_credentials(): void
    {
        Http::fake();
        $discord = $this->endpoint('discord');
        $signal = $this->endpoint('signal');
        app(OutgoingWebhook::class)->test($discord);
        app(OutgoingWebhook::class)->test($signal);
        Http::assertSent(fn ($r) => ($r['allowed_mentions'] ?? null) === ['parse' => []]);
        Http::assertSent(fn ($r) => ($r['number'] ?? null) === '+31612345678' && $r['recipients'] === ['+31687654321'] && $r->hasHeader('Authorization', 'Bearer test-bridge-secret'));
        $raw = DB::table('webhook_endpoints')->where('id', $signal->id)->first();
        $this->assertStringNotContainsString('203.0.113.10', $raw->url);
        $this->assertStringNotContainsString('test-bridge-secret', $raw->options);
    }

    public function test_exception_does_not_store_webhook_credentials(): void
    {
        Http::fake(fn () => throw new \RuntimeException('Failed https://example.test/SUPERSECRET'));
        $endpoint = $this->endpoint();
        app(OutgoingWebhook::class)->test($endpoint);
        $this->assertStringNotContainsString('SUPERSECRET', $endpoint->fresh()->last_error);
    }

    public function test_signal_requires_https_and_does_not_flash_token_on_error(): void
    {
        $user = User::create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => bcrypt('local-test-password')]);
        $user->statusPages()->attach(StatusPage::default());
        $this->actingAs($user)->post('/admin/integrations/notifications', [
            'label' => 'Signal', 'format' => 'signal', 'url' => 'http://203.0.113.10/v2/send',
            'signal_number' => '+31612345678', 'signal_recipient' => '+31687654321', 'signal_token' => 'test-bridge-secret',
        ])->assertSessionHasErrors('url');
        $this->assertNull(session()->getOldInput('signal_token'));
        $this->assertDatabaseCount('webhook_endpoints', 0);
    }

    public function test_telegram_stores_credentials_encrypted_and_sends_plain_text(): void
    {
        Http::fake(['*' => Http::response(['ok' => true])]);
        $user = User::create(['name' => 'Admin', 'email' => 'telegram@example.test', 'password' => bcrypt('local-test-password')]);
        $user->statusPages()->attach(StatusPage::default());
        $token = '123456:abcdefghijklmnopqrstuvwxyz_12345';
        $this->actingAs($user)->post('/admin/integrations/notifications', [
            'label' => 'Telegram', 'format' => 'telegram',
            'telegram_token' => $token, 'telegram_chat_id' => '-1001234567890',
            'url' => 'http://127.0.0.1/ignored',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $endpoint = WebhookEndpoint::sole();
        $this->assertStringNotContainsString($token, DB::table('webhook_endpoints')->value('url'));
        $this->assertStringNotContainsString($token, $endpoint->maskedUrl());
        $safe = new class extends SafeHttp
        {
            public function resolveOwn(string $host): string
            {
                return '203.0.113.10';
            }
        };
        $this->assertTrue((new OutgoingWebhook($safe))->test($endpoint));
        Http::assertSent(fn ($r) => $r->url() === 'https://api.telegram.org/bot'.$token.'/sendMessage'
            && $r['chat_id'] === '-1001234567890' && str_contains($r['text'], 'Test notification from Pharos')
            && ! isset($r['parse_mode']));
    }

    public function test_telegram_retries_after_api_rate_limit_and_rejects_false_success(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push(['ok' => false, 'parameters' => ['retry_after' => 600]], 429)
            ->push(['ok' => true])
            ->push(['ok' => false])]);
        $endpoint = $this->endpoint('telegram');
        $endpoint->update(['options' => ['chat_id' => '-1001234567890']]);
        $service = app(OutgoingWebhook::class);
        $service->incidentChanged($this->incident(), 'incident.created');
        $this->assertSame(0, $service->sendPending());
        $this->travel(599)->seconds();
        $this->assertSame(0, $service->sendPending());
        Http::assertSentCount(1);
        $this->travel(2)->seconds();
        $this->assertSame(1, $service->sendPending());
        $this->assertFalse($service->test($endpoint));
        $this->assertSame('Telegram rejected the message.', $endpoint->fresh()->last_error);
    }

    public function test_telegram_validation_does_not_flash_token(): void
    {
        $user = User::create(['name' => 'Admin', 'email' => 'telegram@example.test', 'password' => bcrypt('local-test-password')]);
        $user->statusPages()->attach(StatusPage::default());
        $this->actingAs($user)->post('/admin/integrations/notifications', [
            'label' => 'Telegram', 'format' => 'telegram', 'telegram_token' => 'secret-invalid-token',
            'telegram_chat_id' => 'invalid',
        ])->assertSessionHasErrors(['telegram_token', 'telegram_chat_id']);
        $this->assertNull(session()->getOldInput('telegram_token'));
        $this->assertDatabaseCount('webhook_endpoints', 0);
    }

    public function test_status_update_link_is_only_visible_when_signed_in(): void
    {
        $incident = $this->incident();
        $user = User::create(['name' => 'Operator', 'email' => 'operator@example.test', 'password' => bcrypt('local-test-password'), 'role' => 'user']);
        $user->statusPages()->attach(StatusPage::default());
        $url = route('admin.incidents.update-form', $incident);
        $this->get('/')->assertOk()->assertDontSee($url, false);
        $this->get($url)->assertRedirect(route('admin.login'));
        $this->actingAs($user)->get('/')->assertOk()->assertSee($url, false);
        $this->get($url)->assertOk()->assertSee($incident->name);
    }

    public function test_kuma_maps_up_down_and_requires_an_api_token(): void
    {
        $component = Component::create(['name' => 'Kuma service']);
        $url = '/api/v1/kuma/components/'.$component->id;
        $this->postJson($url, ['heartbeat' => ['status' => 0]])->assertUnauthorized();
        [, $token] = ApiToken::issue('Kuma');
        $this->withToken($token)->postJson($url, ['heartbeat' => ['status' => 0]])->assertOk();
        $this->assertSame(4, $component->fresh()->status->value);
        $this->withToken($token)->postJson($url, ['heartbeat' => ['status' => 1]])->assertOk();
        $this->assertSame(1, $component->fresh()->status->value);
        $this->withToken($token)->postJson($url, ['heartbeat' => ['status' => 99]])->assertUnprocessable();
    }
}
