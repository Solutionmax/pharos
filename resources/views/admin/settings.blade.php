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

{{-- id="sso": the old /admin/sso address lands here. --}}
<div class="panel" id="sso">
  <div class="panel-hd">
    <h3>Single sign-on</h3>
    <span class="hint">{{ $sso->enabled() ? 'On · '.$sso->providerName() : 'Off' }} · OpenID Connect</span>
  </div>
  <div class="panel-bd">
    @include('admin.partials.sso-form')

    <div class="callout" style="margin-top:16px">
      <b>What still applies.</b> Anyone who switched two-factor on keeps it, whatever door they
      came through. If your provider already enforces MFA and you would rather not be asked twice,
      switch your own two-factor off on your profile — that stays your decision, not the login
      screen's.
    </div>
  </div>
</div>
@endsection
