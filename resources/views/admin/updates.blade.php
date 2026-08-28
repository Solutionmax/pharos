@extends('layouts.admin')
@section('title', 'Updates')
@section('content')

@if ($versionPinned)
  <div class="callout warn">
    <b>The version is pinned in .env.</b> <span class="mono">PHAROS_VERSION</span> is set there, and
    an update never replaces <span class="mono">.env</span> — so after installing a release this
    screen would keep reporting the old version and offer the same update again. Remove that line;
    the version then comes from the code, which is what an update actually replaces.
  </div>
@endif

@include('partials.pagehead', [
  'title' => 'Updates',
  'sub' => 'What you are running, and what is available',
  'action' => ['url' => route('admin.updates', ['refresh' => 1]), 'label' => 'Check again'],
])

@php
  $state = $check['state'];
  // "Up to date" is a claim; it is only made when the server was actually asked.
  $confirmedCurrent = $state === 'ok' && ! $available;
  $stateText = match ($state) {
    'no_release' => 'No release published yet',
    'unreachable' => 'Release server not reachable',
    'invalid' => 'Manifest refused — not signed by our key',
    'disabled' => 'Checking is switched off',
    default => 'No release information',
  };
@endphp

<div class="tiles">
  <div class="tile {{ $available ? 'warn' : ($confirmedCurrent ? 'good' : '') }}">
    <span class="k">Installed</span>
    <span class="v">{{ $current }}</span>
    <span class="n">{{ $available ? 'An update is available' : ($confirmedCurrent ? 'Up to date' : 'No newer release known') }}</span>
  </div>
  <div class="tile">
    <span class="k">Available</span>
    <span class="v">{{ $state === 'ok' ? $latest['version'] : '—' }}</span>
    <span class="n">
      @if ($state === 'ok')
        {{ isset($latest['released_at']) ? 'Released '.$latest['released_at'] : 'Release date unknown' }}
      @else
        {{ $stateText }}@if ($check['error'])<br><span class="mono" style="font-size:11px">{{ $check['error'] }}</span>@endif
      @endif
    </span>
  </div>
  <div class="tile">
    <span class="k">How this install updates</span>
    <span class="v" style="font-size:19px">{{ $managed ? 'From the host' : ($writable ? 'By itself' : 'By hand') }}</span>
    <span class="n">{{ $managed ? 'Docker image, pulled outside the app' : ($writable ? 'Downloads and replaces its own files' : 'The directory is not writable') }}@if ($state !== 'disabled' && $manifestHost) · Checks {{ $manifestHost }} every hour @endif</span>
  </div>
</div>

<p class="note" style="margin:-4px 0 16px">
  @if ($checkedAt)
    Last checked {{ $checkedAt->gt(now()->subMinute()) ? 'just now' : $checkedAt->diffForHumans() }} · next automatic check in {{ $nextCheckAt->diffForHumans(['parts' => 1, 'syntax' => \Carbon\Carbon::DIFF_ABSOLUTE]) }}
  @else
    Never checked yet
  @endif
</p>

@if ($available && ($latest['notes'] ?? false))
  <div class="panel">
    <div class="panel-hd"><h3>What is in {{ $latest['version'] }}</h3></div>
    {{-- Same rules as an incident update: Markdown in, HTML escaped, no javascript: links. --}}
    <div class="panel-bd"><div class="md note">{!! Str::markdown($latest['notes'], ['html_input' => 'escape', 'allow_unsafe_links' => false]) !!}</div></div>
  </div>
@endif

<div class="panel">
  <div class="panel-hd"><h3>Install</h3></div>
  <div class="panel-bd">
    @if (! $available)
      <p class="note">Nothing to do. Pharos checks once an hour, and you can force it with <b>Check again</b>.</p>
    @elseif ($managed)
      <div class="callout">
        <b>This install is managed from outside.</b> The Docker image belongs to the host, so the
        app cannot replace itself. Pressing the button asks the host updater to pull and restart.
      </div>
      @if ($managedStatus)
        <p class="note mono" style="font-size:12.5px">Host reported: {{ json_encode($managedStatus) }}</p>
      @endif
      <form method="POST" action="{{ route('admin.updates.apply') }}">
        @csrf
        <button class="btn" type="submit">Pull {{ $latest['version'] }} and restart</button>
      </form>
      <p class="note">Or, on the host: <span class="mono">docker compose pull &amp;&amp; docker compose up -d</span></p>
    @elseif ($writable)
      <div class="callout">
        The archive is downloaded, checked against a signature made with our key, and only then
        unpacked. Your <b>.env</b>, database and uploads are left alone, and the version you are
        running now is copied to <span class="mono">storage/app/backups</span> first.
        @if ($sqlite) Your SQLite database is copied into the backup as well. @else Your database is <b>not</b> in that backup — take a dump before installing, because the update runs migrations. @endif
      </div>
      <form method="POST" action="{{ route('admin.updates.apply') }}"
            data-confirm-title="Install {{ $latest['version'] }}?"
            data-confirm="Pharos replaces its own files and is <strong>briefly unavailable</strong> while it does. Your settings, uploads and database are kept, and the current version is backed up first."
            data-confirm-action="Install update"
            data-confirm-safe="1">
        @csrf
        <button class="btn" type="submit">Install {{ $latest['version'] }}</button>
      </form>
    @else
      <div class="callout">
        <b>The application directory is not writable</b>, so Pharos cannot update itself. Either give
        the web user write access, or update over SSH:
      </div>
<pre>cd {{ base_path() }}
php artisan pharos:update</pre>
    @endif
  </div>
</div>

<div class="callout" style="margin-bottom:16px">
  Every release manifest is signed with the key that also signs licences, with a different purpose
  field so one can never be replayed as the other. An unsigned or tampered manifest, or an archive
  whose checksum does not match, is refused before anything is written. A failed check reads as
  <b>no news</b>, never as an error on your status page.
</div>

<div class="panel">
  <div class="panel-hd"><h3>Backups kept</h3><span class="hint mono">storage/app/backups</span></div>
  @if ($backups)
    <div class="scroll">
      <table>
        <thead><tr><th>Version</th><th>Taken</th><th>Size</th></tr></thead>
        <tbody>
          @foreach ($backups as $backup)
            <tr>
              <td><b>{{ $backup['version'] }}</b> <span class="sub mono">{{ $backup['name'] }}</span></td>
              <td>{{ $backup['created_at']->format('j M Y H:i') }} <span class="sub">{{ $backup['created_at']->diffForHumans() }}</span></td>
              <td class="num">{{ Number::fileSize($backup['size'], precision: 1) }}</td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
  @endif
  <div class="panel-bd">
    @if (! $backups)
      <p class="note">No backups yet — the first update creates one.</p>
    @endif
    <p class="note">
      @if ($sqlite)The SQLite database is copied into the backup, so putting a folder back puts the data of that moment back too. @else Your database is not in these backups — dump it before an update; the update runs migrations. @endif
      Each update copies the version it replaces into <span class="mono">storage/app/backups</span>
      before writing anything. Nothing prunes that folder: once the new version has run for a while,
      empty it by hand. Rollback is copying a folder back; there is no button for it yet.
    </p>
  </div>
</div>
@endsection
