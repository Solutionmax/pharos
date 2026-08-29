@extends('layouts.auth')
@section('title', 'Sign in')
@section('card')
  <h1>Sign in</h1>
  <p class="lede">Your status page is watching. Sign in to see what it found.</p>

  @if (session('status'))<div class="flash">{{ session('status') }}</div>@endif
  @if (request('after') === 'rollback')
    {{-- Not a flash: the rollback replaced the session store, so the message travels in the URL. --}}
    <div class="flash">Rolled back to a backup. You were signed out because the session store was restored too — sign in with the password you had at the time of that backup.</div>
  @endif
  @if ($errors->any())<div class="errors"><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

  <form method="POST" action="{{ route('admin.login.attempt') }}" style="display:flex;flex-direction:column;gap:16px">
    @csrf
    <div class="field">
      <label for="email">Email</label>
      <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username">
    </div>
    <div class="field">
      <label for="password">Password</label>
      <input id="password" name="password" type="password" required autocomplete="current-password">
    </div>
    <label class="check"><input type="checkbox" name="remember" value="1"> Stay signed in</label>
    <button class="btn" type="submit">Sign in</button>
  </form>

  @if (app(\App\Services\Sso::class)->enabled())
    <div class="ssosplit"><span>or</span></div>
    <a class="btn ghost" href="{{ route('admin.sso.redirect') }}">Sign in with {{ app(\App\Services\Sso::class)->providerName() }}</a>
  @endif
@endsection
