<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Audit;
use App\Models\Setting;
use App\Services\Sso;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The trip to the identity provider and back. Nothing here creates accounts:
 * an address the provider vouches for either matches somebody who already has
 * one, or it does not get in. See docs/sso.md.
 */
class SsoController extends Controller
{
    public function __construct(protected Sso $sso) {}

    public function redirect(Request $request)
    {
        if (! $this->sso->enabled()) {
            return redirect()->route('admin.login');
        }

        try {
            $flow = $this->sso->begin($this->callbackUrl());
        } catch (\Throwable $e) {
            report($e);

            return redirect()->route('admin.login')
                ->withErrors(['email' => 'Could not reach '.$this->sso->providerName().'. Sign in with your password instead.']);
        }

        // The three values that tie the answer to this request, and nothing else:
        // no row to clean up, and a restart cannot strand a half-finished sign-in.
        $request->session()->put('sso.state', $flow['state']);
        $request->session()->put('sso.nonce', $flow['nonce']);
        $request->session()->put('sso.verifier', $flow['verifier']);

        return redirect()->away($flow['url']);
    }

    public function callback(Request $request)
    {
        if (! $this->sso->enabled()) {
            return redirect()->route('admin.login');
        }

        $state = $request->session()->pull('sso.state');
        $nonce = $request->session()->pull('sso.nonce');
        $verifier = $request->session()->pull('sso.verifier');

        if (! $state || ! $nonce || ! $verifier || ! hash_equals($state, (string) $request->query('state'))) {
            return $this->refuse('the sign-in did not match the one that was started');
        }

        if (! is_string($code = $request->query('code'))) {
            return $this->refuse('the provider sent no authorization code');
        }

        try {
            $claims = $this->sso->claims($code, $verifier, $nonce, $this->callbackUrl());
        } catch (\Throwable $e) {
            return $this->refuse($e->getMessage());
        }

        $user = User::where('email', $claims['email'])->first();

        if (! $user) {
            return $this->refuse('no account here uses '.$claims['email']);
        }

        // Somebody who switched two-factor on keeps it, whatever door they came
        // through: a second door that skips the gate makes the gate decorative.
        if ($user->hasTwoFactor()) {
            $request->session()->put(TwoFactorController::PENDING, $user->id);
            $request->session()->put(TwoFactorController::REMEMBER, false);

            return redirect()->route('admin.two-factor');
        }

        Auth::login($user);
        $request->session()->regenerate();
        Audit::record('sso.login');

        return redirect()->intended(route('admin.components'));
    }

    // ---------- administration (the form sits on the Settings screen) ----------

    public function update(Request $request)
    {
        $data = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'provider_name' => ['nullable', 'string', 'max:60'],
            'issuer' => ['nullable', 'url:https', 'max:255'],
            'client_id' => ['nullable', 'string', 'max:255'],
            'client_secret' => ['nullable', 'string', 'max:255'],
            'internal_hosts' => ['nullable', 'string', 'max:500'],
        ]);

        Setting::put('sso.provider_name', $data['provider_name'] ?? '');
        Setting::put('sso.issuer', rtrim($data['issuer'] ?? '', '/'));
        Setting::put('sso.client_id', $data['client_id'] ?? '');
        Setting::put('sso.internal_hosts', $data['internal_hosts'] ?? '');

        // An empty box means "leave it alone", not "wipe it": the secret is never
        // rendered back into the form, so submitting the form would clear it.
        if (filled($data['client_secret'] ?? null)) {
            // Encrypted at rest, like the mail password: a database dump must not hand out the provider secret.
            Setting::put('sso.client_secret', \Illuminate\Support\Facades\Crypt::encryptString($data['client_secret']));
        }

        Cache::forget('sso.discovery.'.md5((string) Setting::get('sso.issuer')));

        if (! isset($data['enabled'])) {
            Setting::put('sso.enabled', '0');

            return redirect()->route('admin.settings', ['tab' => 'sso'])->with('status', 'Single sign-on is off.');
        }

        // Switching it on without checking would leave a button that only fails.
        try {
            $this->sso->discover(Setting::get('sso.issuer'));
        } catch (\Throwable $e) {
            Setting::put('sso.enabled', '0');

            // Back to the sso tab by name: back() would drop the ?tab= when the link carried a hash.
            return redirect()->route('admin.settings', ['tab' => 'sso'])
                ->withErrors(['issuer' => $e->getMessage()])->withInput();
        }

        Setting::put('sso.enabled', '1');

        return redirect()->route('admin.settings', ['tab' => 'sso'])
            ->with('status', 'Single sign-on is on. Try it in a private window before you rely on it.');
    }

    protected function refuse(string $reason)
    {
        Audit::recordAs($this->sso->providerName(), 'sso.rejected');

        return redirect()->route('admin.login')->withErrors([
            'email' => 'Signing in through '.$this->sso->providerName().' did not work: '.$reason.'.',
        ]);
    }

    protected function callbackUrl(): string
    {
        return route('admin.sso.callback');
    }
}
