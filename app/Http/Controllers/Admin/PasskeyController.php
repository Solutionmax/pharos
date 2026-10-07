<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Passkey;
use App\Services\Audit;
use App\Services\PageUrls;
use App\Services\Passkeys;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class PasskeyController extends Controller
{
    public function createOptions(Request $r, Passkeys $keys)
    {
        $r->validate(['current_password' => ['required', 'current_password']]);
        abort_if(Passkey::where('user_id', $r->user()->id)->count() >= 10, 422);

        return response()->json($keys->options($r, 'create'));
    }

    public function register(Request $r, Passkeys $keys)
    {
        $data = $r->validate(['name' => 'required|string|max:100', 'clientDataJSON' => 'required|string|max:8192', 'attestationObject' => 'required|string|max:32768']);
        try {
            $key = $keys->register($r, $data);
        } catch (\Throwable $e) {
            throw ValidationException::withMessages(['passkey' => __('Passkey registration failed. Start again and verify your device.')]);
        }Audit::record('passkey.created', $key);

        return response()->json(['ok' => true]);
    }

    public function loginOptions(Request $r, Passkeys $keys)
    {
        return response()->json($keys->options($r, 'get'));
    }

    public function login(Request $r, Passkeys $keys)
    {
        $data = $r->validate(['id' => 'required|string|max:512', 'clientDataJSON' => 'required|string|max:8192', 'authenticatorData' => 'required|string|max:8192', 'signature' => 'required|string|max:2048', 'userHandle' => 'required|string|max:128']);
        try {
            $user = $keys->authenticate($r, $data);
        } catch (\Throwable $e) {
            throw ValidationException::withMessages(['passkey' => __('Passkey sign in failed. Start again and verify your device.')]);
        }
        $r->session()->regenerate();
        if ($user->hasTwoFactor()) {
            $r->session()->put(TwoFactorController::PENDING, $user->id);
            $r->session()->put(TwoFactorController::REMEMBER, false);

            return response()->json(['redirect' => route('admin.two-factor')]);
        }Auth::login($user);
        $r->session()->regenerate();
        Audit::record('auth.login');

        return response()->json(['redirect' => PageUrls::landing($user)]);
    }

    public function destroy(Request $r, int $passkey)
    {
        $key = Passkey::whereKey($passkey)->where('user_id', $r->user()->id)->firstOrFail();
        $r->validate(['current_password' => ['required', 'current_password']]);
        $key->delete();
        Audit::record('passkey.deleted');

        return back()->with('status', __('Passkey removed.'));
    }
}
