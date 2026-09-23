<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

/**
 * Accepting an invitation: the new account chooses its own password. The token
 * comes from the "invitations" broker, so a reset link cannot be used here and
 * an invitation link cannot be used as a reset.
 */
class InvitationController extends Controller
{
    public const BROKER = 'invitations';

    public function show(Request $request, string $token)
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:255']]);

        return response()->view('admin.reset-password', ['token' => $token, 'email' => $data['email'], 'invitation' => true])
            ->header('Referrer-Policy', 'no-referrer');
    }

    public function accept(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'token' => ['required', 'string', 'max:255'],
            'password' => ['required', 'confirmed', PasswordRule::min(12)],
        ]);

        $status = Password::broker(self::BROKER)->reset($data, function (User $user, string $password) {
            $user->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save();
            Audit::recordAs($user->email, 'auth.invitation_accepted', $user);
        });

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => 'This invitation is invalid or has expired. Ask an administrator to send a new one.']);
        }

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login')->with('status', 'Your password is set. Sign in to continue.');
    }
}
