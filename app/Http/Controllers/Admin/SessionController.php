<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Audit;
use App\Services\UserSessions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * "Where you are signed in": ending your own other sessions. Every query is
 * bound to the signed in user's id, so no other account's session is touched.
 */
class SessionController extends Controller
{
    public function destroy(Request $request, UserSessions $sessions, string $session): RedirectResponse
    {
        $user = $request->user();
        if (! $sessions->destroy($user, $session, $request->session()->getId())) {
            return redirect()->route('admin.profile')->withErrors(['session' => 'That session has already ended.']);
        }
        $this->forgetRememberedDevices($request);
        Audit::record('auth.session_ended', $user);

        return redirect()->route('admin.profile')->with('status', 'That session is signed out.');
    }

    public function destroyOthers(Request $request, UserSessions $sessions): RedirectResponse
    {
        $user = $request->user();
        $count = $sessions->destroyOthers($user, $request->session()->getId());
        $this->forgetRememberedDevices($request);
        Audit::record('auth.sessions_ended', $user);

        return redirect()->route('admin.profile')->with('status', $count
            ? 'Signed out everywhere else ('.$count.' '.Str::plural('session', $count).').'
            : 'There were no other sessions.');
    }

    /**
     * A device that ticked "remember me" would sign straight back in with its
     * cookie. A new remember token makes those cookies worthless; this browser
     * keeps its session, it only loses its own remember cookie.
     */
    protected function forgetRememberedDevices(Request $request): void
    {
        $request->user()->forceFill(['remember_token' => Str::random(60)])->save();
    }
}
