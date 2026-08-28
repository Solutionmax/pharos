<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\TestMail;
use App\Models\Setting;
use App\Services\Audit;
use App\Services\Branding;
use App\Services\Clock;
use App\Services\InstallSettings;
use App\Services\MailConfig;
use App\Services\Sso;
use App\Services\Updater;
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

    public function edit(Request $request, Sso $sso, MailConfig $mailConfig, Updater $updater)
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
            'general' => InstallSettings::all(),
            'manifestHost' => $updater->manifestHost(),
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

    /**
     * The General tab: time zone, retention and the update check in one save.
     * The checkbox is absent from the request when unticked, so it is read
     * with boolean(), not validated as required.
     */
    public function update(Request $request)
    {
        $data = $request->validate([
            'timezone' => ['required', Rule::in(\DateTimeZone::listIdentifiers())],
            'audit_days' => ['required', 'integer', 'between:7,3650'],
            'keep_backups' => ['required', 'integer', 'between:0,50'],
            'update_check' => ['sometimes', 'boolean'],
        ], [
            'audit_days.*' => 'The audit log can be kept between 7 and 3650 days.',
            'keep_backups.*' => 'Backups kept must be a number from 0 (keep all) to 50.',
        ]);

        $before = ['app.timezone' => Clock::timezone()] + $this->keyed(InstallSettings::all());

        Setting::put('app.timezone', $data['timezone']);
        InstallSettings::save([
            'audit_days' => (int) $data['audit_days'],
            'keep_backups' => (int) $data['keep_backups'],
            'update_check' => $request->boolean('update_check'),
        ]);

        $after = ['app.timezone' => Clock::timezone()] + $this->keyed(InstallSettings::all());

        // Only what changed, by setting key: nothing on this tab is a secret.
        $changes = collect($after)
            ->filter(fn ($value, $key) => $value !== $before[$key])
            ->map(fn ($value, $key) => ['from' => $before[$key], 'to' => $value])
            ->all();

        if ($changes !== []) {
            Audit::record('settings.saved', null, $changes);
        }

        return redirect()->route('admin.settings')->with('status', 'Settings saved.');
    }

    /**
     * Form fields → setting keys, so the audit line names what was stored.
     *
     * @return array<string, int|bool>
     */
    protected function keyed(array $general): array
    {
        return [
            InstallSettings::AUDIT_DAYS => $general['audit_days'],
            InstallSettings::KEEP_BACKUPS => $general['keep_backups'],
            InstallSettings::UPDATE_CHECK => $general['update_check'],
        ];
    }
}
