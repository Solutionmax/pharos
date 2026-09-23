@extends('layouts.auth')
@section('title', 'Forgot password')
@section('card')
  <p class="pa-kicker"><i aria-hidden="true"></i>Account recovery</p>
  <h1 class="pa-h1">Forgot your password?</h1>
  <p class="pa-lede">Enter your account email and we will send you a link to choose a new password.</p>
  @if (session('status'))<div class="recovery-notice pa-flash" role="status">{{ session('status') }}</div>@endif
  @include('partials.auth.errors')
  <form class="pa-form" method="POST" action="{{ route('admin.password.email') }}">
    @csrf
    <div class="pa-fl">
      <input id="email" name="email" type="email" value="{{ old('email') }}" placeholder=" " autocomplete="username" required autofocus>
      <label for="email">Account email</label>
    </div>
    @include('partials.auth.submit', ['label' => 'Send reset link'])
  </form>
  <p class="pa-back"><a href="{{ route('admin.login') }}">Back to sign in</a></p>
@endsection
