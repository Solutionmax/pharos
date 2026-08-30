<?php

namespace App\Http\Controllers;

use App\Mail\SubscribeConfirmMail;
use App\Models\Subscriber;
use App\Services\Subscriptions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * The public side of subscriptions. Every answer to the sign-up form is the
 * same sentence, whatever the address already is, so the form cannot be used
 * to find out who subscribed.
 */
class SubscribeController extends Controller
{
    public const REPLY = 'If that address is new, a confirmation is on its way.';

    public function store(Request $request)
    {
        // Switched off in the admin: the form is not on the page, so a POST here
        // is a stale tab or a script. 404, like the confirm link below.
        abort_unless(Subscriptions::enabled(), 404);

        // A bot fills every field; a person never sees this one.
        if ($request->filled('website')) {
            return $this->reply();
        }

        $data = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
        ]);

        $email = mb_strtolower(trim($data['email']));
        $subscriber = Subscriber::where('email', $email)->first();

        // Already confirmed and still in: nothing to send, and saying so would
        // confirm the address exists.
        if ($subscriber?->isActive()) {
            return $this->reply();
        }

        // A new token voids the link in any earlier confirmation mail.
        $subscriber = Subscriber::updateOrCreate(['email' => $email], [
            'token' => Subscriber::freshToken(),
            'created_ip' => $subscriber->created_ip ?? $request->ip(),
        ]);

        try {
            Mail::to($subscriber->email)->send(new SubscribeConfirmMail($subscriber));
        } catch (\Throwable $e) {
            // A host that cannot send (no SMTP host yet, wrong password, port closed) is the
            // operator's problem, not the visitor's: say so plainly and leave the details in the log.
            Log::error('Subscribe confirmation mail failed', ['email' => $subscriber->email, 'error' => $e->getMessage()]);

            return redirect()->route('status')
                ->withInput()
                ->withErrors(['email' => 'The confirmation e-mail could not be sent right now. Please try again later.']);
        }

        return $this->reply();
    }

    public function confirm(Request $request, Subscriber $subscriber)
    {
        abort_unless(Subscriptions::enabled(), 404);
        $this->guardToken($request, $subscriber);

        // Idempotent: mail scanners open links before the reader does, and a
        // second click must not undo or repeat anything.
        if (! $subscriber->isActive()) {
            $subscriber->forceFill([
                'verified_at' => $subscriber->verified_at ?? now(),
                'unsubscribed_at' => null,
            ])->save();
        }

        return $this->page('subscribed', $subscriber);
    }

    /**
     * GET from the mail footer, POST from a mail client's own one-click button.
     * Not behind the master switch: the mails already sent carry this link, and
     * leaving must work whatever the admin has done since.
     */
    public function unsubscribe(Request $request, Subscriber $subscriber)
    {
        $this->guardToken($request, $subscriber);

        if ($subscriber->unsubscribed_at === null) {
            $subscriber->forceFill(['unsubscribed_at' => now()])->save();
        }

        return $this->page('unsubscribed', $subscriber);
    }

    /** The signature proves the URL is ours; the token proves it is the *current* one. */
    protected function guardToken(Request $request, Subscriber $subscriber): void
    {
        abort_unless(hash_equals($subscriber->token, (string) $request->query('token')), 403);
    }

    protected function reply()
    {
        return redirect()->route('status')->with('subscribed', self::REPLY);
    }

    protected function page(string $outcome, Subscriber $subscriber)
    {
        return view('public.subscribe-result', [
            'outcome' => $outcome,
            'subscriber' => $subscriber,
        ]);
    }
}
