<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Models\Component;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class IntegrationController extends Controller
{
    public function index()
    {
        return view('admin.integrations', [
            'tokens' => ApiToken::orderByDesc('created_at')->get(),
            'newToken' => session('new_token'),
            'webhookUrl' => Setting::get('integrations.webhook_url'),
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

    public function updateWebhook(Request $request)
    {
        $data = $request->validate([
            'webhook_url' => ['nullable', 'url', 'max:255'],
        ]);

        Setting::put('integrations.webhook_url', $data['webhook_url'] ?: null);

        if ($data['webhook_url'] && ! Setting::get('integrations.webhook_secret')) {
            Setting::put('integrations.webhook_secret', Str::random(32));
        }

        return redirect()->route('admin.integrations')->with('status', 'Outgoing webhook saved.');
    }

    public function rotateSecret()
    {
        Setting::put('integrations.webhook_secret', Str::random(32));

        return redirect()->route('admin.integrations')
            ->with('status', 'Signing secret rotated. Update the receiving end.');
    }
}
