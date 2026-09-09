@extends('layouts.auth')
@section('title', 'Reset password')
@section('card')
  <span class="auth-eyebrow">Account recovery</span>
  <h1>Choose a new password</h1>
  <p class="lede">Use at least 12 characters. Your two-factor authentication settings stay in place.</p>
  @if ($errors->any())<div class="errors" role="alert"><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
  <form method="POST" action="{{ route('admin.password.update') }}" class="recovery-form">
    @csrf
    <input type="hidden" name="token" value="{{ $token }}">
    <div class="field">
      <label for="email">Account email</label>
      <input id="email" name="email" type="email" value="{{ old('email', $email) }}" autocomplete="username" required>
    </div>
    <div class="field">
      <label for="password">New password</label>
      <input id="password" name="password" type="password" minlength="12" autocomplete="new-password" required autofocus>
    </div>
    <div class="field">
      <label for="password_confirmation">Confirm new password</label>
      <input id="password_confirmation" name="password_confirmation" type="password" minlength="12" autocomplete="new-password" required>
    </div>
    <button type="submit" class="btn">Reset password</button>
    <a class="recovery-back" href="{{ route('admin.password.request') }}">Request a new reset link</a>
  </form>
@endsection
