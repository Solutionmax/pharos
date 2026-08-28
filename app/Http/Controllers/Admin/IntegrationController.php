<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Models\Component;
use App\Models\Setting;
use App\Models\WebhookEndpoint;
use App\Services\OutgoingWebhook;
use App\Services\SafeHttp;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class IntegrationController extends Controller
{
    public function index()
    {
        return view('admin.integrations', [
            'tokens' => ApiToken::orderByDesc('created_at')->get(),
            'newToken' => session('new_token'),
            'endpoints' => WebhookEndpoint::orderBy('id')->get(),
            'webhookSecret' => Setting::get('integrations.webhook_secret'),
            'heartbeats' => Component::whereHas('check', fn ($q) => $q->where('type', 'heartbeat'))
                ->with('check')->get(),
            'components' => Component::orderBy('position')->get(),
        ]);
    }

    public function storeToken(Request $request)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:60']]);

        [, $plain] = ApiToken::issue($data['name']);

        // Passed through the session because it is the only moment it exists in
        // plaintext; only a hash is stored.
        return redirect()->route('admin.integrations')->with('new_token', $plain);
    }

    public function destroyToken(ApiToken $token)
    {
        $name = $token->name;
        $token->delete();

        return redirect()->route('admin.integrations')
            ->with('status', "Token \"{$name}\" revoked. Anything using it stops working now.");
    }

    public function storeEndpoint(Request $request, SafeHttp $safe)
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:60'],
            // http allowed on purpose: an n8n on the same LAN is the common case.
            // This machine and link-local are not: 169.254.169.254 hands out cloud
            // credentials to whoever asks, and a webhook must never be the one asking.
            'url' => ['bail', 'required', 'url:http,https', 'max:255', function (string $attribute, mixed $value, \Closure $fail) use ($safe) {
                if (($ip = $safe->forbiddenAddress($value)) !== null) {
                    $fail("That address resolves to {$ip}, which Pharos will never send to.");
                }
            }],
            'format' => ['required', Rule::in(array_keys(WebhookEndpoint::FORMATS))],
        ]);

        WebhookEndpoint::create($data + ['enabled' => true]);

        // The signature only means anything to a generic receiver, but the secret
        // has to exist before the first one fires.
        if (! Setting::get('integrations.webhook_secret')) {
            Setting::put('integrations.webhook_secret', Str::random(32));
        }

        return redirect()->route('admin.integrations')
            ->with('status', "Notification to \"{$data['label']}\" added. Send a test to be sure it arrives.");
    }

    public function destroyEndpoint(WebhookEndpoint $endpoint)
    {
        $label = $endpoint->label;
        $endpoint->delete();

        return redirect()->route('admin.integrations')
            ->with('status', "Notification to \"{$label}\" removed.");
    }

    public function testEndpoint(WebhookEndpoint $endpoint, OutgoingWebhook $webhook)
    {
        $ok = $webhook->test($endpoint);
        $endpoint->refresh();

        return redirect()->route('admin.integrations')->with(
            'status',
            $ok
                ? "Test sent to \"{$endpoint->label}\" and accepted (HTTP {$endpoint->last_status}). Check the channel."
                : "Test to \"{$endpoint->label}\" failed: ".($endpoint->last_error ?: 'no response').'.',
        );
    }

    public function rotateSecret()
    {
        Setting::put('integrations.webhook_secret', Str::random(32));

        return redirect()->route('admin.integrations')
            ->with('status', 'Signing secret rotated. Update the receiving end.');
    }
}
