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
            data-job="update" data-progress="{{ route('admin.updates.backup.progress') }}"
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

{{-- One dialog for the three jobs that rewrite the install — update, backup, rollback.
     Each shows its steps as the server reports them; without JS the forms post as before. --}}
<dialog class="modal job" id="job-dialog" aria-labelledby="job-title">
  <div class="panel">
    <div class="panel-hd"><span class="job-dot" aria-hidden="true"></span><h3 id="job-title">Working…</h3><span class="hint mono" data-pct></span></div>
    <div class="panel-bd">
      <ol class="job-steps" data-steps></ol>
      <progress class="bar" data-bar></progress>
      <p class="job-say mono" data-say>Starting…</p>
      <div class="modal-act"><button type="button" class="btn ghost" data-close disabled>Close</button></div>
    </div>
  </div>
</dialog>

<script>
(function () {
  var dialog = document.getElementById('job-dialog');
  if (!dialog || !window.fetch || !dialog.showModal) return;
  var q = function (sel) { return dialog.querySelector(sel); };
  var title = q('#job-title'), steps = q('[data-steps]'), bar = q('[data-bar]'), say = q('[data-say]'), pct = q('[data-pct]'), close = q('[data-close]');
  // Stage order per job, as the server reports them; the words are what the operator reads.
  var jobs = {
    update:   {title: 'Installing the update', steps: [['download', 'Downloading the release'], ['verify', 'Checking the signed checksum'], ['unpack', 'Unpacking'], ['backup', 'Backing up the current version'], ['install', 'Installing the new files'], ['migrate', 'Running migrations'], ['finishing', 'Clearing caches']]},
    backup:   {title: 'Making a backup', steps: [['counting', 'Counting files'], ['code', 'Copying code'], ['vendor', 'Copying vendor'], ['database', 'Copying the database'], ['finishing', 'Finishing']]},
    rollback: {title: 'Rolling back', steps: [['safety', 'Keeping a safety copy of now'], ['code', 'Restoring code'], ['vendor', 'Restoring vendor'], ['database', 'Restoring the database'], ['finishing', 'Clearing caches']]}
  };
  var fmt = function (n) { return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, '\u202f'); };
  var running = false;

  function open(job) {
    title.textContent = jobs[job].title;
    steps.innerHTML = '';
    jobs[job].steps.forEach(function (st) {
      var li = document.createElement('li'); li.dataset.stage = st[0]; li.innerHTML = '<i></i><span></span>'; li.querySelector('span').textContent = st[1]; steps.appendChild(li);
    });
    bar.hidden = false; bar.removeAttribute('value'); say.textContent = 'Starting…'; pct.textContent = '';
    dialog.classList.remove('bad', 'ok'); close.disabled = true; running = true;
    dialog.showModal();
  }
  function mark(stage) {
    var seen = false;
    steps.querySelectorAll('li').forEach(function (li) {
      if (seen) { li.className = ''; return; }
      if (li.dataset.stage === stage) { li.className = 'now'; seen = true; return; }
      li.className = 'done';
    });
  }
  function show(s) {
    if (s.state === 'idle' || s.state === 'failed') return;
    mark(s.stage);
    if (s.total) { bar.max = s.total; bar.value = s.done; pct.textContent = Math.floor(100 * s.done / s.total) + '%'; say.textContent = fmt(s.done) + ' / ' + fmt(s.total) + ' files'; }
    else { bar.removeAttribute('value'); say.textContent = ''; }
  }
  function finish(text, bad) {
    running = false; bar.hidden = true; pct.textContent = '';
    steps.querySelectorAll('li').forEach(function (li) { if (bad) { if (li.className === 'now') li.className = 'bad'; } else { li.className = 'done'; } });
    say.textContent = text; dialog.classList.add(bad ? 'bad' : 'ok'); close.disabled = false; close.focus();
  }
  close.addEventListener('click', function () { dialog.close(); });
  // Escape closes the dialog; while a job runs it must not, the server is still busy.
  dialog.addEventListener('cancel', function (event) { if (running) event.preventDefault(); });

  // Install, Back up now and every Roll back go the same way: POST over fetch,
  // poll the progress endpoint meanwhile, reload once the server has answered.
  document.addEventListener('submit', function (event) {
    var form = event.target.closest('form[data-progress]');
    // A guarded form first goes through the confirm dialog; it comes back with confirmed=yes.
    if (!form || (form.dataset.confirm && form.dataset.confirmed !== 'yes')) return;
    event.preventDefault();
    var job = form.dataset.job || 'backup', btn = form.querySelector('button');
    var failed = {update: 'Update failed', backup: 'Backup failed', rollback: 'Rollback failed'}[job];
    function poll() {
      return fetch(form.dataset.progress, {credentials: 'same-origin', headers: {Accept: 'application/json'}}).then(function (r) { return r.json(); }).then(show).catch(function () {});
    }
    btn.disabled = true; open(job);
    // `php artisan serve` is single-threaded, so there the polls queue behind the
    // POST and the steps only move at the end. Real web servers are fine.
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
          // The fresh page carries the new version, table and flash; a rollback gets a moment longer to be read.
          setTimeout(function () { location.href = form.dataset.after || location.href; }, job === 'backup' ? 1500 : 4000);
        });
      })
      .catch(function (err) { clearInterval(timer); finish(err.message || failed + '.', true); btn.disabled = false; });
  });
})();
</script>
@push('head')
<style>
.modal.job .panel-hd{display:flex;align-items:center;gap:10px}
.modal.job .panel-hd h3{flex:1}
.job-dot{width:10px;height:10px;border-radius:50%;background:var(--brand);position:relative;flex:none}
.job-dot::after{content:"";position:absolute;inset:-4px;border-radius:50%;border:2px solid var(--brand);opacity:0;animation:job-ring 1.4s ease-out infinite}
.modal.job.ok .job-dot{background:var(--green)}.modal.job.ok .job-dot::after{animation:none}
.modal.job.bad .job-dot{background:var(--red)}.modal.job.bad .job-dot::after{animation:none}
@keyframes job-ring{0%{transform:scale(.6);opacity:.9}100%{transform:scale(1.8);opacity:0}}
.job-steps{list-style:none;margin:0 0 14px;padding:0;display:grid;gap:6px;font-size:13px;color:var(--ink-3)}
.job-steps li{display:flex;align-items:center;gap:10px;transition:color .2s}
.job-steps li i{width:16px;height:16px;border-radius:50%;border:1.5px solid var(--line2);flex:none;display:inline-grid;place-items:center;font:10px/1 ui-monospace,monospace;font-style:normal}
.job-steps li.now{color:var(--ink)}
.job-steps li.now i{border-color:var(--brand);border-top-color:transparent;animation:job-spin .8s linear infinite}
.job-steps li.done{color:var(--ink-2)}
.job-steps li.done i{background:var(--green);border-color:var(--green)}
.job-steps li.done i::before{content:"✓";color:#fff}
.job-steps li.bad{color:var(--red-ink)}
.job-steps li.bad i{background:var(--red);border-color:var(--red)}
.job-steps li.bad i::before{content:"!";color:#fff}
@keyframes job-spin{to{transform:rotate(360deg)}}
.job-say{margin:8px 0 0;font-size:12px;color:var(--ink-3);min-height:1.4em}
.modal.job.bad .job-say{color:var(--red-ink)}
.modal.job .bar[hidden]{display:none}
.modal.job .bar{width:100%;height:8px;border:0;border-radius:4px;overflow:hidden;background:var(--line);accent-color:var(--brand);-webkit-appearance:none;appearance:none}
.modal.job .bar::-webkit-progress-bar{background:var(--line);border-radius:4px}
.modal.job .bar::-webkit-progress-value{background:var(--brand);border-radius:4px;transition:width .3s var(--ease)}
.modal.job .bar::-moz-progress-bar{background:var(--brand);border-radius:4px}
/* No value yet: a sliding band until the first sample arrives. */
.modal.job .bar:indeterminate::-webkit-progress-bar,.modal.job .bar:indeterminate::-moz-progress-bar{background:linear-gradient(90deg,var(--line) 0 35%,var(--brand) 50%,var(--line) 65% 100%) 0 0/200% 100%;animation:bar-slide 1.2s linear infinite}
@keyframes bar-slide{to{background-position:-200% 0}}
@media (prefers-reduced-motion:reduce){.job-dot::after,.job-steps li.now i,.modal.job .bar:indeterminate::-webkit-progress-bar,.modal.job .bar:indeterminate::-moz-progress-bar{animation:none}.job-steps li.now i{border-top-color:var(--brand)}.modal.job .bar:indeterminate::-webkit-progress-bar{background:var(--brand-soft)}}
</style>
@endpush
@endsection
