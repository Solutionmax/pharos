<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\User;
use App\Services\InitialSetup;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/** First administrator registration requires a filesystem-owned setup key. */
class InstallController extends Controller
{
    public function form()
    {
        if (User::exists()) {
            return redirect()->route('admin.login');
        }

        app(InitialSetup::class)->key();

        return view('admin.install', ['setupKeyPath' => app(InitialSetup::class)->path()]);
    }

    public function store(Request $request)
    {
        if (User::exists()) {
            return redirect()->route('admin.login');
        }

        abort_unless(hash_equals(app(InitialSetup::class)->key(), (string) $request->input('setup_key')), 403, 'Read the setup key from the private installation file or installer.');

        $data = $request->validate([
            'site' => ['required', 'string', 'max:60'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(12)],
            'timezone' => ['nullable', Rule::in(\DateTimeZone::listIdentifiers())],
        ]);

        $user = Cache::lock('pharos:first-admin', 30)->block(5, function () use ($data) {
            return DB::transaction(function () use ($data) {
                abort_if(User::exists(), 409, 'Installation already completed.');
                $user = User::create([
                    'name' => $data['name'],
                    'email' => $data['email'],
                    'password' => Hash::make($data['password']),
                ]);

                Setting::put('brand.name', $data['site']);
                Setting::put('app.timezone', $data['timezone'] ?? 'UTC');

                return $user;
            });
        });

        $this->linkStorage();

        Auth::login($user);
        $request->session()->regenerate();

        // Step 7 of the install journey. Flashed, so it shows once and a reload
        // simply lands on the overview.
        return redirect()->route('admin.install.done')->with('pharos.installed', true);
    }

    public function done(Request $request)
    {
        if (! $request->session()->get('pharos.installed')) {
            return redirect()->route('admin.overview');
        }

        $stamp = Setting::get('checks.last_run_at');
        $url = (string) config('app.url');

        return view('admin.install-done', [
            'site' => (string) Setting::get('brand.name', 'Your status page'),
            'version' => (string) config('pharos.version'),
            'address' => parse_url($url, PHP_URL_HOST) ?: $request->getHost(),
            'database' => match (config('database.default')) {
                'sqlite' => 'SQLite',
                'mysql' => 'MySQL',
                'mariadb' => 'MariaDB',
                'pgsql' => 'PostgreSQL',
                default => (string) config('database.default'),
            },
            'schedulerRunning' => $stamp && Carbon::parse($stamp)->gt(now()->subMinutes(5)),
        ]);
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
