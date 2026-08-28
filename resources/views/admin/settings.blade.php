@extends('layouts.admin')
@section('title', 'Settings')
@section('content')
@include('partials.pagehead', [
  'title' => 'Settings',
  'sub' => 'How this installation behaves',
])

<div class="panel">
  <div class="panel-hd"><h3>General</h3></div>
  <div class="panel-bd">
    <form method="POST" action="{{ route('admin.settings.update') }}">
      @csrf @method('PUT')
      <div class="fields">
        <div class="field">
          <label for="timezone">Time zone</label>
          @include('partials.timezone-select', ['selected' => $timezone])
          <span class="help"><b>{{ $timezone }} — {{ $offset }} now.</b>
            Times on the status page, in e-mails and in the admin are shown in this zone.
            Everything is stored in UTC, so you can change it any time.</span>
        </div>
      </div>
      <div class="actions">
        <button class="btn" type="submit">Save settings</button>
        <button class="btn ghost" type="reset">Undo my changes</button>
      </div>
    </form>
  </div>
</div>

<div class="panel" id="mail">
  <div class="panel-hd">
    <h3>Mail</h3>
    <span class="hint">Used for subscriber notifications</span>
  </div>
  <div class="panel-bd">
    <div class="fields">
      <div class="field">
        <label>Mailer</label>
        <span class="mono" style="font-size:13px">{{ $mail['mailer'] }}@if ($mail['host'] !== '') · {{ $mail['host'] }}@if ($mail['port'] !== ''):{{ $mail['port'] }}@endif @endif</span>
      </div>
      <div class="field">
        <label>From</label>
        <span class="mono" style="font-size:13px">{{ $mail['from_name'] }} &lt;{{ $mail['from'] }}&gt;</span>
      </div>
    </div>
    <form method="POST" action="{{ route('admin.settings.mail-test') }}">
      @csrf
      <div class="actions">
        <button class="btn" type="submit">Send test e-mail</button>
        <span class="help" style="align-self:center">Goes to {{ auth()->user()->email }}.</span>
      </div>
    </form>

    <x-note id="settings.mail-env">
      <b>Mail settings live in <span class="mono">.env</span>,</b> not on this screen: the
      <span class="mono">MAIL_*</span> lines. A blank <span class="mono">MAIL_FROM_NAME</span>
      means mail is signed with the brand name. Change the file, then press the button above to
      prove it works.
    </x-note>
  </div>
</div>

{{-- id="sso": the old /admin/sso address lands here. --}}
<div class="panel" id="sso">
  <div class="panel-hd">
    <h3>Single sign-on</h3>
    <span class="hint">{{ $sso->enabled() ? 'On · '.$sso->providerName() : 'Off' }} · OpenID Connect</span>
  </div>
  <div class="panel-bd">
    @include('admin.partials.sso-form')

    <x-note id="sso.what-still-applies" style="margin-top:16px">
      <b>What still applies.</b> Anyone who switched two-factor on keeps it, whatever door they
      came through. If your provider already enforces MFA and you would rather not be asked twice,
      switch your own two-factor off on your profile — that stays your decision, not the login
      screen's.
    </x-note>
  </div>
</div>
@endsection
