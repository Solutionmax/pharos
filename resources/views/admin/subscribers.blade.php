@extends('layouts.admin')
@section('title', 'Subscribers')
@section('content')
<style>.pill.off{color:var(--ink-3);background:var(--bg-tint)}</style>
@include('partials.pagehead', [
  'title' => 'Subscribers',
  'sub' => 'Who gets an e-mail when an incident is reported or resolved',
])

<div class="tiles">
  <div class="tile good"><span class="k">Active</span><span class="v">{{ $summary['active'] }}</span><span class="n">confirmed, receiving mail</span></div>
  <div class="tile warn"><span class="k">Pending confirmation</span><span class="v">{{ $summary['pending'] }}</span><span class="n">forgotten after {{ \App\Models\Subscriber::PENDING_DAYS }} days</span></div>
  <div class="tile"><span class="k">Unsubscribed</span><span class="v">{{ $summary['unsubscribed'] }}</span><span class="n">kept so they are not mailed again</span></div>
</div>

<div class="panel" id="switch">
  <div class="panel-hd">
    <h3>Subscriptions</h3>
    <span class="hint">{{ $enabled ? 'On' : 'Off' }}</span>
  </div>
  <div class="panel-bd">
    <form method="POST" action="{{ route('admin.subscribers.toggle') }}">
      @csrf
      <input type="hidden" name="enabled" value="{{ $enabled ? '0' : '1' }}">
      <div class="switchrow">
        <span class="t">
          @if ($enabled)
            <strong><span class="pill ok">On</span> — visitors can subscribe</strong>
            <span class="s">The "Get notified" button is on the status page and every public incident update is mailed.</span>
          @else
            <strong><span class="pill off">Off</span> — no button, no mail, existing addresses kept</strong>
            <span class="s">Nothing new is queued while this is off; anything queued before still goes out, and unsubscribe links keep working.</span>
          @endif
        </span>
        <button class="btn {{ $enabled ? 'ghost' : '' }}" type="submit" style="margin-left:auto">{{ $enabled ? 'Switch off' : 'Switch on' }}</button>
      </div>
    </form>
  </div>
</div>

<div class="panel">
  <div class="panel-hd">
    <h3>Addresses</h3>
    <span class="hint">{{ $subscribers->total() }} {{ \Illuminate\Support\Str::plural('address', $subscribers->total()) }}</span>
  </div>

  <div class="panel-bd" style="border-bottom:1px solid var(--line)">
    <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
      <div class="field" style="flex:1;min-width:180px">
        <label for="q">Search</label>
        <input id="q" name="q" type="text" value="{{ $search }}" placeholder="part of an e-mail address">
      </div>
      <button class="btn" type="submit">Search</button>
      @if ($search !== '')
        <a class="btn ghost" href="{{ route('admin.subscribers') }}">Clear</a>
      @endif
      @if ($summary['active'] > 0)
        <a class="btn ghost" style="margin-left:auto" href="{{ route('admin.subscribers.export') }}"
           title="Every active address, as CSV">Export CSV</a>
      @endif
    </form>
  </div>

  @if ($subscribers->isEmpty())
    <div class="empty">
      @include('partials.icon', ['name' => 'mail', 'size' => 28])
      <b>{{ $search !== '' ? 'No address matches' : 'Nobody has subscribed yet' }}</b>
      @if ($search === '')
        The "Get notified" button on the status page is where visitors sign up.
      @endif
    </div>
  @else
    <div class="scroll">
      <table>
        <thead><tr><th>E-mail</th><th>Status</th><th>Subscribed</th><th>Last notification</th><th></th></tr></thead>
        <tbody>
        @foreach ($subscribers as $s)
          <tr>
            <td class="mono" style="font-size:13px">{{ $s->email }}</td>
            <td>
              @if ($s->isActive())<span class="pill ok">Active</span>
              @elseif ($s->isPending())<span class="pill w">Pending</span>
              @else<span class="pill off">Unsubscribed</span>@endif
            </td>
            <td class="num">{{ ($s->verified_at ?? $s->created_at)->format('d M Y') }}</td>
            <td class="num">
              @if ($s->notifications_max_sent_at)
                {{ \Carbon\CarbonImmutable::parse($s->notifications_max_sent_at, 'UTC')->setTimezone(\App\Services\Clock::timezone())->format('d M H:i') }}
              @else
                —
              @endif
            </td>
            <td>
              <span class="rowacts">
                @if ($s->isPending())
                  <form method="POST" action="{{ route('admin.subscribers.resend', $s) }}">
                    @csrf
                    <button type="submit">Resend confirmation</button>
                  </form>
                @endif
                <form method="POST" action="{{ route('admin.subscribers.destroy', $s) }}"
                      data-confirm-title="Remove {{ $s->email }}?"
                      data-confirm="The address and its notification history are <strong>deleted</strong>. This is how you honour a request to be forgotten."
                      data-confirm-action="Remove address">
                  @csrf @method('DELETE')
                  <button type="submit">Delete</button>
                </form>
              </span>
            </td>
          </tr>
        @endforeach
        </tbody>
      </table>
    </div>
    <div class="panel-bd">{{ $subscribers->links() }}</div>
  @endif
</div>

@unless (app(\App\Services\MailConfig::class)->configured())
<x-note id="subscribers.no-mail" warn>
  <b>No mail transport yet.</b> The "Get notified" form stays off the public page, and nothing is
  sent, until <a href="{{ route('admin.settings', ['tab' => 'mail']) }}">Settings → Mail</a> has an SMTP
  host — a form that ends in an error helps nobody.
</x-note>
@endunless
<x-note id="subscribers.how">
  <p><b>How it works.</b> A visitor enters an address, confirms it from the mail they get, and from
  then on receives every update on a public incident. Sending runs from the same cron line as the
  checks (<span class="mono">pharos:notify</span>), so nothing waits on an SMTP server while you
  post an update.</p>
  <p>The CSV holds active addresses only. Deleting a row removes the address and everything sent
  to it — use it for a "forget me" request.</p>
</x-note>
@endsection
