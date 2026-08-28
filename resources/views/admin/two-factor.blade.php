@extends('layouts.admin')
@section('title', 'Two-factor code')
@section('content')
<div class="main" style="max-width:380px;margin:0 auto">
  @if ($errors->any())<div class="errors"><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
  <div class="head" style="justify-content:center;margin-bottom:26px">
    <h1>{{ \App\Models\Setting::get('brand.name', 'Pharos') }}</h1>
  </div>
  <div class="panel">
    <div class="panel-hd"><h3>One more step</h3><span class="hint">Your password checked out</span></div>
    <div class="panel-bd">
      <form method="POST" action="{{ route('admin.two-factor.verify') }}" style="display:flex;flex-direction:column;gap:16px">
        @csrf
        <div class="field">
          <label for="code">Code from your authenticator app</label>
          <input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code"
                 required autofocus placeholder="123456">
          <span class="help">Lost your phone? A recovery code works here too, once each.</span>
        </div>
        <button class="btn" type="submit">Sign in</button>
      </form>
    </div>
  </div>
  <p class="sub" style="text-align:center;margin-top:18px;font-size:13px">
    <a href="{{ route('admin.login') }}">Back to sign in</a>
  </p>
</div>
@endsection
