<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Audit;
use App\Services\PageUrls;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /** Failed attempts per address and IP: an admin login is worth brute forcing. */
    public const MAX_PER_ACCOUNT = 5;

    /**
     * Failed attempts per IP, whatever the address: one source trying a single
     * password against many addresses never reaches the limit above.
     */
    public const MAX_PER_IP = 20;

    /** Both limits count failures over the same five minutes. */
    public const DECAY_SECONDS = 300;

    public function form()
    {
        // A fresh install has nobody to sign in as. Send them to set one up
        // rather than to a form no password can pass.
        if (! User::exists()) {
            return redirect()->route('admin.install');
        }

        return view('admin.login');
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string'],
        ]);

        $key = 'login:'.strtolower($data['email']).'|'.$request->ip();
        $ipKey = 'login-ip:'.$request->ip();

        foreach ([$key => self::MAX_PER_ACCOUNT, $ipKey => self::MAX_PER_IP] as $limited => $max) {
            if (RateLimiter::tooManyAttempts($limited, $max)) {
                throw ValidationException::withMessages([
                    // Minutes read easier than "293 seconds".
                    'email' => 'Too many attempts. Try again in '.(function ($s) {
                        return $s >= 60 ? ceil($s / 60).' minute'.(ceil($s / 60) == 1 ? '' : 's') : $s.' seconds';
                    })(RateLimiter::availableIn($limited)).'.',
                ]);
            }
        }

        // Checked without signing in, so a second factor can stand between the
        // password and the session instead of after it.
        if (! Auth::validate($data)) {
            RateLimiter::hit($key, self::DECAY_SECONDS);
            RateLimiter::hit($ipKey, self::DECAY_SECONDS);
            // Worth recording even though nobody is signed in: a run of these
            // from one address is the first sign of someone trying the door.
            Audit::recordAs($data['email'], 'auth.failed');

            throw ValidationException::withMessages(['email' => 'Those details do not match an account.']);
        }

        // Only the per account count: signing in to an account of your own
        // must not wipe the failures this address piled up against others.
        RateLimiter::clear($key);

        $user = User::where('email', $data['email'])->firstOrFail();

        // The password just checked out, so any session id the browser walked in
        // with (fixated by an attacker before the victim ever typed a password)
        // must not survive into an authenticated, or half-authenticated, state.
        // Auth::login() below does this for the no-2FA path on its own; the
        // pending-2FA path needs its own regenerate since it deliberately skips
        // Auth::login() until the code checks out.
        $request->session()->regenerate();

        if ($user->hasTwoFactor()) {
            // Nothing is authenticated yet: only an id travels to the next screen,
            // and "remember me" waits there too rather than becoming a way around it.
            $request->session()->put(TwoFactorController::PENDING, $user->id);
            $request->session()->put(TwoFactorController::REMEMBER, $request->boolean('remember'));

            return redirect()->route('admin.two-factor');
        }

        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();
        Audit::record('auth.login');

        return redirect()->intended(PageUrls::landing(auth()->user()));
    }

    public function logout(Request $request)
    {
        Audit::record('auth.logout');
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }
}
