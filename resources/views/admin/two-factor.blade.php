@extends('layouts.auth')
@section('title', 'Two-factor code')
@section('card')
  <h1>One more step</h1>
  <p class="lede">Your password checked out. Now the code from your authenticator app.</p>

  @if ($errors->any())<div class="errors"><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

  <form method="POST" action="{{ route('admin.two-factor.verify') }}" style="display:flex;flex-direction:column;gap:16px">
    @csrf
    <div class="field">
      <label for="code">Code</label>
      <input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code"
             required autofocus placeholder="123456" style="font-family:var(--mono);font-size:20px;letter-spacing:.3em;text-align:center">
      <span class="help">Lost your phone? A recovery code works here too, once each.</span>
    </div>
    <button class="btn" type="submit">Sign in</button>
  </form>

  <p style="margin:18px 0 0;font-size:13px"><a href="{{ route('admin.login') }}">Back to sign in</a></p>
@endsection
