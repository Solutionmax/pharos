@extends('layouts.auth')
@use('App\Services\SignInStatus')
@use('App\Services\Sso')
@php
  // The installation picks the layout; the default status page decides whether
  // there is anything to show. Not public: no status at all, and the status
  // layout falls back to the plain column.
  $layout = SignInStatus::layout();
  $status = app(SignInStatus::class)->read($layout === SignInStatus::STATUS);
  $sso = app(Sso::class);
@endphp
@section('title', __('Sign in'))
@section('card')
  <h1 class="pa-h1">{{ __('Sign in') }}</h1>

  @if (session('status'))<div class="flash pa-flash" role="status" data-pa-flash>{{ __(session('status')) }}</div>@endif
  @if (request('after') === 'rollback')
    {{-- Not a flash: the rollback replaced the session store, so the message travels in the URL. --}}
    <div class="flash pa-flash">{{ __('Rolled back to a backup. You were signed out because the session store was restored too. Sign in with the password you had at the time of that backup.') }}</div>
  @endif
  @include('partials.auth.errors')

  <form class="pa-form" method="POST" action="{{ route('admin.login.attempt') }}"
        data-pa-login data-pa-fail="{{ route('admin.login') }}" data-pa-step="{{ route('admin.two-factor') }}">
    @csrf
    <div class="pa-f">
      <label for="email">{{ __('Email') }}</label>
      <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username">
    </div>
    <div @class(['pa-f', 'pa-f-pw', 'is-bad' => $errors->any()]) data-pa-pwbox>
      <div class="pa-f-head">
        <label for="password">{{ __('Password') }}</label>
        <a href="{{ route('admin.password.request') }}">{{ __('Forgot password?') }}</a>
      </div>
      <div class="pa-in">
        <input id="password" name="password" type="password" required autocomplete="current-password" @if ($errors->any()) aria-invalid="true" @endif>
        @include('partials.auth.eye', ['for' => 'password'])
      </div>
      {{-- Empty until Caps Lock is on while typing: a live region, so it is said once, not on every focus. --}}
      <p class="pa-caps" data-pa-caps data-pa-caps-text="{{ __('Caps Lock is on.') }}" aria-live="polite"></p>
    </div>
    <label class="pa-keep"><input type="checkbox" name="remember" value="1"> {{ __('Stay signed in on this device') }}</label>
    @include('partials.auth.submit', ['label' => __('Sign in')])
  </form>

  <p class="pa-lbl pa-or">{{ __('or') }}</p>
  <div @class(['pa-alt', 'pa-alt-two' => $sso->enabled()])>
    <div class="pa-passkey" data-passkey-login data-options-url="{{ route('admin.passkeys.login.options') }}" data-submit-url="{{ route('admin.passkeys.login') }}" data-csrf="{{ csrf_token() }}" data-unavailable="{{ __('Passkeys are unavailable here. Use a supported browser on the configured HTTPS hostname.') }}" data-error="{{ __('Passkey sign in failed. Start again and verify your device.') }}">
      <button class="pa-ghost" type="button" aria-label="{{ __('Sign in with a passkey') }}" disabled>
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="9" cy="8" r="3.5"/><path d="M3 20v-1a5 5 0 0 1 5-5h2a5 5 0 0 1 2.5.7"/><circle cx="18" cy="13" r="2.5"/><path d="M18 15.5V21l1.5-1.5"/></svg>
        {{ __('Passkey') }}
      </button>
      <p class="pa-passkey-status" data-passkey-status role="status"></p>
    </div>
    @if ($sso->enabled())
      <a class="pa-ghost" href="{{ route('admin.sso.redirect') }}" aria-label="{{ __('Sign in with') }} {{ $sso->providerName() }}">
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="8" cy="15" r="4"/><path d="m10.8 12.2 9.2-9.2M17 6l3 3M14 9l2 2"/></svg>
        {{ $sso->providerName() }}
      </a>
    @endif
  </div>
  <script @nonce defer src="{{ asset('assets/pharos-passkeys.js') }}"></script>
@endsection
@if ($status)
  @section('line')
    @include('partials.auth.status-line', ['status' => $status])
  @endsection
@endif
@if ($status && $layout === SignInStatus::STATUS)
  @section('aside')
    @include('partials.auth.status-panel', ['status' => $status])
  @endsection
@endif
