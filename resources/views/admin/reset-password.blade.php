@extends('layouts.auth')
{{-- Also the "accept an invitation" screen ($invitation), which only differs in wording and target. --}}
@php($invitation = $invitation ?? false)
@section('title', $invitation ? 'Choose your password' : 'Reset password')
@section('card')
  <p class="pa-kicker"><i aria-hidden="true"></i>{{ $invitation ? __('Invitation') : __('Account recovery') }}</p>
  <h1 class="pa-h1">{{ $invitation ? __('Choose your password') : __('Choose a new password') }}</h1>
  <p class="pa-lede">{{ $invitation ? __('Use at least 12 characters. You sign in with this email and password from now on.') : __('Use at least 12 characters. Your two factor authentication settings stay in place.') }}</p>
  @include('partials.auth.errors')
  <form class="pa-form" method="POST" action="{{ $invitation ? route('admin.invitation.accept') : route('admin.password.update') }}">
    @csrf
    <input type="hidden" name="token" value="{{ $token }}">
    <div class="pa-fl">
      <input id="email" name="email" type="email" value="{{ old('email', $email) }}" placeholder=" " autocomplete="username" required>
      <label for="email">{{ __('Account email') }}</label>
    </div>
    <div class="pa-fl pa-fl-pw">
      <input id="password" name="password" type="password" minlength="12" placeholder=" " autocomplete="new-password" required autofocus>
      <label for="password">{{ $invitation ? __('Password') : __('New password') }}</label>
      <button type="button" class="pa-eye" aria-controls="password" aria-label="{{ __('Show password') }}" data-pa-eye hidden>{{ __('Show') }}</button>
    </div>
    <div class="pa-fl pa-fl-pw">
      <input id="password_confirmation" name="password_confirmation" type="password" minlength="12" placeholder=" " autocomplete="new-password" required>
      <label for="password_confirmation">{{ $invitation ? __('Repeat password') : __('Confirm new password') }}</label>
      <button type="button" class="pa-eye" aria-controls="password_confirmation" aria-label="{{ __('Show password') }}" data-pa-eye hidden>{{ __('Show') }}</button>
    </div>
    @include('partials.auth.submit', ['label' => $invitation ? 'Set password' : 'Reset password'])
  </form>
  @unless ($invitation)
    <p class="pa-back"><a href="{{ route('admin.password.request') }}">{{ __('Request a new reset link') }}</a></p>
  @endunless
@endsection
