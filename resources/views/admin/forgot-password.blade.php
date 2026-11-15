@extends('layouts.auth')
@section('title', __('Forgot password'))
@section('card')
  <h1 class="pa-h1">{{ __('Forgot your password?') }}</h1>
  <p class="pa-lede">{{ __('Enter your account email and we will send you a link to choose a new password.') }}</p>
  @if (session('status'))<div class="recovery-notice pa-flash" role="status">{{ __(session('status')) }}</div>@endif
  @include('partials.auth.errors')
  <form class="pa-form" method="POST" action="{{ route('admin.password.email') }}">
    @csrf
    <div class="pa-f">
      <label for="email">{{ __('Account email') }}</label>
      <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="username" required autofocus>
    </div>
    @include('partials.auth.submit', ['label' => __('Send reset link')])
  </form>
  <p class="pa-back"><a href="{{ route('admin.login') }}">{{ __('Back to sign in') }}</a></p>
@endsection
