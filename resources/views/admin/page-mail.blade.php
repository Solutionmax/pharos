@extends('layouts.admin')
@section('title', 'Page e-mail')
@section('content')
@include('partials.pagehead', [
  'title' => 'Page e-mail',
  'sub' => 'Sender and transport for this status page',
])

<div class="panel">
  <div class="panel-hd"><h3>Delivery</h3><span class="hint">This page only</span></div>
  <div class="panel-bd">
    <form method="POST" action="{{ \App\Services\PageUrls::route('admin.mail.update') }}" style="display:flex;flex-direction:column;gap:16px">
      @csrf @method('PUT')
      <div class="field">
        <label for="mode">Mail transport</label>
        <select id="mode" name="mode">
          <option value="central" @selected(old('mode', $mailForm['mode'] ?: 'central') === 'central')>Use central mail transport</option>
          <option value="custom" @selected(old('mode', $mailForm['mode']) === 'custom')>Use custom SMTP</option>
        </select>
        <span class="help">Central uses the installation mail server. Custom SMTP is isolated to this page.</span>
      </div>

      <div class="fields">
        <div class="field">
          <label for="host">SMTP host</label>
          <input id="host" name="host" type="text" value="{{ old('host', $mailForm['host']) }}" autocomplete="off">
          @error('host')<span class="help" style="color:var(--red-ink)">{{ $message }}</span>@enderror
        </div>
        <div class="field">
          <label for="port">Port</label>
          <input id="port" name="port" type="number" min="1" max="65535" value="{{ old('port', $mailForm['port']) }}">
          @error('port')<span class="help" style="color:var(--red-ink)">{{ $message }}</span>@enderror
        </div>
      </div>

      <div class="fields">
        <div class="field">
          <label for="encryption">Encryption</label>
          <select id="encryption" name="encryption">
            @foreach (['none' => 'None', 'tls' => 'TLS (STARTTLS)', 'ssl' => 'SSL'] as $value => $label)
              <option value="{{ $value }}" @selected(old('encryption', $mailForm['encryption'] ?: 'tls') === $value)>{{ $label }}</option>
            @endforeach
          </select>
        </div>
        <div class="field">
          <label for="username">Username</label>
          <input id="username" name="username" type="text" value="{{ old('username', $mailForm['username']) }}" autocomplete="off">
        </div>
        <div class="field">
          <label for="password">Password</label>
          <input id="password" name="password" type="password" autocomplete="new-password"
                 placeholder="{{ $mailHasPassword ? 'Stored — leave empty to keep' : '' }}">
          <span class="help">Stored encrypted and never shown again.</span>
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
        </div>
        <div class="field">
          <label for="reply_to">Reply-to address</label>
          <input id="reply_to" name="reply_to" type="email" value="{{ old('reply_to', $mailForm['reply_to']) }}">
          @error('reply_to')<span class="help" style="color:var(--red-ink)">{{ $message }}</span>@enderror
        </div>
      </div>

      <div class="actions">
        <button class="btn" type="submit">Save page e-mail</button>
        <button class="btn ghost" type="reset">Undo my changes</button>
      </div>
    </form>

    <div class="field">
      <label>Effective</label>
      @php $where = $effective['host'] !== '' ? ' via '.$effective['host'].($effective['port'] !== '' ? ':'.$effective['port'] : '') : ''; @endphp
      <span class="mono" style="font-size:13px">{{ $effective['mailer'].$where }} as {{ $effective['from_name'] }} &lt;{{ $effective['from'] }}&gt;</span>
    </div>

    <form method="POST" action="{{ \App\Services\PageUrls::route('admin.mail.test') }}">
      @csrf
      <div class="actions">
        <button class="btn ghost" type="submit">Send test e-mail</button>
        <span class="help" style="align-self:center">Goes to {{ auth()->user()->email }} with this page's sender, branding and transport.</span>
      </div>
      @error('mail')<span class="help" style="color:var(--red-ink);display:block;margin-top:8px">{{ $message }}</span>@enderror
    </form>
  </div>
</div>
@endsection
