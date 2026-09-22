<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Models\Component;
use App\Models\Setting;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\OutgoingWebhook;
use App\Services\PageContext;
use App\Services\PageUrls;
use App\Services\SafeHttp;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class IntegrationController extends Controller
{
    public function index(Request $request)
    {
        $pageId = app(PageContext::class)->id();
        $canEdit = $request->user()->canEditPage($pageId);
        $canAdminister = $request->user()->canAdministerPage($pageId);
        $filters = $request->validate([
            'delivery_endpoint' => ['nullable', 'integer'],
            'delivery_channel' => ['nullable', Rule::in(array_keys(WebhookEndpoint::FORMATS))],
            'delivery_status' => ['nullable', Rule::in(['delivered', 'pending', 'failed'])],
        ]);
        $deliveries = WebhookDelivery::where('status_page_id', $pageId)
            ->whereHas('endpoint', fn ($q) => $q->where('status_page_id', $pageId))
            ->when($filters['delivery_endpoint'] ?? null, fn ($q, $id) => $q->where('webhook_endpoint_id', $id))
            ->when($filters['delivery_channel'] ?? null, fn ($q, $format) => $q->whereHas('endpoint', fn ($e) => $e->where('format', $format)));
        match ($filters['delivery_status'] ?? '') {
            'delivered' => $deliveries->whereNotNull('sent_at'),
            'pending' => $deliveries->whereNull('sent_at')->where('attempts', '<', 6),
            'failed' => $deliveries->whereNull('sent_at')->where('attempts', '>=', 6),
            default => null,
        };
        $profiles = config('integrations.destinations');
        $format = old('format', request()->query('destination', 'generic'));
        $format = is_string($format) && isset($profiles[$format]) ? $format : 'generic';
        $components = Component::with('check')->orderBy('position')->get();

        return view('admin.integrations', [
            'canEditIntegrations' => $canEdit,
            'canAdministerIntegrations' => $canAdminister,
            'deliveryFilters' => $filters,
            'deliveryEndpoints' => WebhookEndpoint::where('status_page_id', $pageId)->orderBy('label')->get(['id', 'label']),
            'destinationProfiles' => $profiles,
            'selectedFormat' => $format,
            'manualComponents' => $components->filter(fn ($component) => $component->enabled && ! $component->check?->enabled),
            'tokens' => ApiToken::where('status_page_id', app(PageContext::class)->id())->orderByDesc('id')->paginate(10, ['*'], 'tokens_page')->withQueryString()->fragment('integration-tokens'),
            'newToken' => $canAdminister && session('new_token_page') === $pageId ? session('new_token') : null,
            'deliveries' => $deliveries->with('endpoint')->latest('id')->paginate(5, ['*'], 'deliveries_page')->withQueryString()->fragment('delivery-history'),
            'endpoints' => WebhookEndpoint::orderBy('id')->paginate(5, ['*'], 'endpoints_page')->withQueryString()->fragment('outgoing-integrations'),
            'webhookSecret' => $canAdminister ? Setting::get('integrations.webhook_secret') : null,
            'heartbeats' => Component::whereHas('check', fn ($q) => $q->where('type', 'heartbeat'))
                ->with('check')->orderBy('id')->paginate(5, ['*'], 'heartbeats_page')->withQueryString()->fragment('guide-heartbeats'),
            'components' => $components,
        ]);
    }

    public function storeToken(Request $request)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:60'], 'scope' => ['sometimes', Rule::in(['read', 'write'])]]);

        [$token, $plain] = ApiToken::issue($data['name'], $request->user(), app(PageContext::class)->id(), $data['scope'] ?? 'read');

        // Passed through the session because it is the only moment it exists in
        // plaintext; only a hash is stored.
        return redirect()->to(PageUrls::route('admin.integrations'))->with(['new_token' => $plain, 'new_token_page' => app(PageContext::class)->id()]);
    }

    public function destroyToken(ApiToken $token)
    {
        abort_unless((int) $token->status_page_id === app(PageContext::class)->id(), 404);
        $name = $token->name;
        $token->delete();

        return redirect()->to(PageUrls::route('admin.integrations'))
            ->with('status', "Token \"{$name}\" revoked. Anything using it stops working now.");
    }

    public function storeEndpoint(Request $request, SafeHttp $safe)
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:60'],
            // http allowed on purpose: an n8n on the same LAN is the common case.
            // This machine and link-local are not: 169.254.169.254 hands out cloud
            // credentials to whoever asks, and a webhook must never be the one asking.
            'url' => ['bail', 'exclude_if:format,telegram', 'required', 'url:http,https', 'max:255', function (string $attribute, mixed $value, \Closure $fail) use ($safe) {
                if (($ip = $safe->forbiddenAddress($value)) !== null) {
                    $fail("That address resolves to {$ip}, which Pharos will never send to.");
                }
            }],
            'format' => ['required', Rule::in(array_keys(WebhookEndpoint::FORMATS))],
            'telegram_token' => ['exclude_unless:format,telegram', 'required', 'string', 'max:200', 'regex:/^[0-9]+:[A-Za-z0-9_-]{20,}$/'],
            'telegram_chat_id' => ['exclude_unless:format,telegram', 'required', 'string', 'max:100', 'regex:/^(?:-?[1-9][0-9]*|@[A-Za-z][A-Za-z0-9_]{4,})$/'],
            'signal_number' => ['exclude_unless:format,signal', 'required', 'regex:/^\+[1-9][0-9]{6,14}$/'],
            'signal_recipient' => ['exclude_unless:format,signal', 'required', 'regex:/^(\+[1-9][0-9]{6,14}|group\.[A-Za-z0-9+\/_=-]{1,200})$/'],
            'signal_token' => ['exclude_unless:format,signal', 'required', 'string', 'min:16', 'max:512', 'regex:/^[A-Za-z0-9._~+\/-]+={0,2}$/'],
        ]);

        if ($data['format'] === 'telegram') {
            $data['url'] = 'https://api.telegram.org/bot'.$data['telegram_token'].'/sendMessage';
        }
        $parts = parse_url($data['url']);
        $errors = [];
        if (isset($parts['user']) || isset($parts['pass'])) {
            $errors['url'] = 'Put credentials in the dedicated field, not in the address.';
        }
        if ($data['format'] === 'discord' && (($parts['scheme'] ?? '') !== 'https'
            || ($parts['host'] ?? '') !== 'discord.com' || ! preg_match('~^/api/webhooks/[0-9]+/[A-Za-z0-9_-]+$~', $parts['path'] ?? ''))) {
            $errors['url'] = 'Use an HTTPS Discord webhook address from discord.com.';
        }
        if ($data['format'] === 'signal' && (($parts['scheme'] ?? '') !== 'https' || ($parts['path'] ?? '') !== '/v2/send')) {
            $errors['url'] = 'Use your authenticated HTTPS Signal bridge endpoint ending in /v2/send.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
        $options = $data['format'] === 'signal'
            ? ['number' => $data['signal_number'], 'recipient' => $data['signal_recipient'], 'token' => $data['signal_token']] : null;
        if ($data['format'] === 'telegram') {
            $options = ['chat_id' => $data['telegram_chat_id']];
        }
        WebhookEndpoint::create(['label' => $data['label'], 'url' => $data['url'], 'format' => $data['format'], 'enabled' => true, 'options' => $options]);

        // The signature only means anything to a generic receiver, but the secret
        // has to exist before the first one fires.
        if (! Setting::get('integrations.webhook_secret')) {
            Setting::put('integrations.webhook_secret', Str::random(32));
        }

        return redirect()->to(PageUrls::route('admin.integrations'))
            ->with('status', "Notification to \"{$data['label']}\" added. Send a test to be sure it arrives.");
    }

    public function destroyEndpoint(WebhookEndpoint $endpoint)
    {
        $label = $endpoint->label;
        $endpoint->delete();

        return redirect()->to(PageUrls::route('admin.integrations'))
            ->with('status', "Notification to \"{$label}\" removed.");
    }

    public function testEndpoint(WebhookEndpoint $endpoint, OutgoingWebhook $webhook)
    {
        $ok = $webhook->test($endpoint);
        $endpoint->refresh();

        return redirect()->to(PageUrls::route('admin.integrations'))->with(
            'status',
            $ok
                ? "Test sent to \"{$endpoint->label}\" and accepted (HTTP {$endpoint->last_status}). Check the channel."
                : "Test to \"{$endpoint->label}\" failed: ".($endpoint->last_error ?: 'no response').'.',
        );
    }

    public function rotateSecret()
    {
        Setting::put('integrations.webhook_secret', Str::random(32));

        return redirect()->to(PageUrls::route('admin.integrations'))
            ->with('status', 'Signing secret rotated. Update the receiving end.');
    }
}
