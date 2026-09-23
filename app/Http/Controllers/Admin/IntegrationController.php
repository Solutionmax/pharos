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
    /** The old single screen. Bookmarks and links from older mails land on the part they meant. */
    public function index(Request $request)
    {
        $query = $request->query();
        $target = match (true) {
            (bool) array_intersect(array_keys($query), ['delivery_endpoint', 'delivery_channel', 'delivery_status', 'deliveries_page']) => 'admin.integrations.log',
            array_key_exists('tokens_page', $query) => 'admin.integrations.tokens',
            array_key_exists('heartbeats_page', $query) => 'admin.integrations.in',
            default => 'admin.integrations.out',
        };

        return redirect()->to(PageUrls::route($target, $query));
    }

    /** Send out: destinations that tell your team. */
    public function out(Request $request)
    {
        $pageId = app(PageContext::class)->id();
        $profiles = config('integrations.destinations');
        $format = old('format', $request->query('destination', 'slack'));
        $format = is_string($format) && isset($profiles[$format]) ? $format : 'slack';

        return view('admin.integrations.out', $this->common($request) + [
            'destinationProfiles' => $profiles,
            'selectedFormat' => $format,
            'endpoints' => WebhookEndpoint::orderBy('id')->paginate(5, ['*'], 'endpoints_page')->withQueryString()->fragment('destinations'),
            'endpointCount' => WebhookEndpoint::count(),
            'webhookSecret' => $request->user()->canAdministerPage($pageId) ? Setting::get('integrations.webhook_secret') : null,
        ]);
    }

    /** Bring in: what keeps components up to date without a person. */
    public function in(Request $request)
    {
        $pageId = app(PageContext::class)->id();
        $components = Component::with(['check', 'group'])->orderBy('position')->get();

        return view('admin.integrations.in', $this->common($request) + [
            'components' => $components,
            'manualComponents' => $components->filter(fn ($component) => $component->enabled && ! $component->check?->enabled)->values(),
            'writeTokens' => $request->user()->canAdministerPage($pageId)
                ? ApiToken::where('status_page_id', $pageId)->where('scope', 'write')->orderBy('name')->get(['id', 'name']) : collect(),
            'heartbeats' => Component::whereHas('check', fn ($q) => $q->where('type', 'heartbeat'))
                ->with('check')->orderBy('id')->paginate(5, ['*'], 'heartbeats_page')->withQueryString()->fragment('heartbeats'),
        ]);
    }

    /** API tokens: page administrators issue and revoke; everyone else is told who can. */
    public function tokens(Request $request)
    {
        $pageId = app(PageContext::class)->id();
        $canAdminister = $request->user()->canAdministerPage($pageId);

        return view('admin.integrations.tokens', $this->common($request) + [
            'tokens' => $canAdminister
                ? ApiToken::with('user:id,name')->where('status_page_id', $pageId)->orderByDesc('id')->paginate(10, ['*'], 'tokens_page')->withQueryString()
                : null,
            'newToken' => $canAdminister && session('new_token_page') === $pageId ? session('new_token') : null,
        ]);
    }

    /** Delivery log: every message sent to a destination of this page, and what came back. */
    public function log(Request $request)
    {
        $pageId = app(PageContext::class)->id();
        $filters = $request->validate([
            'delivery_endpoint' => ['nullable', 'integer'],
            'delivery_channel' => ['nullable', Rule::in(array_keys(WebhookEndpoint::FORMATS))],
            'delivery_status' => ['nullable', Rule::in(['delivered', 'pending', 'failed'])],
        ]);
        $owned = fn () => WebhookDelivery::where('status_page_id', $pageId)
            ->whereHas('endpoint', fn ($q) => $q->where('status_page_id', $pageId));
        $deliveries = $owned()
            ->when($filters['delivery_endpoint'] ?? null, fn ($q, $id) => $q->where('webhook_endpoint_id', $id))
            ->when($filters['delivery_channel'] ?? null, fn ($q, $format) => $q->whereHas('endpoint', fn ($e) => $e->where('format', $format)));
        match ($filters['delivery_status'] ?? '') {
            'delivered' => $deliveries->whereNotNull('sent_at'),
            'pending' => $deliveries->whereNull('sent_at')->where('attempts', '<', 6),
            'failed' => $deliveries->whereNull('sent_at')->where('attempts', '>=', 6),
            default => null,
        };
        $week = now()->subDays(7);

        return view('admin.integrations.log', $this->common($request) + [
            'deliveryFilters' => $filters,
            'deliveryEndpoints' => WebhookEndpoint::orderBy('label')->get(['id', 'label']),
            'deliveries' => $deliveries->with('endpoint')->latest('id')->paginate(10, ['*'], 'deliveries_page')->withQueryString(),
            'counters' => [
                'delivered' => $owned()->whereNotNull('sent_at')->where('sent_at', '>=', $week)->count(),
                'pending' => $owned()->whereNull('sent_at')->where('attempts', '<', 6)->count(),
                'failed' => $owned()->whereNull('sent_at')->where('attempts', '>=', 6)->where('created_at', '>=', $week)->count(),
            ],
        ]);
    }

    /** @return array<string, mixed> */
    protected function common(Request $request): array
    {
        $pageId = app(PageContext::class)->id();

        return [
            'canEditIntegrations' => $request->user()->canEditPage($pageId),
            'canAdministerIntegrations' => $request->user()->canAdministerPage($pageId),
            'contextPage' => app(PageContext::class)->page(),
        ];
    }

    public function storeToken(Request $request)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:60'], 'scope' => ['sometimes', Rule::in(['read', 'write'])]]);

        [$token, $plain] = ApiToken::issue($data['name'], $request->user(), app(PageContext::class)->id(), $data['scope'] ?? 'read');

        // Passed through the session because it is the only moment it exists in
        // plaintext; only a hash is stored.
        return redirect()->to(PageUrls::route('admin.integrations.tokens'))->with(['new_token' => $plain, 'new_token_page' => app(PageContext::class)->id()]);
    }

    public function destroyToken(ApiToken $token)
    {
        abort_unless((int) $token->status_page_id === app(PageContext::class)->id(), 404);
        $name = $token->name;
        $token->delete();

        return redirect()->to(PageUrls::route('admin.integrations.tokens'))
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
            // The form marks that it offered a choice; a client that never knew
            // about events keeps the old meaning of "everything".
            'events' => ['exclude_unless:events_set,1', 'required', 'array', 'min:1'],
            'events.*' => ['string', Rule::in(array_keys(WebhookEndpoint::EVENTS))],
        ], ['events.required' => 'Choose at least one moment to send.']);

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
        $endpoint = WebhookEndpoint::create([
            'label' => $data['label'], 'url' => $data['url'], 'format' => $data['format'], 'enabled' => true, 'options' => $options,
            'events' => isset($data['events']) ? array_values(array_unique($data['events'])) : null,
        ]);

        // The signature only means anything to a generic receiver, but the secret
        // has to exist before the first one fires.
        if (! Setting::get('integrations.webhook_secret')) {
            Setting::put('integrations.webhook_secret', Str::random(32));
        }

        if ($request->boolean('send_test')) {
            return $this->sendTest($endpoint, app(OutgoingWebhook::class), "Destination \"{$endpoint->label}\" saved. ");
        }

        return redirect()->to(PageUrls::route('admin.integrations.out'))
            ->with('status', "Destination \"{$data['label']}\" saved. Send a test to be sure it arrives.");
    }

    public function destroyEndpoint(WebhookEndpoint $endpoint)
    {
        $label = $endpoint->label;
        $endpoint->delete();

        return redirect()->to(PageUrls::route('admin.integrations.out'))
            ->with('status', "Destination \"{$label}\" removed.");
    }

    public function updateEvents(Request $request, WebhookEndpoint $endpoint)
    {
        $data = $request->validate([
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['string', Rule::in(array_keys(WebhookEndpoint::EVENTS))],
        ], ['events.required' => 'Choose at least one moment to send.']);
        $endpoint->update(['events' => array_values(array_unique($data['events']))]);

        return redirect()->to(PageUrls::route('admin.integrations.out'))
            ->with('status', "\"{$endpoint->label}\" now receives: ".collect($endpoint->events)->map(fn ($e) => WebhookEndpoint::EVENTS[$e])->join(', ').'.');
    }

    public function testEndpoint(WebhookEndpoint $endpoint, OutgoingWebhook $webhook)
    {
        return $this->sendTest($endpoint, $webhook);
    }

    protected function sendTest(WebhookEndpoint $endpoint, OutgoingWebhook $webhook, string $prefix = '')
    {
        $ok = $webhook->test($endpoint);
        $endpoint->refresh();

        return redirect()->to(PageUrls::route('admin.integrations.out'))->with(
            'status',
            $prefix.($ok
                ? "Test sent to \"{$endpoint->label}\" and accepted (HTTP {$endpoint->last_status}). Check the channel."
                : "Test to \"{$endpoint->label}\" failed: ".($endpoint->last_error ?: 'no response').'.'),
        );
    }

    public function rotateSecret()
    {
        Setting::put('integrations.webhook_secret', Str::random(32));

        return redirect()->to(PageUrls::route('admin.integrations.out'))
            ->with('status', 'Signing secret rotated. Update the receiving end.');
    }
}
