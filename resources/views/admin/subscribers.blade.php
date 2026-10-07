@extends('layouts.admin')
@section('title', __('Subscribers'))
@section('content')
@php $canEditPage = auth()->user()->canEditPage(app(\App\Services\PageContext::class)->id()); $switchPage = app(\App\Services\PageContext::class)->page()->name; @endphp
@include('partials.pagehead', [
  'title' => __('Subscribers'),
  'sub' => __('Who gets an email when an incident is reported or resolved, or maintenance is planned'),
])

@include('partials.page-context', [
  'contextTitle' => __('Subscribers for'),
  'contextHelp' => __('The switch and the addresses below belong only to this page. Every page has its own subscribers and its own switch.'),
])

<div class="op-kpis">
  <div class="op-kpi good"><span class="k">{{ __('Active') }}</span><span class="v">{{ $summary['active'] }}</span><span class="n">{{ __('confirmed, receiving mail') }}</span></div>
  <div class="op-kpi {{ $summary['pending'] > 0 ? 'warn' : '' }}"><span class="k">{{ __('Pending confirmation') }}</span><span class="v">{{ $summary['pending'] }}</span><span class="n">{{ __('forgotten after') }} {{ \App\Models\Subscriber::PENDING_DAYS }} {{ __('days') }}</span></div>
  <div class="op-kpi"><span class="k">{{ __('Unsubscribed') }}</span><span class="v">{{ $summary['unsubscribed'] }}</span><span class="n">{{ __('kept so they are not mailed again') }}</span></div>
</div>

<section class="op-card" id="switch" aria-labelledby="switch-title">
  <header><h3 id="switch-title">{{ __('Subscriptions') }}</h3><span class="ix-state {{ $enabled ? 'ok' : 'off' }}">{{ $enabled ? __('On') : __('Off') }}</span></header>
  <div class="bd">
    <div class="switchrow">
      <span class="t">
        @if ($enabled)
          <strong><span class="ix-state ok">{{ __('On for') }} {{ $switchPage }}</span> {{ __('Right now visitors can subscribe') }}</strong>
          <span class="s">{{ __('The "Get notified" button is on the status page. Every public incident update, and every maintenance announcement, is mailed.') }}</span>
        @else
          <strong><span class="ix-state off">{{ __('Off for') }} {{ $switchPage }}</span> {{ __('Right now: no button, no mail, existing addresses kept') }}</strong>
          <span class="s">{{ __('Nothing new is queued while this is off; anything queued before still goes out, and unsubscribe links keep working.') }}</span>
        @endif
      </span>
      @if ($canEditPage)
        <form method="POST" action="{{ \App\Services\PageUrls::route('admin.subscribers.toggle') }}" style="margin-left:auto">
          @csrf
          <input type="hidden" name="enabled" value="{{ $enabled ? '0' : '1' }}">
          <button class="btn {{ $enabled ? 'ghost' : '' }}" type="submit">{{ $enabled ? __('Switch off') : __('Switch on') }}</button>
        </form>
      @endif
    </div>
  </div>
</section>

<section class="op-card" aria-labelledby="addresses-title">
  <header><h3 id="addresses-title">{{ __('Addresses') }}</h3><span class="hint">{{ $subscribers->total() }} {{ \App\Services\Localization::plural('address', $subscribers->total()) }}</span></header>
  <form class="op-filters" method="GET" action="{{ \App\Services\PageUrls::route('admin.subscribers') }}" role="search">
    <label class="sr-only" for="q">{{ __('Search addresses') }}</label>
    <input id="q" name="q" type="text" value="{{ $search }}" placeholder="{{ __('Part of an email address') }}">
    <button class="btn op-sm" type="submit">{{ __('Search') }}</button>
    @if ($search !== '')<a class="btn ghost op-sm" href="{{ \App\Services\PageUrls::route('admin.subscribers') }}">{{ __('Clear') }}</a>@endif
    @if ($canEditPage && $summary['active'] > 0)
      <a class="btn ghost op-sm push" href="{{ \App\Services\PageUrls::route('admin.subscribers.export') }}" title="{{ __('Every active address, as CSV') }}">{{ __('Export CSV') }}</a>
    @endif
  </form>

  @if ($subscribers->isEmpty())
    <div class="empty">
      @include('partials.icon', ['name' => 'mail', 'size' => 28])
      <b>{{ $search !== '' ? __('No address matches') : __('Nobody has subscribed yet') }}</b>
      @if ($search === '')
        {{ __('The "Get notified" button on the status page is where visitors sign up.') }}
      @endif
    </div>
  @else
    <div class="scroll">
      <table class="op-table">
        <thead><tr><th>{{ __('Email') }}</th><th>{{ __('Status') }}</th><th class="hide-sm">{{ __('Subscribed') }}</th><th class="hide-sm">{{ __('Last notification') }}</th><th><span class="sr-only">{{ __('Actions') }}</span></th></tr></thead>
        <tbody>
        @foreach ($subscribers as $s)
          <tr>
            <td class="mono" style="font-size:13px;overflow-wrap:anywhere">{{ $s->email }}</td>
            <td>
              @if ($s->isActive())<span class="ix-state ok">{{ __('Active') }}</span>
              @elseif ($s->isPending())<span class="ix-state w">{{ __('Pending') }}</span>
              @else<span class="ix-state off">{{ __('Unsubscribed') }}</span>@endif
            </td>
            <td class="num hide-sm">{{ ($s->verified_at ?? $s->created_at)->translatedFormat('j M Y') }}</td>
            <td class="num hide-sm">
              @if ($s->notifications_max_sent_at)
                {{ \Carbon\CarbonImmutable::parse($s->notifications_max_sent_at, 'UTC')->setTimezone(\App\Services\Clock::timezone())->translatedFormat('d M H:i') }}
              @else
                <span class="op-dim">{{ __('none yet') }}</span>
              @endif
            </td>
            <td class="right">
              @if ($canEditPage)<span class="rowacts">
                @if ($s->isPending())
                  <form method="POST" action="{{ \App\Services\PageUrls::route('admin.subscribers.resend', $s) }}">
                    @csrf
                    <button type="submit">{{ __('Resend confirmation') }}</button>
                  </form>
                @endif
                <form method="POST" action="{{ \App\Services\PageUrls::route('admin.subscribers.destroy', $s) }}"
                      data-confirm-title="Remove {{ $s->email }}?"
                      data-confirm="{{ __('The address and its notification history are <strong>deleted</strong>. This is how you honour a request to be forgotten.') }}"
                      data-confirm-action="{{ __('Remove address') }}">
                  @csrf @method('DELETE')
                  <button type="submit">{{ __('Delete') }}</button>
                </form>
              </span>@endif
            </td>
          </tr>
        @endforeach
        </tbody>
      </table>
    </div>
    @if ($subscribers->hasPages())<div class="bd">{{ $subscribers->links('vendor.pagination.pharos', ['previousLabel' => __('Newer'), 'nextLabel' => __('Older')]) }}</div>@endif
  @endif
</section>

@unless (app(\App\Services\MailConfig::class)->configured())
<x-note id="subscribers.no-mail" warn style="margin-top:18px">
  <b>{{ __('No mail transport yet.') }}</b> {{ __('The "Get notified" form stays off the public page, and nothing is
  sent, until') }} <a href="{{ route('admin.settings', ['tab' => 'mail']) }}">{{ __('Settings → Mail') }}</a> {{ __('has an SMTP
  host: a form that ends in an error helps nobody.') }}
</x-note>
@endunless
<x-note id="subscribers.how" style="margin-top:18px">
  <p><b>{{ __('How it works.') }}</b> {{ __('A visitor enters an address, confirms it from the mail they get, and from
  then on receives every update on a public incident and every maintenance announcement. Sending runs
  from the same cron line as the checks (') }}<span class="mono">{{ __('pharos:notify') }}</span>{{ __('), so nothing waits on
  an SMTP server while you post an update.') }}</p>
  <p>{{ __('The CSV holds active addresses only. Deleting a row removes the address and everything sent
  to it: use it for a "forget me" request.') }}</p>
</x-note>
@endsection
