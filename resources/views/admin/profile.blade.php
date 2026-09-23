@extends('layouts.admin')
@section('title', 'Your profile')
@section('content')
@php
  $palette = ['#0b6bcb', '#7a5af8', '#0e9384', '#e8590c', '#c11574', '#667085'];
  $themeNow = in_array($user->theme, \App\Models\User::THEMES, true) ? $user->theme : app(\App\Services\Branding::class)->theme();
  $securityGood = ($user->hasTwoFactor() ? 1 : 0) + 1;
@endphp
@include('partials.pagehead', [
  'title' => 'Your profile',
  'sub' => 'Your account, security and preferences',
])

<section class="pf-hero" aria-label="Your account">
  <span class="pp-av" style="background:{{ $palette[$user->id % count($palette)] }}" aria-hidden="true">{{ mb_strtoupper(mb_substr($user->name, 0, 1)) }}</span>
  <div style="min-width:0">
    <h2>{{ $user->name }}</h2>
    <p>{{ $user->email }}</p>
    <div class="pp-badges">
      <span class="pp-tag {{ $user->isAdmin() ? 'admin' : 'user' }}">{{ $user->role->label() }}</span>
      <span class="pp-tag {{ $user->hasTwoFactor() ? 'f2a' : 'no2a' }}">{{ $user->hasTwoFactor() ? '2FA on' : 'No 2FA' }}</span>
      <span class="pp-tag user">{{ $pageCount === null ? 'All pages' : $pageCount.' '.\Illuminate\Support\Str::plural('page', $pageCount) }}</span>
    </div>
  </div>
  <div class="right">
    <div class="pf-stat"><b>{{ $user->created_at?->format('j M Y') }}</b><span>Member since</span></div>
    <div class="pf-stat"><b>{{ $thisDevice['browser'] }} on {{ $thisDevice['platform'] }}</b><span>This session</span></div>
  </div>
</section>

@if (session('recovery_codes'))
  <section class="ix-card pf-codes" aria-labelledby="pf-codes-title">
    <header><h3 id="pf-codes-title">Recovery codes</h3><span class="hint">Shown once</span></header>
    <div class="bd">
      <x-note id="profile.recovery-codes">
        <b>Save these now.</b> Each one signs you in once when your authenticator app is not to hand.
        They are stored hashed, so this screen is the only place they exist in full.
      </x-note>
      <div class="fields" style="margin-top:14px">
        @foreach (session('recovery_codes') as $code)
          <div class="copy"><code>{{ $code }}</code></div>
        @endforeach
      </div>
    </div>
  </section>
@endif

<div class="pf-grid">
  <div class="pf-col">
    <section class="ix-card" aria-labelledby="pf-details">
      <header><h3 id="pf-details">Your details</h3></header>
      <div class="bd">
        <form method="POST" action="{{ route('admin.profile.update') }}">
          @csrf @method('PUT')
          <div class="ix-row">
            <div><label class="ix-lbl" for="name">Name</label>
              <input class="ix-input" id="name" name="name" type="text" value="{{ old('name', $user->name) }}" required></div>
            <div><label class="ix-lbl" for="email">Email</label>
              <input class="ix-input" id="email" name="email" type="email" value="{{ old('email', $user->email) }}" required autocomplete="username"></div>
          </div>
          <p class="pf-help">This is what you sign in with.</p>
          <button class="btn" type="submit" style="margin-top:14px">Save details</button>
        </form>
      </div>
    </section>

    <section class="ix-card" aria-labelledby="pf-prefs">
      <header><h3 id="pf-prefs">Preferences</h3></header>
      <div class="bd">
        <form method="POST" action="{{ route('admin.profile.preferences') }}" class="pf-pref">
          @csrf @method('PUT')
          <span><b id="pf-theme-label">Theme</b><span>For the admin screens. The quick switch at the top of each screen still works for this browser.</span></span>
          <span class="pf-prefctl">
            <span class="pp-seg" role="radiogroup" aria-labelledby="pf-theme-label">
              @foreach (['light' => 'Light', 'system' => 'System', 'dark' => 'Dark'] as $value => $label)
                <label><input type="radio" name="theme" value="{{ $value }}" @checked($themeNow === $value)><span>{{ $label }}</span></label>
              @endforeach
            </span>
            <button class="btn ghost" type="submit">Save</button>
          </span>
        </form>
        <div class="pf-pref">
          <span><b>"Good to know" notes</b>
            <span>{{ $hiddenNotes ? 'You have hidden '.$hiddenNotes.' Good to know '.\Illuminate\Support\Str::plural('note', $hiddenNotes).'.' : 'All Good to know notes are showing.' }}</span></span>
          @if ($hiddenNotes)
            <form method="POST" action="{{ route('admin.notes.restore') }}">@csrf
              <button class="btn ghost" type="submit">Show all notes again</button>
            </form>
          @endif
        </div>
        @if ($hiddenNotes)
          <details class="ix-more">
            <summary>Hidden notes, one by one</summary>
            <div class="notes-hidden">
              @foreach ($hiddenByPage as $page => $group)
                <div class="notes-page">
                  <b>@if ($group['url'])<a href="{{ $group['url'] }}">{{ $page }}</a>@else{{ $page }}@endif</b>
                  <ul>
                    @foreach ($group['notes'] as $note)
                      <li>
                        <span>{{ $note['title'] }}</span>
                        <form method="POST" action="{{ route('admin.notes.restore-one', $note['id']) }}">@csrf<button type="submit">Show again</button></form>
                      </li>
                    @endforeach
                  </ul>
                </div>
              @endforeach
            </div>
          </details>
        @endif
      </div>
    </section>
  </div>

  <div class="pf-col">
    <section class="ix-card" aria-labelledby="pf-security">
      <header><h3 id="pf-security">Security</h3><span class="hint">{{ $securityGood }} of 2 in order</span></header>
      <div class="bd">
        <div class="pf-sec">
          <span class="ic {{ $user->hasTwoFactor() ? 'good' : 'warn' }}" aria-hidden="true">@include('partials.icon', ['name' => 'shield', 'size' => 17])</span>
          <div style="flex:1;min-width:0">
            <b>Two factor authentication</b>
            @if ($user->hasTwoFactor())
              <p>On. {{ $recoveryLeft }} unused recovery codes left. Lost the phone and the codes? Run <span class="mono">php artisan pharos:2fa:disable {{ $user->email }}</span> on the server.</p>
              <details class="ix-more">
                <summary>New recovery codes</summary>
                <form method="POST" action="{{ route('admin.profile.recovery-codes') }}">
                  @csrf
                  <label class="ix-lbl" for="rc-password">Your password</label>
                  <input class="ix-input" id="rc-password" name="current_password" type="password" required autocomplete="current-password">
                  <button class="btn ghost" type="submit">Make new recovery codes</button>
                </form>
              </details>
              <details class="ix-more">
                <summary>Switch two factor off</summary>
                <form method="POST" action="{{ route('admin.profile.two-factor.disable') }}"
                      data-confirm-title="Switch two factor off?"
                      data-confirm="Your password becomes the only thing between anyone and this admin. Recovery codes are deleted."
                      data-confirm-action="Switch it off">
                  @csrf @method('DELETE')
                  <label class="ix-lbl" for="off-password">Your password</label>
                  <input class="ix-input" id="off-password" name="current_password" type="password" required autocomplete="current-password">
                  <button class="btn ghost" type="submit">Switch off two factor</button>
                </form>
              </details>
            @elseif ($pendingSecret)
              <p>Almost there. Add the key to your authenticator app, then enter a code to switch it on. Nothing changes about signing in until you do.</p>
              <div class="pf-setup">
                <label class="ix-lbl">Setup key</label>
                <div class="copy"><code>{{ trim(chunk_split($pendingSecret, 4, ' ')) }}</code></div>
                <label class="ix-lbl">Or paste this link into the app</label>
                <div class="copy"><code>{{ $otpauthUri }}</code></div>
                <form method="POST" action="{{ route('admin.profile.two-factor.confirm') }}">
                  @csrf
                  <label class="ix-lbl" for="code">Code from the app</label>
                  <input class="ix-input" id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" required placeholder="123456">
                  <button class="btn" type="submit">Switch on two factor</button>
                </form>
              </div>
            @else
              <p>Off. With it on, a stolen password is not enough: signing in also asks for a six digit code from an app on your phone, with ten single use recovery codes for the day the phone is not there.</p>
              <form method="POST" action="{{ route('admin.profile.two-factor.start') }}">
                @csrf
                <button class="btn" type="submit">Set up two factor</button>
              </form>
            @endif
          </div>
        </div>
        <div class="pf-sec">
          <span class="ic n" aria-hidden="true">@include('partials.icon', ['name' => 'key', 'size' => 17])</span>
          <div style="flex:1;min-width:0">
            <b>Password</b>
            <p>At least 12 characters. When you change it, every other session on your account is signed out.</p>
            <details class="ix-more" @if ($errors->has('current_password') || $errors->has('password')) open @endif>
              <summary>Change password</summary>
              <form method="POST" action="{{ route('admin.profile.password') }}">
                @csrf @method('PUT')
                <label class="ix-lbl" for="p-current">Current password</label>
                <input class="ix-input" id="p-current" name="current_password" type="password" required autocomplete="current-password">
                <label class="ix-lbl" for="p-new">New password</label>
                <input class="ix-input" id="p-new" name="password" type="password" required autocomplete="new-password">
                <label class="ix-lbl" for="p-confirm">Repeat new password</label>
                <input class="ix-input" id="p-confirm" name="password_confirmation" type="password" required autocomplete="new-password">
                <button class="btn" type="submit">Change password</button>
              </form>
            </details>
          </div>
        </div>
      </div>
    </section>

    <section class="ix-card" aria-labelledby="pf-sessions">
      <header><h3 id="pf-sessions">Where you are signed in</h3>
        @if (count($sessions) > 1)
          <form method="POST" action="{{ route('admin.profile.sessions.others') }}" class="hint"
                data-confirm-title="Sign out everywhere else?"
                data-confirm="Every other browser signed in to your account has to sign in again. This one stays signed in."
                data-confirm-action="Sign out the others">
            @csrf @method('DELETE')
            <button type="submit" class="linkbtn">Sign out the others</button>
          </form>
        @endif
      </header>
      <div class="bd">
        @if (! $sessionsKnown)
          <p class="pf-help">Sessions are not kept in the database on this installation, so they cannot be listed here.</p>
        @else
          <ul class="pf-sess">
            @forelse ($sessions as $session)
              <li>
                <span class="ic" aria-hidden="true">@include('partials.icon', ['name' => $session['phone'] ? 'phone' : 'desktop', 'size' => 18])</span>
                <span style="min-width:0"><b>{{ $session['browser'] }} on {{ $session['platform'] }}</b>
                  <span class="d">{{ $session['ip'] ? $session['ip'].', ' : '' }}{{ $session['current'] ? 'now' : $session['last']->diffForHumans() }}</span></span>
                @if ($session['current'])
                  <span class="ix-state ok">This one</span>
                @else
                  <form method="POST" action="{{ route('admin.profile.sessions.destroy', $session['key']) }}">
                    @csrf @method('DELETE')
                    <button type="submit" class="btn ghost pf-small" aria-label="Sign out {{ $session['browser'] }} on {{ $session['platform'] }}, last active {{ $session['last']->diffForHumans() }}">Sign out</button>
                  </form>
                @endif
              </li>
            @empty
              <li><span></span><span class="d">No sessions found.</span><span></span></li>
            @endforelse
          </ul>
        @endif
      </div>
    </section>
  </div>
</div>
@endsection
