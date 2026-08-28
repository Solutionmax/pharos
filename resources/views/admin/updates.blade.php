@extends('layouts.admin')
@section('title', 'Updates')
@section('content')

@if ($versionPinned)
  <x-note id="updates.pinned" warn>
    <b>The version is pinned in .env.</b> <span class="mono">PHAROS_VERSION</span> is set there, and
    an update never replaces <span class="mono">.env</span> — so after installing a release this
    screen would keep reporting the old version and offer the same update again. Remove that line;
    the version then comes from the code, which is what an update actually replaces.
  </x-note>
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
      <x-note id="updates.managed">
        <b>This install is managed from outside.</b> The Docker image belongs to the host, so the
        app cannot replace itself. Pressing the button asks the host updater to pull and restart.
      </x-note>
      @if ($managedStatus)
        <p class="note mono" style="font-size:12.5px">Host reported: {{ json_encode($managedStatus) }}</p>
      @endif
      <form method="POST" action="{{ route('admin.updates.apply') }}">
        @csrf
        <button class="btn" type="submit">Pull {{ $latest['version'] }} and restart</button>
      </form>
      <p class="note">Or, on the host: <span class="mono">docker compose pull &amp;&amp; docker compose up -d</span></p>
    @elseif ($writable)
      <x-note id="updates.how-it-installs">
        The archive is downloaded, checked against a signature made with our key, and only then
        unpacked. Your <b>.env</b>, database and uploads are left alone, and the version you are
        running now is copied to <span class="mono">storage/app/backups</span> first.
        @if ($sqlite) Your SQLite database is copied into the backup as well. @else Your database is <b>not</b> in that backup — take a dump before installing, because the update runs migrations. @endif
      </x-note>
      <form method="POST" action="{{ route('admin.updates.apply') }}"
            data-confirm-title="Install {{ $latest['version'] }}?"
            data-confirm="Pharos replaces its own files and is <strong>briefly unavailable</strong> while it does. Your settings, uploads and database are kept, and the current version is backed up first."
            data-confirm-action="Install update"
            data-confirm-safe="1">
        @csrf
        <button class="btn" type="submit">Install {{ $latest['version'] }}</button>
      </form>
    @else
      <x-note id="updates.not-writable">
        <b>The application directory is not writable</b>, so Pharos cannot update itself. Either give
        the web user write access, or update over SSH:
      </x-note>
<pre>cd {{ base_path() }}
php artisan pharos:update</pre>
    @endif
  </div>
</div>

<x-note id="updates.safe" style="margin-bottom:16px">
  Every release manifest is signed with the key that also signs licences, with a different purpose
  field so one can never be replayed as the other. An unsigned or tampered manifest, or an archive
  whose checksum does not match, is refused before anything is written. A failed check reads as
  <b>no news</b>, never as an error on your status page.
</x-note>

<div class="panel">
  <div class="panel-hd">
    <h3>Backups kept</h3><span class="hint mono">storage/app/backups</span>
    <form method="POST" action="{{ route('admin.updates.backup') }}" id="backup-form" data-job="backup" data-progress="{{ route('admin.updates.backup.progress') }}">
      @csrf
      <button class="btn" type="submit">Back up now</button>
    </form>
  </div>
  {{-- Filled by the script below while a backup or rollback runs; without JS the forms post as before. --}}
  <div class="backup-progress" id="backup-progress" hidden>
    <span class="mono" data-label></span><span class="mono" data-pct></span>
    <progress class="bar"></progress>
  </div>
  @if ($backups)
    <div class="scroll">
      <table>
        <thead><tr><th>Version</th><th>Taken</th><th>Size</th><th></th></tr></thead>
        <tbody>
          @foreach ($backups as $backup)
            <tr>
              <td><b>{{ $backup['version'] }}</b> <span class="sub mono">{{ $backup['name'] }}</span></td>
              <td>{{ $backup['created_at']->format('j M Y H:i') }} <span class="sub">{{ $backup['created_at']->diffForHumans() }}</span></td>
              <td class="num">{{ Number::fileSize($backup['size'], precision: 1) }}</td>
              <td>
                <span class="rowacts">
                  <a href="{{ route('admin.updates.backup.download', $backup['name']) }}">Download</a>
                  <form method="POST" action="{{ route('admin.updates.backup.rollback', $backup['name']) }}"
                        data-job="rollback" data-progress="{{ route('admin.updates.backup.progress') }}" data-after="{{ route('admin.login', ['after' => 'rollback']) }}"
                        data-confirm-title="Roll back to {{ $backup['version'] }}?"
                        data-confirm="Pharos replaces its own files with the copy taken on {{ $backup['created_at']->format('j M Y H:i') }} and, on SQLite, puts that copy of the database back too — everything entered since then is lost from the app, but not from the safety backup Pharos makes first. The page is briefly unavailable."
                        data-confirm-action="Roll back">
                    @csrf
                    <button type="submit">Roll back</button>
                  </form>
                  <form method="POST" action="{{ route('admin.updates.backup.destroy', $backup['name']) }}"
                        data-confirm-title="Remove backup {{ $backup['name'] }}?"
                        data-confirm="This is the copy of {{ $backup['version'] }} taken on {{ $backup['created_at']->format('j M Y H:i') }}. Once removed, there is nothing to put back."
                        data-confirm-action="Remove backup">
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
  @endif
  @if (! $backups)
    <div class="panel-bd">
      <p class="note">No backups yet — the first update creates one, or press <b>Back up now</b>.</p>
    </div>
  @endif
</div>

<x-note id="updates.backups">
  @if ($sqlite)The SQLite database is copied into the backup, so putting a folder back puts the data of that moment back too. @else Your database is not in these backups — dump it before an update; the update runs migrations. @endif
  Each update copies the version it replaces into <span class="mono">storage/app/backups</span>
  before writing anything. Pharos keeps the newest {{ \App\Services\InstallSettings::keepBackups() ?: 'all' }} (set under <a href="{{ route('admin.settings') }}">Settings → General</a>); older ones go when a new one is made. <b>Roll back</b> puts a folder back — after copying what it replaces into a
  backup of its own, so a rollback can be undone too.
</x-note>

<script>
(function () {
  var box = document.getElementById('backup-progress');
  if (!box || !window.fetch) return;
  var bar = box.querySelector('progress'), label = box.querySelector('[data-label]'), pct = box.querySelector('[data-pct]');
  var words = {
    backup: {counting: 'Counting files…', code: 'Copying code…', vendor: 'Copying vendor…', database: 'Copying the database…', finishing: 'Finishing…'},
    rollback: {safety: 'Keeping a safety copy…', code: 'Restoring code…', vendor: 'Restoring vendor…', database: 'Restoring the database…', finishing: 'Finishing…'}
  };
  var fmt = function (n) { return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, '\u202f'); };
  function finish(text, bad) { bar.hidden = true; pct.textContent = ''; label.textContent = text; box.classList.toggle('bad', !!bad); }
  // Back up now and every Roll back go the same way: POST over fetch, poll the
  // progress endpoint meanwhile, reload once the server has answered.
  document.addEventListener('submit', function (event) {
    var form = event.target.closest('form[data-progress]');
    // A guarded form first goes through the confirm dialog; it comes back with confirmed=yes.
    if (!form || (form.dataset.confirm && form.dataset.confirmed !== 'yes')) return;
    event.preventDefault();
    var job = form.dataset.job || 'backup', btn = form.querySelector('button'), failed = job === 'rollback' ? 'Rollback failed' : 'Backup failed';
    function show(s) {
      if (s.state === 'idle' || s.state === 'failed') return;
      var text = words[job][s.stage] || s.stage;
      if (s.total) { bar.max = s.total; bar.value = s.done; pct.textContent = Math.floor(100 * s.done / s.total) + '%'; text += ' ' + fmt(s.done) + ' / ' + fmt(s.total) + ' files'; }
      label.textContent = text;
    }
    function poll() {
      return fetch(form.dataset.progress, {credentials: 'same-origin', headers: {Accept: 'application/json'}}).then(function (r) { return r.json(); }).then(show).catch(function () {});
    }
    btn.disabled = true; box.hidden = false; box.classList.remove('bad');
    bar.hidden = false; bar.removeAttribute('value'); label.textContent = 'Starting…'; pct.textContent = '';
    // `php artisan serve` is single-threaded, so there the polls queue behind the
    // POST and the bar only moves at the end. Real web servers are fine.
    var timer = setInterval(poll, 500);
    fetch(form.action, {method: 'POST', credentials: 'same-origin', headers: {Accept: 'application/json', 'X-CSRF-TOKEN': form.querySelector('[name=_token]').value}})
      .then(function (r) {
        if (!/json/.test(r.headers.get('content-type') || '')) throw new Error(failed + ' (HTTP ' + r.status + ').');
        return r.json();
      })
      .then(function (j) {
        clearInterval(timer);
        return poll().then(function () {
          if (!j.ok) throw new Error(j.message || failed + '.');
          finish(j.message || 'Backup made: ' + j.name + ' (' + j.size + ')');
          // The table and the flash come with the fresh page; a rollback gets a moment longer to be read.
          setTimeout(function () { location.href = form.dataset.after || location.href; }, job === 'rollback' ? 4000 : 1500);
        });
      })
      .catch(function (err) { clearInterval(timer); finish(err.message || failed + '.', true); btn.disabled = false; });
  });
})();
</script>
@push('head')
<style>
.backup-progress[hidden]{display:none}
.backup-progress{display:grid;grid-template-columns:1fr auto;gap:6px 12px;padding:12px 20px;border-bottom:1px solid var(--line);background:var(--bg-tint);font-size:12px;color:var(--ink-3)}
.backup-progress.bad{color:var(--red-ink)}
.backup-progress .bar{grid-column:1/-1;width:100%;height:8px;border:0;border-radius:4px;overflow:hidden;background:var(--line);accent-color:var(--brand);-webkit-appearance:none;appearance:none}
.backup-progress .bar::-webkit-progress-bar{background:var(--line);border-radius:4px}
.backup-progress .bar::-webkit-progress-value{background:var(--brand);border-radius:4px;transition:width .3s var(--ease)}
.backup-progress .bar::-moz-progress-bar{background:var(--brand);border-radius:4px}
/* No value yet: a sliding band until the first sample arrives. */
.backup-progress .bar:indeterminate::-webkit-progress-bar,.backup-progress .bar:indeterminate::-moz-progress-bar{background:linear-gradient(90deg,var(--line) 0 35%,var(--brand) 50%,var(--line) 65% 100%) 0 0/200% 100%;animation:bar-slide 1.2s linear infinite}
@keyframes bar-slide{to{background-position:-200% 0}}
@media (prefers-reduced-motion:reduce){.backup-progress .bar:indeterminate::-webkit-progress-bar,.backup-progress .bar:indeterminate::-moz-progress-bar{animation:none;background:var(--brand-soft)}}
</style>
@endpush
@endsection
