@extends('layouts.install', ['current' => 6])
@section('title', 'Create your account')

{{-- Step 6 of the install journey the web installer started. Without JavaScript
     this is a plain form; pharos-install.js adds the detected time zone and the
     password strength meter. --}}
@section('content')
<div class="pi-kicker">Step 6 of 7</div>
<h1>Name your status page and create your account</h1>
<p class="pi-lede">You are the first administrator. You can invite others later and give them access per page.</p>

<form method="POST" action="{{ route('admin.install.store') }}" novalidate>
  @csrf
  <div class="pi-card"><div class="pi-bd">
    <div class="pi-grid">
      <div class="pi-field pi-span2">
        <label for="setup_key">Installation key</label>
        <input class="pi-inp" id="setup_key" name="setup_key" type="password" required autocomplete="off">
        <span class="pi-help">The same key you unlocked the installer with. You can also read <code>{{ $setupKeyPath }}</code> with your hosting File Manager. It is never shown to visitors.</span>
      </div>

      <div class="pi-field">
        <label for="site">Status page name</label>
        <input class="pi-inp" id="site" name="site" type="text" value="{{ old('site') }}" maxlength="60"
               required autofocus placeholder="Acme Hosting"
               @error('site') aria-invalid="true" aria-describedby="site-error" @enderror>
        @error('site')<span class="pi-err" id="site-error">{{ $message }}</span>@enderror
      </div>

      <div class="pi-field pi-tz">
        <label for="timezone">Time zone</label>
        @include('partials.timezone-select', ['selected' => old('timezone', 'UTC'), 'describedBy' => 'timezone-help'])
        @error('timezone')<span class="pi-err" id="timezone-help">{{ $message }}</span>@else<span class="pi-help" id="timezone-help" data-pi-tz-help>How times are shown. Stored in UTC, so you can change it later.</span>@enderror
      </div>

      <div class="pi-field">
        <label for="name">Your name</label>
        <input class="pi-inp" id="name" name="name" type="text" value="{{ old('name') }}" required autocomplete="name"
               @error('name') aria-invalid="true" aria-describedby="name-error" @enderror>
        @error('name')<span class="pi-err" id="name-error">{{ $message }}</span>@enderror
      </div>

      <div class="pi-field">
        <label for="email">Email</label>
        <input class="pi-inp" id="email" name="email" type="email" value="{{ old('email') }}" required autocomplete="username"
               @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
        @error('email')<span class="pi-err" id="email-error">{{ $message }}</span>@else<span class="pi-help">You sign in with this.</span>@enderror
      </div>

      <div class="pi-field">
        <label for="password">Password</label>
        <input class="pi-inp" id="password" name="password" type="password" required autocomplete="new-password" minlength="12"
               placeholder="At least 12 characters" aria-describedby="password-help"
               @error('password') aria-invalid="true" @enderror data-pi-password>
        <span class="pi-meter" aria-hidden="true"><i data-pi-meter></i></span>
        @error('password')
          <span class="pi-err" id="password-help">{{ $message }}</span>
        @else
          <span class="pi-help" id="password-help" data-pi-meter-label aria-live="polite">At least 12 characters. A sentence or a password manager works best.</span>
        @enderror
      </div>

      <div class="pi-field">
        <label for="password_confirmation">Repeat password</label>
        <input class="pi-inp" id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password" data-pi-password-repeat>
        <span class="pi-help" data-pi-repeat-label aria-live="polite"></span>
      </div>
    </div>

    <div class="pi-note">
      <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M7 11V8a5 5 0 0 1 10 0v3M5 11h14v10H5z"/></svg>
      <span>Turn on two factor authentication in your profile right after the first sign in.</span>
    </div>
  </div></div>

  <div class="pi-actions">
    <button class="pi-btn" type="submit" data-pi-submit>Create my account and finish</button>
    <span class="pi-sub">This screen disappears the moment the account exists.</span>
  </div>
</form>
@endsection
