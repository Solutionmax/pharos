@extends('layouts.admin')
@section('title', 'Sign in')
@section('content')
<div style="max-width:380px;margin:8vh auto 0">
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
    </div>
  </div>
</div>
@endsection
