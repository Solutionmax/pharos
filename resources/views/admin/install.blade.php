@extends('layouts.admin')
@section('title', 'Welcome')

@php
    // Ninety days of a plausible history, fixed rather than random so the panel
    // renders identically on every load and in every screenshot. o = up,
    // w = degraded, b = down.
    $history = str_split(str_repeat('o', 34).'w'.str_repeat('o', 12).'b'.'w'.str_repeat('o', 41));
@endphp

@push('head')
<style>
.setup{display:grid;grid-template-columns:minmax(340px,7fr) 9fr;min-height:100vh}

/* ---- left: the product, not a decoration ------------------------------- */
.setup-aside{
  /* The logo partial paints itself with var(--brand); on this navy the company
     blue goes muddy, so the panel raises it rather than forking the partial. */
  --brand:#2ea3ff;
  --on:#32d583; --degraded:#fdb022; --off:#f97066;
  --pane-ink:#eaf0f7; --pane-ink-2:#93a7bd; --pane-ink-3:#6d8299; --pane-line:#1d2f45;
  --pad:clamp(34px,4vw,56px);
  position:relative;overflow:hidden;
  /* Corners are anchored and the story sits in the optical centre, so the
     panel does not pool everything at the bottom on a tall screen. */
  display:flex;flex-direction:column;justify-content:center;gap:30px;
  padding:var(--pad);
  background:radial-gradient(120% 90% at 12% 0%,#12293f 0%,#0a1729 46%,#060f1c 100%);
  box-shadow:1px 0 0 var(--pane-line);
  color:var(--pane-ink);
}
/* The lighthouse does one thing: it sweeps. Slow enough to be atmosphere
   rather than an animation someone has to sit through. */
.setup-aside::before{
  content:"";position:absolute;top:-42%;left:-14%;width:150%;height:150%;
  background:conic-gradient(from 0deg at 22% 30%,
    transparent 0deg, #2ea3ff26 12deg, #2ea3ff00 40deg, transparent 360deg);
  animation:sweep 18s linear infinite;pointer-events:none;
}
.setup-aside > *{position:relative}
@keyframes sweep{to{transform:rotate(360deg)}}
@media (prefers-reduced-motion:reduce){.setup-aside::before{animation:none;opacity:.5}}

.setup-brand{position:absolute;top:var(--pad);left:var(--pad);
  display:flex;align-items:center;gap:11px;font-weight:700;font-size:16px;letter-spacing:-.01em}

.setup-lede h2{
  font-size:clamp(26px,2.5vw,36px);line-height:1.15;letter-spacing:-.035em;font-weight:800;
  text-wrap:balance;margin-bottom:14px}
.setup-lede h2 em{font-style:normal;color:var(--pane-ink-3)}
.setup-lede p{color:var(--pane-ink-2);font-size:14.5px;max-width:34ch}

/* ---- the uptime strip: the same object the status page ships ----------- */
.setup-strip{display:flex;gap:1.5px;height:40px;align-items:stretch}
.setup-strip span{flex:1;border-radius:1.5px;background:var(--on)}
.setup-strip span.w{background:var(--degraded)}
.setup-strip span.b{background:var(--off)}
.setup-scale{
  display:flex;justify-content:space-between;margin-top:9px;
  font-family:var(--mono);font-size:11px;color:var(--pane-ink-3);letter-spacing:.02em}
.setup-scale strong{color:var(--pane-ink-2);font-weight:500}

.setup-next{border-top:1px solid var(--pane-line);padding-top:24px;display:grid;gap:11px;margin:0;list-style:none}
.setup-next li{display:flex;gap:12px;align-items:baseline;font-size:13.5px;color:var(--pane-ink-2)}
.setup-next b{
  font-family:var(--mono);font-size:11px;font-weight:500;color:var(--pane-ink-3);
  flex:none;width:20px}
.setup-foot{position:absolute;bottom:var(--pad);left:var(--pad);
  font-size:12px;color:var(--pane-ink-3);letter-spacing:.01em}

/* ---- right: the form has the room, and nothing else ------------------- */
.setup-form{display:flex;align-items:center;justify-content:center;padding:clamp(28px,4vw,56px);background:var(--bg)}
.setup-form-inner{width:100%;max-width:400px}
.setup-form h1{font-size:29px;letter-spacing:-.035em;margin-bottom:7px}
.setup-form .sub{color:var(--ink-3);font-size:14px;margin-bottom:30px}
.setup-form form{display:flex;flex-direction:column;gap:17px}
.setup-pair{display:grid;grid-template-columns:1fr 1fr;gap:12px}
@media (max-width:420px){.setup-pair{grid-template-columns:1fr}}

.setup-form .field input{background:var(--card);transition:border-color .15s var(--ease)}
/* Border colour is the affordance; the focus ring itself stays the layout's
   :focus-visible outline, so keyboard users keep what they had. */
.setup-form .field input:focus,.setup-form .field select:focus{border-color:var(--brand)}
.setup-form .field input[aria-invalid=true]{border-color:var(--red)}
.setup-form .err{font-size:12px;color:var(--red-ink);font-weight:500}
.setup-form .help{font-size:12px;color:var(--ink-3)}

.setup-submit{width:100%;padding:12px 18px;font-size:14px;border-radius:11px;cursor:pointer}
.setup-submit:hover{filter:brightness(1.07)}
.setup-submit:active{transform:translateY(1px)}
.setup-note{margin-top:22px;font-size:12px;color:var(--ink-3);text-align:center;line-height:1.6}

/* Stack below the split's natural breaking point. The panel keeps its story
   but stops eating the fold on a phone. */
@media (max-width:860px){
  .setup{grid-template-columns:1fr;min-height:0}
  .setup-aside{--pad:26px;gap:22px;padding:26px 24px 30px}
  .setup-brand{position:static}
  .setup-next,.setup-foot{display:none}
  .setup-form{padding:36px 24px 56px}
}
</style>
@endpush

@section('content')
<div class="setup">

  <aside class="setup-aside">
    <span class="setup-brand">@include('partials.logo', ['size' => 26])</span>

    <div class="setup-lede">
      <h2>Your status page is green.<br><em>Your server is not.</em></h2>
      <p>Pharos polls your endpoints itself and opens the incident before a customer emails you about it.</p>
    </div>

    <div>
      <div class="setup-strip" role="img" aria-label="Ninety days of uptime, one outage and two degraded days">
        @foreach ($history as $day)
          <span @class(['w' => $day === 'w', 'b' => $day === 'b'])></span>
        @endforeach
      </div>
      <div class="setup-scale">
        <span>90 days ago</span>
        <span><strong>99.94%</strong> uptime</span>
        <span>today</span>
      </div>
    </div>

    <ol class="setup-next">
      <li><b>01</b> Name your page and create your login</li>
      <li><b>02</b> Add the things you want watched</li>
      <li><b>03</b> Pharos checks them and writes the incidents</li>
    </ol>

    <span class="setup-foot">AGPL-3.0 &middot; self-hosted &middot; nothing phones home</span>
  </aside>

  <main class="setup-form">
    <div class="setup-form-inner">
      <h1>Welcome</h1>
      <p class="sub">One screen, and this status page is yours.</p>

      <form method="POST" action="{{ route('admin.install.store') }}" novalidate>
        @csrf

        <div class="field">
          <label for="site">Status page name</label>
          <input id="site" name="site" type="text" value="{{ old('site') }}" maxlength="60"
                 required autofocus placeholder="Acme Hosting"
                 @error('site') aria-invalid="true" @enderror>
          @error('site')<span class="err">{{ $message }}</span>@enderror
        </div>

        <div class="field">
          <label for="timezone">Time zone</label>
          @include('partials.timezone-select', ['selected' => old('timezone', 'UTC')])
          @error('timezone')<span class="err">{{ $message }}</span>@else<span class="help">How times are shown. Stored in UTC, so you can change it later.</span>@enderror
        </div>

        <div class="field">
          <label for="name">Your name</label>
          <input id="name" name="name" type="text" value="{{ old('name') }}" required autocomplete="name"
                 @error('name') aria-invalid="true" @enderror>
          @error('name')<span class="err">{{ $message }}</span>@enderror
        </div>

        <div class="field">
          <label for="email">Email</label>
          <input id="email" name="email" type="email" value="{{ old('email') }}" required autocomplete="username"
                 @error('email') aria-invalid="true" @enderror>
          @error('email')<span class="err">{{ $message }}</span>@else<span class="help">You sign in with this.</span>@enderror
        </div>

        <div class="setup-pair">
          <div class="field">
            <label for="password">Password</label>
            <input id="password" name="password" type="password" required autocomplete="new-password"
                   @error('password') aria-invalid="true" @enderror>
          </div>
          <div class="field">
            <label for="password_confirmation">Confirm</label>
            <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password">
          </div>
        </div>
        @error('password')
          <span class="err" style="margin-top:-8px">{{ $message }}</span>
        @else
          <span class="help" style="margin-top:-8px">At least 12 characters.</span>
        @enderror

        <button class="btn setup-submit" type="submit">Create administrator</button>
      </form>

      <p class="setup-note">This screen disappears the moment the account exists.</p>
    </div>
  </main>

</div>
@endsection
