<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
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
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        // Throttled per email and IP: an admin login is worth brute forcing.
        $key = 'login:'.strtolower($data['email']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                // Minutes read easier than "293 seconds".
                'email' => 'Too many attempts. Try again in '.(function ($s) {
                    return $s >= 60 ? ceil($s / 60).' minute'.(ceil($s / 60) == 1 ? '' : 's') : $s.' seconds';
                })(RateLimiter::availableIn($key)).'.',
            ]);
        }

        // Checked without signing in, so a second factor can stand between the
        // password and the session instead of after it.
        if (! Auth::validate($data)) {
            RateLimiter::hit($key, 300);
            // Worth recording even though nobody is signed in: a run of these
            // from one address is the first sign of someone trying the door.
            Audit::recordAs($data['email'], 'auth.failed');

            throw ValidationException::withMessages(['email' => 'Those details do not match an account.']);
        }

        RateLimiter::clear($key);

        $user = User::where('email', $data['email'])->firstOrFail();

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

        return redirect()->intended(route('admin.components'));
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
