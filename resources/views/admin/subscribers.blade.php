@extends('layouts.admin')
@section('title', 'Subscribers')
@section('content')
@php $canEditPage = auth()->user()->canEditPage(app(\App\Services\PageContext::class)->id()); $switchPage = app(\App\Services\PageContext::class)->page()->name; @endphp
@include('partials.pagehead', [
  'title' => 'Subscribers',
  'sub' => 'Who gets an email when an incident is reported or resolved, or maintenance is planned',
])

@include('partials.page-context', [
  'contextTitle' => 'Subscribers for',
  'contextHelp' => 'The switch and the addresses below belong only to this page. Every page has its own subscribers and its own switch.',
])

<div class="op-kpis">
  <div class="op-kpi good"><span class="k">Active</span><span class="v">{{ $summary['active'] }}</span><span class="n">confirmed, receiving mail</span></div>
  <div class="op-kpi {{ $summary['pending'] > 0 ? 'warn' : '' }}"><span class="k">Pending confirmation</span><span class="v">{{ $summary['pending'] }}</span><span class="n">forgotten after {{ \App\Models\Subscriber::PENDING_DAYS }} days</span></div>
  <div class="op-kpi"><span class="k">Unsubscribed</span><span class="v">{{ $summary['unsubscribed'] }}</span><span class="n">kept so they are not mailed again</span></div>
</div>

<section class="op-card" id="switch" aria-labelledby="switch-title">
  <header><h3 id="switch-title">Subscriptions</h3><span class="ix-state {{ $enabled ? 'ok' : 'off' }}">{{ $enabled ? 'On' : 'Off' }}</span></header>
  <div class="bd">
    <div class="switchrow">
      <span class="t">
        @if ($enabled)
          <strong><span class="ix-state ok">On for {{ $switchPage }}</span> Right now visitors can subscribe</strong>
          <span class="s">The "Get notified" button is on the status page. Every public incident update, and every maintenance announcement, is mailed.</span>
        @else
          <strong><span class="ix-state off">Off for {{ $switchPage }}</span> Right now: no button, no mail, existing addresses kept</strong>
          <span class="s">Nothing new is queued while this is off; anything queued before still goes out, and unsubscribe links keep working.</span>
        @endif
      </span>
      @if ($canEditPage)
        <form method="POST" action="{{ \App\Services\PageUrls::route('admin.subscribers.toggle') }}" style="margin-left:auto">
          @csrf
          <input type="hidden" name="enabled" value="{{ $enabled ? '0' : '1' }}">
          <button class="btn {{ $enabled ? 'ghost' : '' }}" type="submit">{{ $enabled ? 'Switch off' : 'Switch on' }}</button>
        </form>
      @endif
    </div>
  </div>
</section>

<section class="op-card" aria-labelledby="addresses-title">
  <header><h3 id="addresses-title">Addresses</h3><span class="hint">{{ $subscribers->total() }} {{ \Illuminate\Support\Str::plural('address', $subscribers->total()) }}</span></header>
  <form class="op-filters" method="GET" action="{{ \App\Services\PageUrls::route('admin.subscribers') }}" role="search">
    <label class="sr-only" for="q">Search addresses</label>
    <input id="q" name="q" type="text" value="{{ $search }}" placeholder="Part of an email address">
    <button class="btn op-sm" type="submit">Search</button>
    @if ($search !== '')<a class="btn ghost op-sm" href="{{ \App\Services\PageUrls::route('admin.subscribers') }}">Clear</a>@endif
    @if ($canEditPage && $summary['active'] > 0)
      <a class="btn ghost op-sm push" href="{{ \App\Services\PageUrls::route('admin.subscribers.export') }}" title="Every active address, as CSV">Export CSV</a>
    @endif
  </form>

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
      <table class="op-table">
        <thead><tr><th>Email</th><th>Status</th><th class="hide-sm">Subscribed</th><th class="hide-sm">Last notification</th><th><span class="sr-only">Actions</span></th></tr></thead>
        <tbody>
        @foreach ($subscribers as $s)
          <tr>
            <td class="mono" style="font-size:13px;overflow-wrap:anywhere">{{ $s->email }}</td>
            <td>
              @if ($s->isActive())<span class="ix-state ok">Active</span>
              @elseif ($s->isPending())<span class="ix-state w">Pending</span>
              @else<span class="ix-state off">Unsubscribed</span>@endif
            </td>
            <td class="num hide-sm">{{ ($s->verified_at ?? $s->created_at)->format('j M Y') }}</td>
            <td class="num hide-sm">
              @if ($s->notifications_max_sent_at)
                {{ \Carbon\CarbonImmutable::parse($s->notifications_max_sent_at, 'UTC')->setTimezone(\App\Services\Clock::timezone())->format('d M H:i') }}
              @else
                <span class="op-dim">none yet</span>
              @endif
            </td>
            <td class="right">
              @if ($canEditPage)<span class="rowacts">
                @if ($s->isPending())
                  <form method="POST" action="{{ \App\Services\PageUrls::route('admin.subscribers.resend', $s) }}">
                    @csrf
                    <button type="submit">Resend confirmation</button>
                  </form>
                @endif
                <form method="POST" action="{{ \App\Services\PageUrls::route('admin.subscribers.destroy', $s) }}"
                      data-confirm-title="Remove {{ $s->email }}?"
                      data-confirm="The address and its notification history are <strong>deleted</strong>. This is how you honour a request to be forgotten."
                      data-confirm-action="Remove address">
                  @csrf @method('DELETE')
                  <button type="submit">Delete</button>
                </form>
              </span>@endif
            </td>
          </tr>
        @endforeach
        </tbody>
      </table>
    </div>
    @if ($subscribers->hasPages())<div class="bd">{{ $subscribers->links('vendor.pagination.pharos', ['previousLabel' => 'Newer', 'nextLabel' => 'Older']) }}</div>@endif
  @endif
</section>

@unless (app(\App\Services\MailConfig::class)->configured())
<x-note id="subscribers.no-mail" warn style="margin-top:18px">
  <b>No mail transport yet.</b> The "Get notified" form stays off the public page, and nothing is
  sent, until <a href="{{ route('admin.settings', ['tab' => 'mail']) }}">Settings → Mail</a> has an SMTP
  host: a form that ends in an error helps nobody.
</x-note>
@endunless
<x-note id="subscribers.how" style="margin-top:18px">
  <p><b>How it works.</b> A visitor enters an address, confirms it from the mail they get, and from
  then on receives every update on a public incident and every maintenance announcement. Sending runs
  from the same cron line as the checks (<span class="mono">pharos:notify</span>), so nothing waits on
  an SMTP server while you post an update.</p>
  <p>The CSV holds active addresses only. Deleting a row removes the address and everything sent
  to it: use it for a "forget me" request.</p>
</x-note>
@endsection
