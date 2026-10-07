@extends('layouts.auth')
@section('title', __('Sign in'))
@section('card')
  <p class="pa-kicker"><i aria-hidden="true"></i>{{ __('Admin sign in') }}</p>
  <h1 class="pa-h1">{{ __('Sign in to') }} {{ $branding->name() }}</h1>
  <p class="pa-lede">{{ __('Manage components, incidents and maintenance for your status pages.') }}</p>

  @if (session('status'))<div class="flash pa-flash" role="status" data-pa-flash>{{ __(session('status')) }}</div>@endif
  @if (request('after') === 'rollback')
    {{-- Not a flash: the rollback replaced the session store, so the message travels in the URL. --}}
    <div class="flash pa-flash">{{ __('Rolled back to a backup. You were signed out because the session store was restored too. Sign in with the password you had at the time of that backup.') }}</div>
  @endif
  @include('partials.auth.errors')

  <form class="pa-form" method="POST" action="{{ route('admin.login.attempt') }}"
        data-pa-login data-pa-fail="{{ route('admin.login') }}" data-pa-step="{{ route('admin.two-factor') }}">
    @csrf
    <div class="pa-fl">
      <input id="email" name="email" type="email" value="{{ old('email') }}" placeholder=" " required autofocus autocomplete="username">
      <label for="email">{{ __('Email') }}</label>
    </div>
    <div class="pa-fl pa-fl-pw" data-pa-pwbox>
      <input id="password" name="password" type="password" placeholder=" " required autocomplete="current-password">
      <label for="password">{{ __('Password') }}</label>
      <button type="button" class="pa-eye" aria-controls="password" aria-label="{{ __('Show password') }}" data-pa-eye hidden>{{ __('Show') }}</button>
    </div>
    <div class="pa-opts">
      <label class="pa-switch"><input type="checkbox" name="remember" value="1"> {{ __('Stay signed in') }}</label>
      <a href="{{ route('admin.password.request') }}">{{ __('Forgot password?') }}</a>
    </div>
    @include('partials.auth.submit', ['label' => __('Sign in')])
  </form>

  <div data-passkey-login data-options-url="{{ route('admin.passkeys.login.options') }}" data-submit-url="{{ route('admin.passkeys.login') }}" data-csrf="{{ csrf_token() }}" data-unavailable="{{ __('Passkeys are unavailable here. Use a supported browser on the configured HTTPS hostname.') }}" data-error="{{ __('Passkey sign in failed. Start again and verify your device.') }}"><button class="pa-ghost" type="button" disabled>{{ __('Sign in with a passkey') }}</button><p data-passkey-status role="status"></p></div>
  <script defer src="{{ asset('assets/pharos-passkeys.js') }}"></script>
  @if (app(\App\Services\Sso::class)->enabled())
    <div class="pa-or"><span>{{ __('or') }}</span></div>
    <a class="pa-ghost" href="{{ route('admin.sso.redirect') }}">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="8" cy="15" r="4"/><path d="m10.8 12.2 9.2-9.2M17 6l3 3M14 9l2 2"/></svg>
      {{ __('Sign in with') }} {{ app(\App\Services\Sso::class)->providerName() }}
    </a>
  @endif
@endsection
