@extends('layouts.admin')
@section('title', 'Sign in')
@section('content')
<style>
  .ssosplit{display:flex;align-items:center;gap:12px;margin:18px 0 14px;color:var(--ink-3);font-size:12px}
  .ssosplit::before,.ssosplit::after{content:"";height:1px;flex:1;background:var(--line)}
</style>
<div class="main" style="max-width:380px;margin:0 auto">
  @if (session('status'))<div class="flash">{{ session('status') }}</div>@endif
  @if ($errors->any())<div class="errors"><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
  <div class="head" style="justify-content:center;margin-bottom:26px">
    <h1>{{ \App\Models\Setting::get('brand.name', 'Pharos') }}</h1>
  </div>
  <div class="panel">
    <div class="panel-bd">
      <form method="POST" action="{{ route('admin.login.attempt') }}" style="display:flex;flex-direction:column;gap:16px">
        @csrf
        <div class="field">
          <label for="email">Email</label>
          <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username">
        </div>
        <div class="field">
          <label for="password">Password</label>
          <input id="password" name="password" type="password" required autocomplete="current-password">
        </div>
        <label class="check"><input type="checkbox" name="remember" value="1"> Stay signed in</label>
        <button class="btn" type="submit">Sign in</button>
      </form>
      @if (app(\App\Services\Sso::class)->enabled())
        <div class="ssosplit"><span>or</span></div>
        <a class="btn ghost" style="width:100%;justify-content:center"
           href="{{ route('admin.sso.redirect') }}">Sign in with {{ app(\App\Services\Sso::class)->providerName() }}</a>
      @endif
    </div>
  </div>
</div>
@endsection
