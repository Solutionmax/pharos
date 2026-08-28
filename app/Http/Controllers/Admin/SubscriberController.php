<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\SubscribeConfirmMail;
use App\Models\Subscriber;
use App\Services\Audit;
use App\Services\Clock;
use App\Services\Subscriptions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

/**
 * Who gets incident mail. Open to every account: subscribers are part of
 * running the page, like incidents, not part of installing it.
 */
class SubscriberController extends Controller
{
    public function index(Request $request)
    {
        $search = trim((string) $request->query('q'));

        $subscribers = Subscriber::query()
            ->withMax('notifications', 'sent_at')
            ->when($search !== '', fn ($q) => $q->where('email', 'like', '%'.$search.'%'))
            ->latest('id')
            ->paginate(50)
            ->withQueryString();

        return view('admin.subscribers', [
            'subscribers' => $subscribers,
            'search' => $search,
            'enabled' => Subscriptions::enabled(),
            'summary' => [
                'active' => Subscriber::active()->count(),
                'pending' => Subscriber::pending()->count(),
                'unsubscribed' => Subscriber::whereNotNull('unsubscribed_at')->count(),
            ],
        ]);
    }

    /** The master switch. Operational, like the rest of this screen: pausing mail is not installing. */
    public function toggle(Request $request)
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        $enabled = (bool) $data['enabled'];

        Subscriptions::set($enabled);

        return redirect()->route('admin.subscribers')->with('status', $enabled
            ? 'Subscriptions are on: the button is back on the status page and new updates are mailed.'
            : 'Subscriptions are off: no button, no new mail. Existing addresses are kept, and unsubscribing still works.');
    }

    /** Erasure on request, in one click: the row and its notification history go together. */
    public function destroy(Subscriber $subscriber)
    {
        Audit::record('subscriber.removed', $subscriber);
        $subscriber->delete();

        return redirect()->route('admin.subscribers')
            ->with('status', "{$subscriber->email} removed, along with their notification history.");
    }

    public function resend(Subscriber $subscriber)
    {
        if (! $subscriber->isPending()) {
            return redirect()->route('admin.subscribers')
                ->withErrors(['resend' => "{$subscriber->email} is already confirmed."]);
        }

        // Fresh token: the mail they lost stops working, the one they get now does.
        $subscriber->forceFill(['token' => Subscriber::freshToken()])->save();
        Mail::to($subscriber->email)->send(new SubscribeConfirmMail($subscriber));
        Audit::record('subscriber.confirmation_resent', $subscriber);

        return redirect()->route('admin.subscribers')
            ->with('status', "Confirmation sent again to {$subscriber->email}.");
    }

    /** Active addresses only: what a data-portability request, or a move to another tool, needs. */
    public function export()
    {
        $name = 'pharos-subscribers-'.Clock::now()->format('Ymd-Hi').'.csv';
        $count = Subscriber::active()->count();

        Audit::record('subscribers.exported', null, ['active' => ['from' => '—', 'to' => (string) $count]]);

        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['email', 'subscribed_at']);

            Subscriber::active()->chunkById(500, function ($rows) use ($out) {
                foreach ($rows as $s) {
                    fputcsv($out, [$s->email, $s->verified_at->toIso8601String()]);
                }
            });

            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
