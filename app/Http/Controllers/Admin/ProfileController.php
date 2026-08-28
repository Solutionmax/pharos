<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\RecoveryCode;
use App\Services\Audit;
use App\Services\Totp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Your own account: name, email, password and the second factor. Everything here
 * is about the person signed in, never about somebody else's account.
 */
class ProfileController extends Controller
{
    public function __construct(protected Totp $totp) {}

    public function show(Request $request)
    {
        $user = $request->user();

        return view('admin.profile', [
            'user' => $user,
            // Present only between "start setting up" and confirming a code.
            'pendingSecret' => $user->totp_secret && ! $user->hasTwoFactor() ? $user->totp_secret : null,
            'otpauthUri' => $user->totp_secret && ! $user->hasTwoFactor()
                ? $this->totp->uri($user->totp_secret, $user->email, config('app.name'))
                : null,
            'recoveryLeft' => $user->hasTwoFactor() ? $user->recoveryCodes()->whereNull('used_at')->count() : 0,
            'hiddenNotes' => count($user->dismissed_notes ?? []),
            'hiddenByPage' => \App\Services\Notes::hiddenByPage($user->dismissed_notes ?? []),
        ]);
    }

    /** "Got it" on a note. The script answers with fetch; without it the form posts and comes back. */
    public function dismissNote(Request $request, string $id)
    {
        $request->user()->dismissNote($id);

        return $request->expectsJson() ? response()->noContent() : back();
    }

    public function restoreNote(Request $request, string $id)
    {
        $request->user()->restoreNote($id);

        return back()->with('status', 'That note is back.');
    }

    public function restoreNotes(Request $request)
    {
        $request->user()->restoreNotes();

        return back()->with('status', 'All notes are back.');
    }

    public function update(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', Rule::unique('users', 'email')->ignore($user)],
        ]);

        $user->update($data);

        return redirect()->route('admin.profile')->with('status', 'Your details have been saved.');
    }

    /** Hands out a secret. Nothing changes for signing in until a code confirms it. */
    public function startTwoFactor(Request $request)
    {
        $user = $request->user();

        if ($user->hasTwoFactor()) {
            return back()->withErrors(['code' => 'Two-factor authentication is already on.']);
        }

        $user->forceFill(['totp_secret' => $this->totp->secret(), 'totp_last_step' => null])->save();

        return redirect()->route('admin.profile')
            ->with('status', 'Add the key to your authenticator app, then enter a code to switch it on.');
    }

    public function confirmTwoFactor(Request $request)
    {
        $user = $request->user();
        $request->validate(['code' => ['required', 'string']]);

        if (! $user->totp_secret || $user->hasTwoFactor()) {
            return back()->withErrors(['code' => 'Start the setup first.']);
        }

        $step = $this->totp->verify($user->totp_secret, $request->input('code'), $user->totp_last_step);

        if ($step === null) {
            return back()->withErrors(['code' => 'That code did not match. Check your phone clock and try the next one.']);
        }

        $user->forceFill(['totp_confirmed_at' => now(), 'totp_last_step' => $step])->save();
        Audit::record('2fa.enabled', $user);

        // Shown once, on the next screen, and never recoverable afterwards.
        return redirect()->route('admin.profile')
            ->with('recovery_codes', RecoveryCode::replaceFor($user))
            ->with('status', 'Two-factor authentication is on.');
    }

    public function disableTwoFactor(Request $request)
    {
        $user = $request->user();
        $request->validate(['current_password' => ['required', 'current_password']]);

        $user->forceFill(['totp_secret' => null, 'totp_confirmed_at' => null, 'totp_last_step' => null])->save();
        $user->recoveryCodes()->delete();
        Audit::record('2fa.disabled', $user);

        return redirect()->route('admin.profile')->with('status', 'Two-factor authentication is off.');
    }

    public function regenerateRecoveryCodes(Request $request)
    {
        $user = $request->user();
        $request->validate(['current_password' => ['required', 'current_password']]);

        abort_unless($user->hasTwoFactor(), 403);

        return redirect()->route('admin.profile')
            ->with('recovery_codes', RecoveryCode::replaceFor($user))
            ->with('status', 'New recovery codes. The old ones no longer work.');
    }

    /** Kept here rather than on UserController: it is your account, not the list. */
    public function updatePassword(Request $request)
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::min(12)],
        ]);

        $request->user()->update(['password' => Hash::make($data['password'])]);
        Auth::logoutOtherDevices($data['password']);

        return redirect()->route('admin.profile')
            ->with('status', 'Your password has been changed. Any other session on your account was signed out.');
    }
}
