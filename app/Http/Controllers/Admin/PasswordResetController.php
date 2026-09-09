<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Audit;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Throwable;

class PasswordResetController extends Controller
{
    public const SENT_MESSAGE = 'If an account matches that email, a reset link will arrive shortly. Check your spam folder too.';

    public function requestForm()
    {
        return view('admin.forgot-password');
    }

    public function sendLink(Request $request)
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:255']]);

        try {
            Password::sendResetLink($data);
        } catch (Throwable $exception) {
            // Report a delivery failure for the operator, without revealing accounts.
            report($exception);
        }

        return redirect()->route('admin.password.request')->with('status', self::SENT_MESSAGE);
    }

    public function resetForm(Request $request, string $token)
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:255']]);

        return response()->view('admin.reset-password', ['token' => $token, 'email' => $data['email']])
            ->header('Referrer-Policy', 'no-referrer');
    }

    public function reset(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'token' => ['required', 'string', 'max:255'],
            'password' => ['required', 'confirmed', PasswordRule::min(12)],
        ]);

        $status = Password::reset($data, function (User $user, string $password) {
            $user->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save();
            Audit::recordAs($user->email, 'auth.password_reset', $user);
            event(new PasswordReset($user));
        });

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => 'This reset link is invalid or has expired. Request a new link to try again.']);
        }

        // Do not sign in automatically: the normal login still requires 2FA.
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login')->with('status', 'Your password has been reset. Sign in with your new password.');
    }
}
