<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\TestMail;
use App\Models\Setting;
use App\Services\Audit;
use App\Services\Branding;
use App\Services\Clock;
use App\Services\Sso;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

/**
 * How this installation behaves: the time zone and single sign-on. Admin only.
 * What the public page shows lives on the Status page screen instead.
 */
class SettingsController extends Controller
{
    public function edit(Sso $sso)
    {
        return view('admin.settings', [
            'timezone' => Clock::timezone(),
            'offset' => Clock::offsetLabel(),
            'sso' => $sso,
            'callbackUrl' => route('admin.sso.callback'),
            'mail' => $this->mailSummary(),
        ]);
    }

    /**
     * Proves MAIL_* end to end by mailing the person who pressed the button.
     * The exception text is shown as-is: "Connection refused" is the answer
     * they came for.
     */
    public function sendTestMail(Request $request)
    {
        $user = $request->user();

        try {
            Mail::to($user->email)->send(new TestMail($user));
        } catch (\Throwable $e) {
            return redirect()->route('admin.settings')
                ->withErrors(['mail' => 'Test e-mail failed: '.$e->getMessage()]);
        }

        Audit::record('mail.test', $user);

        return redirect()->route('admin.settings')->with('status', "Test e-mail sent to {$user->email}.");
    }

    /**
     * What the install would send with, read from config so the screen never
     * disagrees with .env. No password: this page is read by every admin.
     *
     * @return array<string, string>
     */
    protected function mailSummary(): array
    {
        $mailer = (string) config('mail.default');
        $transport = config("mail.mailers.{$mailer}", []);

        return [
            'mailer' => $mailer,
            'host' => (string) ($transport['host'] ?? ''),
            'port' => (string) ($transport['port'] ?? ''),
            'from' => (string) config('mail.from.address'),
            'from_name' => (string) (config('mail.from.name') ?: app(Branding::class)->name()),
        ];
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'timezone' => ['required', Rule::in(\DateTimeZone::listIdentifiers())],
        ]);

        Setting::put('app.timezone', $data['timezone']);

        return redirect()->route('admin.settings')->with('status', 'Settings saved.');
    }
}
