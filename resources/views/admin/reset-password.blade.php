@extends('layouts.auth')
{{-- Also the "accept an invitation" screen ($invitation), which only differs in wording and target. --}}
@php($invitation = $invitation ?? false)
@section('title', $invitation ? 'Choose your password' : 'Reset password')
@section('card')
  <span class="auth-eyebrow">{{ $invitation ? 'Invitation' : 'Account recovery' }}</span>
  <h1>{{ $invitation ? 'Choose your password' : 'Choose a new password' }}</h1>
  <p class="lede">{{ $invitation ? 'Use at least 12 characters. You sign in with this email and password from now on.' : 'Use at least 12 characters. Your two factor authentication settings stay in place.' }}</p>
  @if ($errors->any())<div class="errors" role="alert"><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
  <form method="POST" action="{{ $invitation ? route('admin.invitation.accept') : route('admin.password.update') }}" class="recovery-form">
    @csrf
    <input type="hidden" name="token" value="{{ $token }}">
    <div class="field">
      <label for="email">Account email</label>
      <input id="email" name="email" type="email" value="{{ old('email', $email) }}" autocomplete="username" required>
    </div>
    <div class="field">
      <label for="password">{{ $invitation ? 'Password' : 'New password' }}</label>
      <input id="password" name="password" type="password" minlength="12" autocomplete="new-password" required autofocus>
    </div>
    <div class="field">
      <label for="password_confirmation">{{ $invitation ? 'Repeat password' : 'Confirm new password' }}</label>
      <input id="password_confirmation" name="password_confirmation" type="password" minlength="12" autocomplete="new-password" required>
    </div>
    <button type="submit" class="btn">{{ $invitation ? 'Set password' : 'Reset password' }}</button>
    @unless ($invitation)
      <a class="recovery-back" href="{{ route('admin.password.request') }}">Request a new reset link</a>
    @endunless
  </form>
@endsection
