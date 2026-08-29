@extends('layouts.admin')
{{--
  The signed-out shell. Everything on the panel is built from the operator's own
  brand: the accent colour is the light, their logo sits under the sweep, and
  with the credit hidden (Brand pack) nothing on the page names Pharos. Without
  a Brand pack the Pharos mark stands in and the credit shows — the lighthouse
  is ours, the page is theirs.
--}}
@php
  $ownLogo = $branding->logoDarkUrl() ?? $branding->logoUrl();
  $whiteLabel = $branding->creditHidden();
@endphp
@section('content')
<style>
  .auth{min-height:100vh;display:grid;grid-template-columns:minmax(0,1.05fr) minmax(0,1fr)}
  @media (max-width:860px){.auth{grid-template-columns:1fr;grid-template-rows:200px auto}}

  /* ---------- the panel: the operator's colour, dark ---------- */
  .lh{position:relative;overflow:hidden;isolation:isolate;color:#dbe7f5;
      --deep:color-mix(in srgb,var(--brand) 22%,#070f1c);
      --deeper:color-mix(in srgb,var(--brand) 10%,#05090f);
      --light:color-mix(in srgb,var(--brand) 60%,#ffffff);
      background:var(--deeper);
      display:flex;flex-direction:column;justify-content:flex-end;padding:clamp(24px,4vw,48px)}
  .lh::before{content:"";position:absolute;inset:0;
      background:radial-gradient(70% 60% at 50% 42%,var(--deep) 0%,var(--deeper) 60%,#04070c 100%)}
  .lh::after{content:"";position:absolute;inset:0;pointer-events:none;opacity:.35;
      background-image:repeating-linear-gradient(to right,#ffffff0a 0 1px,transparent 1px 12.5%)}
  .lh-sky{position:absolute;inset:0;overflow:hidden}
  /* the sweep: a cone of the brand's light, turning around the logo */
  .beam{position:absolute;left:50%;top:40%;width:220vmax;height:220vmax;margin:-110vmax 0 0 -110vmax;
      background:conic-gradient(from -22deg at 50% 50%,transparent 0deg,transparent 6deg,
        color-mix(in srgb,var(--light) 22%,transparent) 16deg,
        color-mix(in srgb,var(--light) 36%,transparent) 22deg,
        color-mix(in srgb,var(--light) 22%,transparent) 28deg,transparent 38deg,transparent 44deg);
      animation:sweep 9s linear infinite;mix-blend-mode:screen;will-change:transform}
  .beam.b2{opacity:.55;animation-delay:-4.5s}
  @keyframes sweep{to{transform:rotate(360deg)}}
  /* a soft halo behind whatever sits in the middle */
  .halo{position:absolute;left:50%;top:40%;width:34vmin;height:34vmin;transform:translate(-50%,-50%);border-radius:50%;
      background:radial-gradient(circle,color-mix(in srgb,var(--light) 28%,transparent) 0%,transparent 70%);animation:glow 3.2s var(--ease) infinite}
  @keyframes glow{0%,100%{opacity:.7;transform:translate(-50%,-50%) scale(1)}50%{opacity:1;transform:translate(-50%,-50%) scale(1.12)}}
  .lh-mark{position:absolute;left:50%;top:40%;transform:translate(-50%,-50%);width:min(40vmin,280px);color:#eaf2fb;
      filter:drop-shadow(0 20px 40px #00000066);display:flex;justify-content:center}
  .lh-mark svg{width:100%;height:auto;display:block}
  .lh-mark img{max-width:100%;max-height:min(26vmin,160px);width:auto;height:auto;display:block}

  /* ninety days under the light: they wake as the sweep passes */
  .days{position:relative;display:flex;gap:3px;height:34px;align-items:flex-end;margin-bottom:14px}
  .days i{flex:1;height:100%;border-radius:2px;background:#12b76a;opacity:.22;animation:wake 9s linear infinite;animation-delay:calc(var(--i) * -.1s)}
  .days i.w{background:#f79009}
  .days i.blip{animation:blip 9s linear infinite;animation-delay:calc(var(--i) * -.1s)}
  @keyframes wake{0%,72%{opacity:.22}80%{opacity:1}88%,100%{opacity:.22}}
  @keyframes blip{0%,72%{opacity:.22;background:#12b76a}78%{opacity:1;background:#f04438}84%{opacity:1;background:#f79009}88%{opacity:1;background:#12b76a}100%{opacity:.22;background:#12b76a}}
  .lh-cap{position:relative;display:flex;align-items:center;gap:10px;font-family:var(--mono);font-size:10.5px;letter-spacing:.14em;text-transform:uppercase;color:#9fb3c8}
  .lh-cap b{width:7px;height:7px;border-radius:50%;background:#12b76a;box-shadow:0 0 0 0 #12b76a66;animation:beat 2.4s var(--ease) infinite}
  @keyframes beat{0%{box-shadow:0 0 0 0 #12b76a66}70%{box-shadow:0 0 0 9px #12b76a00}100%{box-shadow:0 0 0 0 #12b76a00}}
  .lh-cap .r{margin-left:auto;color:#7d8fa3;letter-spacing:.08em;text-transform:none}
  .lh-line{position:relative;font-family:var(--sans);font-weight:800;letter-spacing:-.03em;line-height:1.05;font-size:clamp(22px,2.6vw,34px);color:#fff;margin:0 0 18px;max-width:20ch}
  .lh-line em{font-style:normal;color:var(--light)}
  @media (max-width:860px){
    .lh{padding:18px 22px;justify-content:center}
    .lh-line,.days{display:none}
    .lh-mark{width:150px;top:50%}
    .lh-mark img{max-height:80px}
    .beam,.halo{top:50%}
    .lh-cap{position:absolute;left:22px;right:22px;bottom:14px}
  }

  /* ---------- the card ---------- */
  .au{display:flex;flex-direction:column;justify-content:center;align-items:center;padding:clamp(24px,5vw,64px) clamp(18px,4vw,48px);background:var(--bg)}
  .au-card{width:100%;max-width:420px;animation:rise .7s var(--ease) both}
  @keyframes rise{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:none}}
  .au-brand{display:flex;align-items:center;gap:12px;margin-bottom:28px;font-weight:800;font-size:19px;letter-spacing:-.025em;color:var(--ink)}
  .au h1{margin:0 0 6px;font-size:28px;font-weight:800;letter-spacing:-.035em;line-height:1.1}
  .au .lede{margin:0 0 26px;color:var(--ink-2);font-size:14.5px}
  .au .field input{background:var(--card);border-color:var(--line);transition:border-color .15s,box-shadow .15s}
  .au .field input:focus{border-color:var(--brand);box-shadow:0 0 0 4px color-mix(in srgb,var(--brand) 18%,transparent);outline:none}
  .au .btn{width:100%;justify-content:center;padding:12px 18px;font-size:14.5px;border-radius:12px;transition:transform .15s var(--ease),box-shadow .2s}
  .au .btn:hover{transform:translateY(-1px);box-shadow:0 10px 24px -12px color-mix(in srgb,var(--brand) 70%,transparent)}
  .au .btn:active{transform:none}
  .au-foot{margin-top:28px;font-size:12.5px;color:var(--ink-3);display:flex;gap:14px;flex-wrap:wrap}
  .au-foot a{color:var(--ink-3)}.au-foot a:hover{color:var(--ink)}
  .ssosplit{display:flex;align-items:center;gap:12px;margin:18px 0 14px;color:var(--ink-3);font-size:12px}
  .ssosplit::before,.ssosplit::after{content:"";height:1px;flex:1;background:var(--line)}

  @media (prefers-reduced-motion:reduce){
    .beam,.beam.b2{animation:none;transform:rotate(-30deg)}
    .days i,.days i.blip,.halo,.lh-cap b,.au-card{animation:none}
    .days i{opacity:.55}
  }
</style>

<div class="auth">
  <aside class="lh" aria-hidden="true">
    <div class="lh-sky">
      <div class="beam"></div><div class="beam b2"></div>
      <div class="halo"></div>
    </div>
    <div class="lh-mark">
      @if ($ownLogo)
        <img src="{{ $ownLogo }}" alt="">
      @else
        @include('partials.mark')
      @endif
    </div>
    <p class="lh-line">Every check runs. <em>Every minute.</em></p>
    <div class="days">
      @for ($i = 0; $i < 90; $i++)
        <i style="--i:{{ $i }}" class="{{ $i === 61 ? 'blip' : ($i === 23 ? 'w' : '') }}"></i>
      @endfor
    </div>
    <div class="lh-cap"><b></b>all systems operational<span class="r">last 90 days</span></div>
  </aside>

  <main class="au">
    <div class="au-card">
      <div class="au-brand">
        @include('partials.logo', ['size' => 34])
      </div>
      @yield('card')
      <div class="au-foot">
        <a href="{{ url('/') }}">Status page</a>
        @unless ($whiteLabel)
          <a href="{{ config('pharos.docs_url') }}" target="_blank" rel="noopener">Documentation</a>
          <span>Powered by Pharos</span>
        @endunless
      </div>
    </div>
  </main>
</div>
@endsection
