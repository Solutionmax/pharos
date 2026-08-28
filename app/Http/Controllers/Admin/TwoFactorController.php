<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\RecoveryCode;
use App\Models\User;
use App\Services\Audit;
use App\Services\Totp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * The second step of signing in. Nobody is authenticated here yet: the password
 * stage left an id in the session and nothing more, so a request that arrives
 * without it goes back to the form.
 */
class TwoFactorController extends Controller
{
    /** Session keys written by AuthController once the password checked out. */
    public const PENDING = '2fa.user';

    public const REMEMBER = '2fa.remember';

    public function __construct(protected Totp $totp) {}

    public function form(Request $request)
    {
        return $this->pending($request)
            ? view('admin.two-factor')
            : redirect()->route('admin.login');
    }

    public function verify(Request $request)
    {
        $user = $this->pending($request);

        if (! $user) {
            return redirect()->route('admin.login');
        }

        $request->validate(['code' => ['required', 'string']]);
        $code = $request->input('code');

        // Six digits are guessable if you may keep guessing.
        $key = '2fa:'.$user->id.'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'code' => 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.',
            ]);
        }

        $step = $this->totp->verify($user->totp_secret, $code, $user->totp_last_step);

        if ($step !== null) {
            $user->forceFill(['totp_last_step' => $step])->save();

            return $this->signIn($request, $user);
        }

        if (RecoveryCode::consume($user, $code)) {
            Audit::recordAs($user->email, '2fa.recovery_used', $user);

            return $this->signIn($request, $user);
        }

        RateLimiter::hit($key, 300);
        Audit::recordAs($user->email, 'auth.2fa_failed');

        throw ValidationException::withMessages(['code' => 'That code did not match.']);
    }

    protected function signIn(Request $request, User $user)
    {
        // Only now, so "remember me" cannot become a way around this screen.
        Auth::login($user, $request->session()->pull(self::REMEMBER, false));

        $request->session()->forget(self::PENDING);
        $request->session()->regenerate();
        Audit::record('auth.login');

        return redirect()->intended(route('admin.components'));
    }

    protected function pending(Request $request): ?User
    {
        $id = $request->session()->get(self::PENDING);

        return $id ? User::find($id) : null;
    }
}
