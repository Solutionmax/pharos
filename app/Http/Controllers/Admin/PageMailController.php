<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\TestMail;
use App\Services\Audit;
use App\Services\Branding;
use App\Services\MailConfig;
use App\Services\PageContext;
use App\Services\PageUrls;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class PageMailController extends Controller
{
    public function edit(MailConfig $mailConfig)
    {
        return view('admin.page-mail', [
            'mailPage' => app(PageContext::class)->page(),
            'mailForm' => $mailConfig->storedPage(),
            'effective' => $mailConfig->effectivePage(),
            'mailHasPassword' => $mailConfig->pageHasPassword(),
            'brandName' => app(Branding::class)->name(),
        ]);
    }

    public function update(Request $request, MailConfig $mailConfig): RedirectResponse
    {
        $data = $request->validate([
            'mode' => ['required', Rule::in(['central', 'custom'])],
            'host' => ['nullable', 'required_if:mode,custom', 'string', 'max:255'],
            'port' => ['nullable', 'required_if:mode,custom', 'integer', 'between:1,65535'],
            'encryption' => ['required', Rule::in(MailConfig::ENCRYPTIONS)],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:1000'],
            'from_address' => ['nullable', 'required_if:mode,custom', 'email:rfc', 'max:254'],
            'from_name' => ['nullable', 'string', 'max:255'],
            'reply_to' => ['nullable', 'email:rfc', 'max:254'],
        ]);

        $mailConfig->savePage($data);
        Audit::record('page.mail_settings_saved', null, [
            'mode' => ['from' => '—', 'to' => $data['mode']],
            'host' => ['from' => '—', 'to' => (string) ($data['host'] ?? '')],
        ]);

        return redirect()->to(PageUrls::route('admin.mail.edit'))->with('status', 'Page e-mail settings saved.');
    }

    public function test(Request $request, MailConfig $mailConfig): RedirectResponse
    {
        $user = $request->user();

        try {
            $mailConfig->sendTo($user->email, new TestMail($user));
        } catch (\Throwable $e) {
            Log::warning('Page test e-mail failed', [
                'page' => app(PageContext::class)->id(),
                'type' => $e::class,
            ]);

            return redirect()->to(PageUrls::route('admin.mail.edit'))
                ->withErrors(['mail' => 'Test e-mail failed. Check this page’s mail settings and server log.']);
        }

        Audit::record('page.mail_test', $user);

        return redirect()->to(PageUrls::route('admin.mail.edit'))
            ->with('status', "Test e-mail sent to {$user->email}.");
    }
}
