@extends('layouts.admin')
@section('title', 'Users')
@section('content')
@php
  $me = auth()->user();
  $palette = ['#0b6bcb', '#7a5af8', '#0e9384', '#e8590c', '#c11574', '#667085'];
  $avatarColor = fn ($u) => $palette[$u->id % count($palette)];
  $roleNames = ['viewer' => 'Read only', 'editor' => 'Editor', 'admin' => 'Page admin'];
  $admins = $users->filter->isAdmin()->count();
  $withTwoFactor = $users->filter->hasTwoFactor()->count();
  $withoutAccess = $users->reject->isAdmin()->filter(fn ($u) => $u->statusPages->whereNull('archived_at')->isEmpty())->count();
  $weekAgo = now()->subDays(7);
  $activeWeek = collect($lastSeen)->filter(fn ($seen) => $seen->greaterThan($weekAgo))->count();
  $seenLabel = function ($u) use ($lastSeen, $sessionsKnown) {
      if (! $sessionsKnown) {
          return 'Unknown';
      }
      $seen = $lastSeen[$u->id] ?? null;
      if (! $seen) {
          return 'Not signed in';
      }
      if ($seen->greaterThan(now()->subMinutes(5))) {
          return 'Now';
      }

      return $seen->isToday() ? 'Today '.$seen->format('H:i') : ($seen->isYesterday() ? 'Yesterday' : $seen->diffForHumans());
  };
@endphp

@include('partials.pagehead', [
  'crumbs' => ['Users'],
  'crumbScope' => 'installation',
  'title' => 'Users',
  'sub' => 'Who can sign in, and what they may do on which page',
  'actions' => [['dialog' => 'user-add', 'label' => 'Add someone']],
])

<div class="pp-kpis">
  <div class="pp-kpi"><span class="k">Accounts</span><div class="v">{{ $users->count() }}</div>
    <span class="n">{{ $admins }} {{ \Illuminate\Support\Str::plural('administrator', $admins) }}, {{ $users->count() - $admins }} {{ \Illuminate\Support\Str::plural('user', $users->count() - $admins) }}</span></div>
  <div class="pp-kpi"><span class="k">Two factor</span><div class="v" @if ($withTwoFactor < $users->count()) style="color:var(--amber-ink)" @endif>{{ $withTwoFactor }}/{{ $users->count() }}</div>
    <span class="n">{{ $users->count() - $withTwoFactor === 0 ? 'Every account' : ($users->count() - $withTwoFactor).' '.\Illuminate\Support\Str::plural('account', $users->count() - $withTwoFactor).' without' }}</span></div>
  <div class="pp-kpi"><span class="k">Without access</span><div class="v">{{ $withoutAccess }}</div><span class="n">Can sign in, sees no page</span></div>
  <div class="pp-kpi"><span class="k">Active this week</span><div class="v">{{ $sessionsKnown ? $activeWeek : 'Unknown' }}</div>
    <span class="n">{{ $sessionsKnown ? 'Signed in during the last 7 days' : 'Needs database sessions' }}</span></div>
</div>

<section class="ix-card" aria-label="Accounts">
  <div class="pp-bar">
    <label class="sr-only" for="pp-q">Search name or email</label>
    <input class="ix-input pp-search" id="pp-q" type="search" placeholder="Search name or email" value="{{ $search }}" autocomplete="off">
    <div class="ix-chips" id="pp-f" role="group" aria-label="Show">
      <button type="button" class="ix-chip" data-k="all" aria-pressed="true">Everyone</button>
      <button type="button" class="ix-chip" data-k="admin" aria-pressed="false">Administrators</button>
      <button type="button" class="ix-chip" data-k="user" aria-pressed="false">Page users</button>
    </div>
    <span class="sub" id="pp-count" role="status" aria-live="polite"></span>
  </div>
  <div id="pp-list">
    @foreach ($users as $user)
      @php($assigned = $user->statusPages->whereNull('archived_at'))
      <div class="pp-user" data-kind="{{ $user->isAdmin() ? 'admin' : 'user' }}" data-q="{{ mb_strtolower($user->name.' '.$user->email) }}">
        <div class="pp-who">
          <span class="pp-av" style="background:{{ $avatarColor($user) }}" aria-hidden="true">{{ mb_strtoupper(mb_substr($user->name, 0, 1)) }}</span>
          <span><b>{{ $user->name }}@if ($user->is($me)) <span class="pp-tag user">you</span>@endif</b><span>{{ $user->email }}</span></span>
        </div>
        <div class="pp-badges">
          @if ($user->isAdmin())
            <span class="pp-acc" style="padding-left:9px">All pages <em>· administrator</em></span>
          @else
            @forelse ($assigned as $assignedPage)
              <span class="pp-acc">@include('partials.page-tag', ['tagPage' => $assignedPage]) {{ $assignedPage->name }} <em>· {{ $roleNames[$assignedPage->pivot->role] ?? $assignedPage->pivot->role }}</em></span>
            @empty
              <span class="pp-tag no2a">No page access yet</span>
            @endforelse
          @endif
        </div>
        <div class="pp-meta">
          <span class="pp-tag {{ $user->isAdmin() ? 'admin' : 'user' }}">{{ $user->role->label() }}</span>
          @if ($user->hasTwoFactor())
            <span class="pp-tag f2a">2FA on</span>
          @elseif ($user->require_two_factor)
            <span class="pp-tag no2a">2FA asked</span>
          @else
            <span class="pp-tag no2a">No 2FA</span>
          @endif
          <br>Last seen <b>{{ $seenLabel($user) }}</b>
        </div>
        <div class="pp-act">
          <button type="button" class="btn ghost" data-dialog="user-edit-{{ $user->id }}" aria-haspopup="dialog">Edit access</button>
          <details class="pp-more">
            <summary aria-label="More for {{ $user->name }}">&hellip;</summary>
            <div class="pp-menu">
              <form method="POST" action="{{ route('admin.users.invite', $user) }}">@csrf
                <button type="submit">Send a new invitation</button>
              </form>
              @unless ($user->is($me))
                <form method="POST" action="{{ route('admin.users.destroy', $user) }}"
                      data-confirm-title="Remove {{ $user->name }}?"
                      data-confirm="They lose access <strong>immediately</strong> and any open session stops working. Nothing they created is deleted."
                      data-confirm-action="Remove account">
                  @csrf @method('DELETE')
                  <button type="submit" class="danger">Remove account</button>
                </form>
              @endunless
            </div>
          </details>
        </div>
      </div>
    @endforeach
  </div>
  <p class="empty" id="pp-empty" hidden>Nobody matches that search.</p>
</section>

<details class="ix-more pp-rolesbox">
  <summary>What each role may do</summary>
  <div>
    <div class="scroll">
      <table class="pp-roles">
        <thead><tr><th scope="col"><span class="sr-only">Permission</span></th><th scope="col">Read only</th><th scope="col">Editor</th><th scope="col">Page admin</th><th scope="col">Administrator</th></tr></thead>
        <tbody>
          @foreach ([
            ['See dashboards, incidents and history', 1],
            ['Post incidents, change components', 2],
            ['Subscribers and integrations', 2],
            ['Branding, email, templates, API tokens', 3],
            ['Users, settings, licence, updates', 4],
            ['Create and archive pages', 4],
          ] as [$what, $from])
            <tr><td>{{ $what }}</td>
              @foreach ([1, 2, 3, 4] as $level)
                <td>@if ($level >= $from)<span class="y" aria-label="Yes">✓</span>@else<span class="x" aria-label="No">·</span>@endif</td>
              @endforeach
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
    <p class="sub">Page roles are set per page. A published status page stays public whatever the roles.</p>
  </div>
</details>

@include('admin.partials.access-drawer', ['member' => null])
@foreach ($users as $user)
  @include('admin.partials.access-drawer', ['member' => $user])
@endforeach

<script>
(function () {
  var q = document.getElementById('pp-q'), chips = document.getElementById('pp-f');
  var rows = document.querySelectorAll('.pp-user'), count = document.getElementById('pp-count'), empty = document.getElementById('pp-empty');
  function filter() {
    var kind = chips.querySelector('[aria-pressed="true"]').dataset.k, term = q.value.trim().toLowerCase(), shown = 0;
    rows.forEach(function (row) {
      row.hidden = (kind !== 'all' && row.dataset.kind !== kind) || row.dataset.q.indexOf(term) === -1;
      if (!row.hidden) shown++;
    });
    empty.hidden = shown !== 0;
    count.textContent = shown === rows.length ? '' : shown + ' of ' + rows.length + ' shown';
  }
  q.addEventListener('input', filter);
  chips.addEventListener('click', function (event) {
    var chip = event.target.closest('.ix-chip');
    if (!chip) return;
    chips.querySelectorAll('.ix-chip').forEach(function (c) { c.setAttribute('aria-pressed', c === chip ? 'true' : 'false'); });
    filter();
  });
  filter();
})();
</script>
@endsection
