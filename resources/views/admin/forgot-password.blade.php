@extends('layouts.auth')
@section('title', 'Forgot password')
@section('card')
  <span class="auth-eyebrow">Account recovery</span>
  <h1>Forgot your password?</h1>
  <p class="lede">Enter your account email and we will send you a link to choose a new password.</p>
  @if (session('status'))<div class="recovery-notice" role="status">{{ session('status') }}</div>@endif
  @if ($errors->any())<div class="errors" role="alert"><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
  <form method="POST" action="{{ route('admin.password.email') }}" class="recovery-form">
    @csrf
    <div class="field">
      <label for="email">Account email</label>
      <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="username" required autofocus>
    </div>
    <button type="submit" class="btn">Send reset link</button>
    <a class="recovery-back" href="{{ route('admin.login') }}">Back to sign in</a>
  </form>
@endsection
