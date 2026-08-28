@extends('layouts.admin')
@section('title', 'Your profile')
@section('content')
@include('partials.pagehead', [
  'title' => 'Your profile',
  'sub' => 'Signed in as '.$user->email.' · '.$user->role->label(),
])

@if (session('recovery_codes'))
  <div class="panel">
    <div class="panel-hd"><h3>Recovery codes</h3><span class="hint">Shown once</span></div>
    <div class="panel-bd">
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
  </div>
@endif

<div class="panel">
  <div class="panel-hd"><h3>Your details</h3></div>
  <div class="panel-bd">
    <form method="POST" action="{{ route('admin.profile.update') }}" style="display:flex;flex-direction:column;gap:16px">
      @csrf @method('PUT')
      <div class="fields">
        <div class="field">
          <label for="name">Name</label>
          <input id="name" name="name" type="text" value="{{ old('name', $user->name) }}" required>
        </div>
        <div class="field">
          <label for="email">Email</label>
          <input id="email" name="email" type="email" value="{{ old('email', $user->email) }}" required>
          <span class="help">This is what you sign in with.</span>
        </div>
      </div>
      <div class="actions"><button class="btn" type="submit">Save details</button></div>
    </form>
  </div>
</div>

<div class="panel">
  <div class="panel-hd">
    <h3>Two-factor authentication</h3>
    <span class="hint">{{ $user->hasTwoFactor() ? 'On · '.$recoveryLeft.' unused recovery codes' : 'Off' }}</span>
  </div>
  <div class="panel-bd">
    @if ($user->hasTwoFactor())
      <p class="sub" style="font-size:13.5px;color:var(--ink-2)">
        Signing in asks for a code from your authenticator app after your password.
        Lost the phone and the codes? Run <span class="mono">php artisan pharos:2fa:disable {{ $user->email }}</span>
        on the server.
      </p>
      <form method="POST" action="{{ route('admin.profile.recovery-codes') }}" style="display:flex;flex-direction:column;gap:16px;margin-top:16px">
        @csrf
        <div class="field">
          <label for="rc-password">Your password</label>
          <input id="rc-password" name="current_password" type="password" required autocomplete="current-password">
        </div>
        <div class="actions"><button class="btn ghost" type="submit">New recovery codes</button></div>
      </form>
      <form method="POST" action="{{ route('admin.profile.two-factor.disable') }}"
            data-confirm-title="Switch two-factor off?"
            data-confirm="Your password becomes the only thing between anyone and this admin. Recovery codes are deleted."
            data-confirm-action="Switch it off"
            style="display:flex;flex-direction:column;gap:16px;margin-top:8px">
        @csrf @method('DELETE')
        <div class="field">
          <label for="off-password">Your password</label>
          <input id="off-password" name="current_password" type="password" required autocomplete="current-password">
        </div>
        <div class="actions"><button class="btn ghost" type="submit">Switch off two-factor</button></div>
      </form>
    @elseif ($pendingSecret)
      <x-note id="profile.two-factor">
        <b>Almost there.</b> Add the key below to your authenticator app, then enter a code to switch it on.
        Nothing changes about signing in until you do.
      </x-note>
      <div class="field" style="margin-top:14px">
        <label>Setup key</label>
        <div class="copy"><code>{{ trim(chunk_split($pendingSecret, 4, ' ')) }}</code></div>
        <span class="help">Type this into the app as a time-based key, or paste the link below.</span>
      </div>
      <div class="field">
        <label>Or paste this link into the app</label>
        <div class="copy"><code>{{ $otpauthUri }}</code></div>
      </div>
      <form method="POST" action="{{ route('admin.profile.two-factor.confirm') }}" style="display:flex;flex-direction:column;gap:16px">
        @csrf
        <div class="field">
          <label for="code">Code from the app</label>
          <input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" required placeholder="123456">
        </div>
        <div class="actions"><button class="btn" type="submit">Switch on two-factor</button></div>
      </form>
    @else
      <p class="sub" style="font-size:13.5px;color:var(--ink-2)">
        A stolen password on its own stops being enough: signing in also asks for a six-digit code
        from an authenticator app on your phone. You get ten single-use recovery codes for the day
        the phone is not there.
      </p>
      <form method="POST" action="{{ route('admin.profile.two-factor.start') }}" style="margin-top:16px">
        @csrf
        <div class="actions"><button class="btn" type="submit">Set up two-factor</button></div>
      </form>
    @endif
  </div>
</div>

<div class="panel">
  <div class="panel-hd"><h3>Change your password</h3></div>
  <div class="panel-bd">
    <form method="POST" action="{{ route('admin.profile.password') }}" style="display:flex;flex-direction:column;gap:16px">
      @csrf @method('PUT')
      <div class="field">
        <label for="p-current">Current password</label>
        <input id="p-current" name="current_password" type="password" required autocomplete="current-password">
        <span class="help">Asked for so a borrowed screen cannot lock you out of your own account.</span>
      </div>
      <div class="fields">
        <div class="field">
          <label for="p-new">New password</label>
          <input id="p-new" name="password" type="password" required autocomplete="new-password">
          <span class="help">At least 12 characters. Any other session on your account is signed out.</span>
        </div>
        <div class="field">
          <label for="p-confirm">Repeat</label>
          <input id="p-confirm" name="password_confirmation" type="password" required autocomplete="new-password">
        </div>
      </div>
      <div class="actions"><button class="btn ghost" type="submit">Change password</button></div>
    </form>
  </div>
</div>

<div class="panel">
  <div class="panel-hd"><h3>Notes</h3><span class="hint">The "Good to know" boxes around the admin</span></div>
  <div class="panel-bd">
    @if ($hiddenNotes)
      <p class="sub" style="font-size:13.5px;color:var(--ink-2)">You have hidden {{ $hiddenNotes }} Good to know {{ \Illuminate\Support\Str::plural('note', $hiddenNotes) }}.</p>
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
      <form method="POST" action="{{ route('admin.notes.restore') }}">
        @csrf
        <div class="actions"><button class="btn ghost" type="submit">Show all notes again</button></div>
      </form>
    @else
      <p class="sub" style="font-size:13.5px;color:var(--ink-2)">All Good to know notes are showing.</p>
    @endif
  </div>
</div>
@endsection
