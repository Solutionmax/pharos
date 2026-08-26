@extends('layouts.admin')
@section('title', 'Updates')
@section('content')
@include('partials.pagehead', [
  'title' => 'Updates',
  'sub' => 'What you are running, and what is available',
  'action' => ['url' => route('admin.updates', ['refresh' => 1]), 'label' => 'Check again'],
])

<div class="tiles">
  <div class="tile {{ $available ? 'warn' : 'good' }}">
    <span class="k">Installed</span>
    <span class="v">{{ $current }}</span>
    <span class="n">{{ $available ? 'An update is available' : 'Up to date' }}</span>
  </div>
  <div class="tile">
    <span class="k">Available</span>
    <span class="v">{{ $latest['version'] ?? '—' }}</span>
    <span class="n">{{ isset($latest['released_at']) ? 'Released '.$latest['released_at'] : 'No release information' }}</span>
  </div>
  <div class="tile">
    <span class="k">How this install updates</span>
    <span class="v" style="font-size:19px">{{ $managed ? 'From the host' : ($writable ? 'By itself' : 'By hand') }}</span>
    <span class="n">{{ $managed ? 'Docker image, pulled outside the app' : ($writable ? 'Downloads and replaces its own files' : 'The directory is not writable') }}</span>
  </div>
</div>

@if ($available && ($latest['notes'] ?? false))
  <div class="panel">
    <div class="panel-hd"><h3>What is in {{ $latest['version'] }}</h3></div>
    <div class="panel-bd"><p class="note">{{ $latest['notes'] }}</p></div>
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
      </div>
      <form method="POST" action="{{ route('admin.updates.apply') }}"
            onsubmit="return confirm('Install {{ $latest['version'] }}? The site is briefly unavailable while files are replaced.')">
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

<div class="panel">
  <div class="panel-hd"><h3>Why an update is safe to take</h3></div>
  <div class="panel-bd">
    <p class="note">
      Every release manifest is signed with the same key that signs licences, with a different
      purpose field so one can never be replayed as the other. An unsigned manifest, a tampered
      one, or an archive whose checksum does not match is refused before anything is written.
      A failed check reads as <b>no news</b>, never as an error on your status page.
    </p>
  </div>
</div>
@endsection
