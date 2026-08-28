@extends('layouts.admin')
@section('title', 'Settings')
@section('content')
@include('partials.pagehead', [
  'title' => 'Settings',
  'sub' => 'How this installation behaves',
])

{{-- Three unrelated things on one screen read better as three tabs. The server
     renders one at a time (?tab=), so a save or a validation error can send
     you straight back to the tab you were on. --}}
<nav class="seg tabs" aria-label="Settings">
  @foreach ($tabs as $tabKey => $hint)
    <a href="{{ route('admin.settings', ['tab' => $tabKey]) }}" @if ($tabKey === $tab) aria-current="page" @endif>
      {{ ['general' => 'General', 'mail' => 'Mail', 'sso' => 'Single sign-on'][$tabKey] }}
      @if ($hint !== '')<span class="tabhint">{{ $hint }}</span>@endif
    </a>
  @endforeach
</nav>

@if ($tab === 'general')
<div class="panel" id="general">
  <div class="panel-hd"><h3>General</h3></div>
  <div class="panel-bd">
    <form method="POST" action="{{ route('admin.settings.update') }}">
      @csrf @method('PUT')
      <input type="hidden" name="_tab" value="general">
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
@endif

@if ($tab === 'mail')
<div class="panel" id="mail">
  <div class="panel-hd">
    <h3>Mail</h3>
    <span class="hint">Used for subscriber notifications</span>
  </div>
  <div class="panel-bd">
    <form method="POST" action="{{ route('admin.settings.mail') }}" style="display:flex;flex-direction:column;gap:16px">
      @csrf @method('PUT')
      <input type="hidden" name="_tab" value="mail">
      <div class="fields">
        <div class="field">
          <label for="mailer">Mailer</label>
          <select id="mailer" name="mailer">
            @foreach (['smtp' => 'SMTP', 'sendmail' => 'Sendmail (local)', 'log' => 'Write to the log (testing)'] as $value => $label)
              <option value="{{ $value }}" @selected(old('mailer', $mailForm['mailer'] ?: $mail['mailer']) === $value)>{{ $label }}</option>
            @endforeach
          </select>
          <span class="help">"Write to the log" puts every mail in <span class="mono">storage/logs</span> instead of sending it — handy while you set things up.</span>
        </div>
        <div class="field">
          <label for="encryption">Encryption</label>
          <select id="encryption" name="encryption">
            @foreach (['none' => 'None', 'tls' => 'TLS (STARTTLS, port 587)', 'ssl' => 'SSL (port 465)'] as $value => $label)
              <option value="{{ $value }}" @selected(old('encryption', $mailForm['encryption']) === $value)>{{ $label }}</option>
            @endforeach
          </select>
          <span class="help">587 + TLS works for most providers; a few want 465 + SSL.</span>
        </div>
      </div>
      <div class="fields">
        <div class="field">
          <label for="host">SMTP host</label>
          <input id="host" name="host" type="text" value="{{ old('host', $mailForm['host']) }}" placeholder="smtp.example.net" autocomplete="off">
          @error('host')<span class="help" style="color:var(--red-ink)">{{ $message }}</span>@enderror
        </div>
        <div class="field">
          <label for="port">Port</label>
          <input id="port" name="port" type="number" min="1" max="65535" value="{{ old('port', $mailForm['port']) }}" placeholder="587">
          @error('port')<span class="help" style="color:var(--red-ink)">{{ $message }}</span>@enderror
        </div>
      </div>
      <div class="fields">
        <div class="field">
          <label for="username">Username</label>
          <input id="username" name="username" type="text" value="{{ old('username', $mailForm['username']) }}" autocomplete="off">
        </div>
        <div class="field">
          <label for="password">Password</label>
          <input id="password" name="password" type="password" autocomplete="new-password"
                 placeholder="{{ $mailHasPassword ? 'Stored — leave empty to keep' : '' }}">
          <span class="help">Stored encrypted and never shown again. Empty means unchanged.</span>
        </div>
      </div>
      <div class="fields">
        <div class="field">
          <label for="from_address">From address</label>
          <input id="from_address" name="from_address" type="email" value="{{ old('from_address', $mailForm['from_address']) }}" placeholder="status@example.net">
          @error('from_address')<span class="help" style="color:var(--red-ink)">{{ $message }}</span>@enderror
        </div>
        <div class="field">
          <label for="from_name">From name</label>
          <input id="from_name" name="from_name" type="text" value="{{ old('from_name', $mailForm['from_name']) }}" placeholder="{{ $brandName }}">
          <span class="help">Empty signs mail with the brand name, {{ $brandName }}.</span>
        </div>
      </div>
      <div class="actions">
        <button class="btn" type="submit">Save mail settings</button>
        <button class="btn ghost" type="reset">Undo my changes</button>
      </div>
    </form>

    {{-- What a mail would go out with right now: the database wins, .env fills the gaps. --}}
    <div class="field" style="margin-top:16px">
      <label>Effective</label>
      @php $where = $mail['host'] !== '' ? ' via '.$mail['host'].($mail['port'] !== '' ? ':'.$mail['port'] : '') : ''; @endphp
      <span class="mono" style="font-size:13px">{{ $mail['mailer'].$where }} as {{ $mail['from_name'] }} &lt;{{ $mail['from'] }}&gt;</span>
    </div>

    <form method="POST" action="{{ route('admin.settings.mail-test') }}" style="margin-top:12px">
      @csrf
      <div class="actions">
        <button class="btn" type="submit">Send test e-mail</button>
        <span class="help" style="align-self:center">Goes to {{ auth()->user()->email }}, with the settings as saved above.</span>
      </div>
    </form>

    <x-note id="settings.mail-env" style="margin-top:16px">
      <b>What is saved here wins;</b> anything left empty falls back to the
      <span class="mono">MAIL_*</span> lines in <span class="mono">.env</span>. Save, then press the
      button above to prove it works.
    </x-note>

    {{-- Not an x-note: a state line must not be dismissable. --}}
    <div class="field" style="margin-top:12px">
      <label>Subscriptions</label>
      <span class="help">
        @if ($subscriptionsOn)
          <b>Subscriptions are on.</b> Visitors can subscribe on the status page and get a mail per incident update.
        @else
          <b>Subscriptions are off.</b> No button on the status page and no new mail; existing addresses are kept.
        @endif
        The switch is on the <a href="{{ route('admin.subscribers') }}">Subscribers</a> screen.
      </span>
    </div>
  </div>
</div>
@endif

@if ($tab === 'sso')
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
@endif

<script>
// Old links say /admin/settings#mail and #sso. The hash never reaches the server,
// so when it names a tab that is not the one shown, ask for that tab instead.
(function () {
  var wanted = location.hash.replace('#', '');
  var here = @json($tab);
  if ((wanted === 'mail' || wanted === 'sso' || wanted === 'general') && wanted !== here) {
    location.replace(@json(route('admin.settings'), JSON_UNESCAPED_SLASHES) + '?tab=' + wanted + '#' + wanted);
  }
})();
</script>
@endsection
