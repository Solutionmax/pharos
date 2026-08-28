<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\TemplatePreviewMail;
use App\Services\Audit;
use App\Services\License;
use App\Services\MailTemplates;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class MailTemplateController extends Controller
{
    public function __construct(protected License $license, protected MailTemplates $templates) {}

    public function edit(Request $request)
    {
        $key = $this->key($request->query('template'));
        $sample = $this->templates->render($key, $this->templates->sample($key, $request->user()));

        return view('admin.mail-templates', [
            'key' => $key,
            'labels' => MailTemplates::labels(),
            'tags' => MailTemplates::tags($key),
            'subject' => $this->templates->subject($key),
            'body' => $this->templates->body($key),
            'isDefault' => $this->templates->isDefault($key),
            'licensed' => $this->license->has(License::FEATURE_BRAND_PACK),
            'previewSubject' => $sample['subject'],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        if ($refused = $this->refuse($request)) {
            return $refused;
        }

        $this->templates->save($request->input('template'), $request->input('subject'), $request->input('body'));
        Audit::record('mail_template.saved', null, ['template' => $request->input('template')]);

        return $this->back($request->input('template'))->with('status', 'Template saved.');
    }

    public function reset(Request $request): RedirectResponse
    {
        if ($refused = $this->refuse($request, ['subject', 'body'])) {
            return $refused;
        }

        $this->templates->reset($request->input('template'));
        Audit::record('mail_template.reset', null, ['template' => $request->input('template')]);

        return $this->back($request->input('template'))->with('status', 'Template reset to the default.');
    }

    /** The template as it is on the form, unsaved, with the sample data, to the signed-in admin. */
    public function sendTest(Request $request): RedirectResponse
    {
        if ($refused = $this->refuse($request)) {
            return $refused;
        }

        $user = $request->user();
        $key = $request->input('template');
        $rendered = $this->templates->render($key, $this->templates->sample($key, $user), $request->input('subject'), $request->input('body'));

        try {
            Mail::to($user->email)->send(new TemplatePreviewMail($rendered));
        } catch (\Throwable $e) {
            return $this->back($key)->withErrors(['mail' => 'Test e-mail failed: '.$e->getMessage()]);
        }

        Audit::record('mail.test', $user);

        return $this->back($key)->with('status', "Test e-mail sent to {$user->email}.");
    }

    /**
     * The mail rendered from the form's unsaved values with sample data. As JSON
     * for the live preview, as a page for the iframe's first load. Writes
     * nothing, and needs no licence: a free install may look at what it sends.
     */
    public function preview(Request $request)
    {
        $key = $this->key($request->input('template'));
        $rendered = $this->templates->render(
            $key,
            $this->templates->sample($key, $request->user()),
            $request->filled('subject') ? (string) $request->input('subject') : null,
            $request->filled('body') ? (string) $request->input('body') : null,
        );

        if ($request->expectsJson()) {
            return response()->json(['subject' => $rendered['subject'], 'html' => $rendered['html']]);
        }

        // Every other page refuses to be framed (SecurityHeaders). This one is
        // built for the iframe on the Mail templates screen, so it alone allows
        // its own origin; the middleware leaves headers already set untouched.
        return response($rendered['html'])->withHeaders([
            'X-Frame-Options' => 'SAMEORIGIN',
            'Content-Security-Policy' => "frame-ancestors 'self'",
        ]);
    }

    /**
     * Validation and the licence gate, shared by the three writes. Hiding the
     * buttons alone would only be decoration.
     *
     * @param  list<string>  $except  rules to skip (reset carries no subject or body)
     */
    protected function refuse(Request $request, array $except = []): ?RedirectResponse
    {
        $rules = [
            'template' => ['required', Rule::in(MailTemplates::keys())],
            'subject' => ['required', 'string', 'max:200', 'not_regex:/<script/i'],
            'body' => ['required', 'string', 'max:20000', 'not_regex:/<script/i'],
        ];
        $validator = Validator::make($request->all(), array_diff_key($rules, array_flip($except)), [
            'not_regex' => 'Scripts have no place in a mail; the :attribute may not contain <script>.',
        ]);

        if ($validator->fails()) {
            return $this->back($request->input('template'))->withErrors($validator)->withInput();
        }

        if (! $this->license->has(License::FEATURE_BRAND_PACK)) {
            return $this->back($request->input('template'))
                ->withErrors(['template' => 'Editing the mail templates is part of the brand pack.']);
        }

        return null;
    }

    protected function key(mixed $requested): string
    {
        return in_array($requested, MailTemplates::keys(), true) ? $requested : MailTemplates::keys()[0];
    }

    protected function back(mixed $key): RedirectResponse
    {
        return redirect()->route('admin.mail-templates', ['template' => $this->key($key)]);
    }
}
