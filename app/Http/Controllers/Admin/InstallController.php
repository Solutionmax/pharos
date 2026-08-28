<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rules\Password;

/**
 * The first-run screen. Without it the only way into a fresh install is
 * `php artisan pharos:user`, which a shared hosting account cannot run.
 *
 * There is no lock file and no install token: an account existing IS the lock.
 * That leaves a window between migrating and finishing this form in which
 * whoever arrives first becomes the administrator — the same trade every
 * self-hosted installer makes. The window closes on submit.
 */
class InstallController extends Controller
{
    public function form()
    {
        if (User::exists()) {
            return redirect()->route('admin.login');
        }

        return view('admin.install');
    }

    public function store(Request $request)
    {
        if (User::exists()) {
            return redirect()->route('admin.login');
        }

        $data = $request->validate([
            'site' => ['required', 'string', 'max:60'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(12)],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
        ]);

        Setting::put('brand.name', $data['site']);

        $this->linkStorage();

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('admin.components')
            ->with('status', 'Pharos is installed. Add your first component to put something on the status page.');
    }

    /**
     * An uploaded logo lands on the public disk, which is only reachable through
     * public/storage. A browser-only install never gets to run storage:link, so
     * this is the one moment we can make that symlink for them.
     *
     * Failing is survivable — branding images stay broken until someone links it
     * by hand — so it must not take the install down with it.
     */
    private function linkStorage(): void
    {
        if (file_exists(public_path('storage'))) {
            return;
        }

        try {
            Artisan::call('storage:link');
        } catch (\Throwable $e) {
            Log::warning('Could not create public/storage during install: '.$e->getMessage());
        }
    }
}
