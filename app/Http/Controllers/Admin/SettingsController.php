<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\TestMail;
use App\Models\Setting;
use App\Services\Audit;
use App\Services\Branding;
use App\Services\Clock;
use App\Services\MailConfig;
use App\Services\Sso;
use App\Services\Subscriptions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

/**
 * How this installation behaves: the time zone, mail and single sign-on. Admin
 * only. What the public page shows lives on the Status page screen instead.
 */
class SettingsController extends Controller
{
    /** The tabs, in order. ?tab= picks one; anything else is General. */
    public const TABS = ['general', 'mail', 'sso'];

    public function edit(Request $request, Sso $sso, MailConfig $mailConfig)
    {
        $mail = $mailConfig->effective();

        return view('admin.settings', [
            'tab' => $this->tab($request),
            // The word beside each tab: its state, so you know before you open it.
            'tabs' => [
                'general' => Clock::timezone(),
                'mail' => $mail['mailer'].($mail['mailer'] === 'smtp' && $mail['host'] !== '' ? ' via '.$mail['host'] : ''),
                'sso' => $sso->enabled() ? 'On' : 'Off',
            ],
            'timezone' => Clock::timezone(),
            'offset' => Clock::offsetLabel(),
            'sso' => $sso,
            'callbackUrl' => route('admin.sso.callback'),
            'mail' => $mail,
            'mailForm' => $mailConfig->stored(),
            'mailHasPassword' => $mailConfig->hasPassword(),
            'brandName' => app(Branding::class)->name(),
            'subscriptionsOn' => Subscriptions::enabled(),
        ]);
    }

    /**
     * Which tab to show: the query wins, then the _tab a form carried when its
     * validation failed — a redirect back drops the query but keeps old input.
     */
    protected function tab(Request $request): string
    {
        $tab = $request->query('tab') ?? $request->old('_tab');

        return in_array($tab, self::TABS, true) ? $tab : 'general';
    }

    /**
     * Stores the mail settings. The password is the one value never shown
     * again, so an empty box keeps what is there (MailConfig::save).
     */
    public function updateMail(Request $request, MailConfig $mailConfig)
    {
        $data = $request->validate([
            'mailer' => ['required', Rule::in(MailConfig::MAILERS)],
            'host' => ['nullable', 'string', 'max:255', 'required_if:mailer,smtp'],
            'port' => ['nullable', 'integer', 'between:1,65535', 'required_if:mailer,smtp'],
            'encryption' => ['nullable', Rule::in(MailConfig::ENCRYPTIONS)],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
            'from_address' => ['nullable', 'email:rfc', 'max:254'],
            'from_name' => ['nullable', 'string', 'max:120'],
        ], [
            'host.required_if' => 'SMTP needs a host.',
            'port.required_if' => 'SMTP needs a port.',
        ]);

        $before = $mailConfig->stored();
        $mailConfig->save($data);
        $after = $mailConfig->stored();

        // What changed, minus the password: the diff only says *that* it changed.
        $changes = collect($after)
            ->filter(fn ($value, $field) => $value !== $before[$field])
            ->map(fn ($value, $field) => ['from' => $before[$field], 'to' => $value])
            ->all();

        if (filled($data['password'] ?? null)) {
            $changes['password'] = ['from' => '••••', 'to' => '••••'];
        }

        Audit::record('mail.settings_saved', null, $changes);

        return redirect()->route('admin.settings', ['tab' => 'mail'])->with('status', 'Mail settings saved.');
    }

    /**
     * Proves the mail settings end to end by mailing the person who pressed the
     * button. The exception text is shown as-is: "Connection refused" is the
     * answer they came for.
     */
    public function sendTestMail(Request $request)
    {
        $user = $request->user();

        try {
            Mail::to($user->email)->send(new TestMail($user));
        } catch (\Throwable $e) {
            return redirect()->route('admin.settings', ['tab' => 'mail'])
                ->withErrors(['mail' => 'Test e-mail failed: '.$e->getMessage()]);
        }

        Audit::record('mail.test', $user);

        return redirect()->route('admin.settings', ['tab' => 'mail'])->with('status', "Test e-mail sent to {$user->email}.");
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
