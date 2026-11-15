@extends('layouts.admin')
@section('title', __('Scheduler and backups'))
@section('content')
@include('partials.pagehead', ['crumbs' => ['Scheduler and backups'], 'crumbScope' => 'installation', 'title' => __('Scheduler and backups')])
<div class="op-cols">
  <div class="op-side">
    <section class="op-card">
      <header><h3>{{ __('Remote backups') }}</h3><span class="hint">{{ $destinations->count() }}</span></header>
      <div class="bd beta-form">
        <p class="op-dim">{{ __('Daily backups include a consistent database snapshot, application files and uploaded files, and with a backup passphrase also the environment file (.env). Destinations receive private archives and each upload is downloaded to verify its checksum. Keep remote storage private.') }}</p>
        <section class="backup-passphrase" aria-labelledby="backup-passphrase-title">
          <div class="op-acts"><h3 id="backup-passphrase-title">{{ __('Backup passphrase') }}</h3><span class="op-pill {{ $passphraseSet ? 'st-ok' : 'st-off' }}">{{ $passphraseSet ? __('Archives encrypted') : __('Not encrypted') }}</span></div>
          @if ($passphraseSet)
            <p class="op-dim">{{ __('Remote archives are encrypted with this passphrase (Argon2id, XChaCha20 Poly1305) and include .env with APP_KEY. Only the passphrase opens them: keep a copy somewhere other than this server.') }}</p>
          @else
            <p class="errors" role="note">{{ __('Without a passphrase remote archives are not encrypted and leave .env out. Keep APP_KEY somewhere else: without it, the encrypted fields in a restored database (two factor secrets, mail and backup credentials) cannot be read.') }}</p>
          @endif
          <form method="post" class="beta-form" action="{{ route('admin.backup-passphrase.update') }}">
            @csrf @method('PUT')
            <div class="fields">
              <div class="field"><label for="backup-passphrase">{{ $passphraseSet ? __('New passphrase') : __('Passphrase') }}</label><input id="backup-passphrase" name="passphrase" type="password" autocomplete="new-password" minlength="{{ \App\Services\BackupPassphrase::MIN_LENGTH }}" maxlength="1024" required></div>
              <div class="field"><label for="backup-passphrase-confirmation">{{ __('Repeat passphrase') }}</label><input id="backup-passphrase-confirmation" name="passphrase_confirmation" type="password" autocomplete="new-password" required></div>
            </div>
            @error('passphrase')<p class="errors">{{ $message }}</p>@enderror
            <p class="op-dim">{{ __('Stored on this server, encrypted with APP_KEY, because scheduled uploads need it. Open an archive with php artisan pharos:backup:decrypt followed by the file name.') }}</p>
            <div class="op-acts"><button class="btn op-sm">{{ __('Save passphrase') }}</button></div>
          </form>
          @if ($passphraseSet)
            <form method="post" action="{{ route('admin.backup-passphrase.destroy') }}"
                  data-confirm-title="{{ __('Remove the backup passphrase?') }}"
                  data-confirm="{{ __('Remote archives are then no longer encrypted and leave .env out. Archives made before keep needing the passphrase.') }}"
                  data-confirm-action="{{ __('Remove passphrase') }}">
              @csrf @method('DELETE')<button class="btn danger op-sm">{{ __('Remove passphrase') }}</button>
            </form>
          @endif
        </section>
        @forelse ($destinations as $destination)
          <article class="backup-destination">
            <div class="op-acts"><h3>{{ $destination->name }}</h3><span class="op-pill">{{ strtoupper($destination->driver) }}</span></div>
            <p class="op-dim">{{ __('Last success') }}: {{ $destination->last_success_at?->diffForHumans() ?? __('Never') }}</p>
            @if ($destination->last_error)<p class="errors">{{ $destination->last_error }}</p>@endif
            <div class="op-acts">
              <form method="post" action="{{ route('admin.backup-destinations.run', $destination) }}">@csrf<button class="btn op-sm">{{ __('Back up now') }}</button></form>
              <form method="post" action="{{ route('admin.backup-destinations.destroy', $destination) }}">@csrf @method('DELETE')<button class="btn danger op-sm">{{ __('Remove destination') }}</button></form>
            </div>
          </article>
        @empty
          <div class="op-empty"><b>{{ __('No backup destinations yet') }}</b><span>{{ __('Choose S3 or SFTP below to store a verified copy off this server.') }}</span></div>
        @endforelse
        @foreach (['s3' => __('S3 destination'), 'sftp' => __('SFTP destination')] as $driver => $label)
          <details class="beta-disclosure" @if(old('driver') === $driver) open @endif>
            <summary><span class="op-pill">{{ strtoupper($driver) }}</span>{{ $label }}<span class="beta-chevron" aria-hidden="true">⌄</span></summary>
            <form method="post" class="beta-form" action="{{ route('admin.backup-destinations.store') }}">
              @csrf<input type="hidden" name="driver" value="{{ $driver }}">
              <div class="field"><label for="{{ $driver }}-name">{{ __('Name') }}</label><input id="{{ $driver }}-name" name="name" type="text" maxlength="100" required></div>
              <div class="fields">
                @if ($driver === 's3')
                  <div class="field wide"><label for="s3-endpoint">{{ __('HTTPS endpoint (optional for AWS)') }}</label><input id="s3-endpoint" name="endpoint" type="url"></div>
                  <div class="field"><label for="s3-region">{{ __('Region') }}</label><input id="s3-region" name="region" type="text" value="us-east-1" required></div>
                  <div class="field"><label for="s3-bucket">{{ __('Bucket') }}</label><input id="s3-bucket" name="bucket" type="text" required></div>
                  <div class="field wide"><label for="s3-prefix">{{ __('Folder prefix') }}</label><input id="s3-prefix" name="prefix" type="text"></div>
                  <div class="field"><label for="s3-key">{{ __('Access key') }}</label><input id="s3-key" name="key" type="text" autocomplete="off" required></div>
                  <div class="field"><label for="s3-secret">{{ __('Secret key') }}</label><input id="s3-secret" name="secret" type="password" autocomplete="new-password" required></div>
                @else
                  <div class="field"><label for="sftp-host">{{ __('Host') }}</label><input id="sftp-host" name="host" type="text" required></div>
                  <div class="field"><label for="sftp-port">{{ __('Port') }}</label><input id="sftp-port" name="port" type="number" value="22" min="1" max="65535"></div>
                  <div class="field"><label for="sftp-username">{{ __('Username') }}</label><input id="sftp-username" name="username" type="text" required></div>
                  <div class="field"><label for="sftp-password">{{ __('Password') }}</label><input id="sftp-password" name="password" type="password" autocomplete="new-password" required></div>
                  <div class="field wide"><label for="sftp-fingerprint">{{ __('Verified server host fingerprint') }}</label><input id="sftp-fingerprint" name="fingerprint" type="text" required></div>
                  <div class="field wide"><label for="sftp-root">{{ __('Remote directory') }}</label><input id="sftp-root" name="root" type="text" value="/backups" required></div>
                @endif
              </div>
              <label class="check"><input name="allow_private" type="checkbox" value="1"><span>{{ __('Allow this destination on a private network') }}</span></label>
              <div class="op-acts"><button class="btn">{{ __('Add destination') }}</button></div>
            </form>
          </details>
        @endforeach
      </div>
    </section>
  </div>
  <section class="op-card">
    <header><h3>{{ __('Web scheduler fallback') }}</h3></header>
    <div class="bd beta-form">
      <p class="op-dim">{{ __('Enable only when your hosting cannot run the normal scheduler. Requests need the private PHAROS_WEB_CRON_TOKEN environment credential in an Authorization: Bearer header.') }}</p>
      <div class="scheduler-addresses"><code>POST {{ url('/api/cron') }}</code><code>GET {{ url('/cron/run') }}</code></div>
      <p class="op-dim">{{ __('External scheduler services can call the GET address with an Authorization: Bearer header. Credentials in query strings are rejected to keep them out of access logs.') }}</p>
      <div><span class="op-pill {{ $cronConfigured ? 'st-ok' : 'st-off' }}">{{ $cronConfigured ? __('Credential configured') : __('Credential not configured') }}</span></div>
      <form method="post" class="beta-form" action="{{ route('admin.system-monitoring.cron') }}">
        @csrf @method('PUT')<input type="hidden" name="enabled" value="0">
        <label class="check"><input type="checkbox" name="enabled" value="1" @checked($webEnabled)><span>{{ __('Enable web scheduler') }}</span></label>
        <div class="op-acts"><button class="btn">{{ __('Save') }}</button></div>
      </form>
    </div>
  </section>
</div>
@endsection
