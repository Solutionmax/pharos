@extends('layouts.admin')
{{--
  The signed-out shell: a lighthouse panel beside the form. The panel is Pharos'
  own identity (navy, the lamp, a sweep of light over a bar of days) and stays
  navy in both themes; the card follows the theme tokens. The customer's logo,
  when they have a Brand pack, sits on the card — the lighthouse is ours.
--}}
@section('content')
<style>
  .auth{min-height:100vh;display:grid;grid-template-columns:minmax(0,1.05fr) minmax(0,1fr)}
  @media (max-width:860px){.auth{grid-template-columns:1fr;grid-template-rows:200px auto}}

  /* ---------- the lighthouse panel ---------- */
  .lh{position:relative;overflow:hidden;background:var(--navy);color:#dbe7f5;isolation:isolate;
      display:flex;flex-direction:column;justify-content:flex-end;padding:clamp(24px,4vw,48px)}
  .lh::before{content:"";position:absolute;inset:0;
      background:radial-gradient(70% 60% at 50% 42%,#12305a 0%,#0a1729 55%,#070f1c 100%)}
  /* faint ruling, the same paper the site is printed on */
  .lh::after{content:"";position:absolute;inset:0;pointer-events:none;opacity:.35;
      background-image:repeating-linear-gradient(to right,#ffffff0a 0 1px,transparent 1px 12.5%)}
  .lh-sky{position:absolute;inset:0;overflow:hidden}
  /* the beam: a cone of light pinned to the lamp, turning */
  .beam{position:absolute;left:50%;top:38%;width:220vmax;height:220vmax;margin:-110vmax 0 0 -110vmax;
      background:conic-gradient(from -22deg at 50% 50%,transparent 0deg,#f5a62300 6deg,#f5a62333 16deg,#f5a62355 22deg,#f5a62333 28deg,#f5a62300 38deg,transparent 44deg);
      animation:sweep 9s linear infinite;mix-blend-mode:screen;will-change:transform}
  .beam.b2{opacity:.55;animation-delay:-4.5s}
  @keyframes sweep{to{transform:rotate(360deg)}}
  .lamp{position:absolute;left:50%;top:38%;width:18px;height:18px;margin:-9px 0 0 -9px;border-radius:50%;
      background:#ffd27a;box-shadow:0 0 18px 6px #f5a623aa,0 0 80px 30px #f5a62344;animation:glow 3s var(--ease) infinite}
  @keyframes glow{0%,100%{box-shadow:0 0 18px 6px #f5a623aa,0 0 80px 30px #f5a62344}50%{box-shadow:0 0 24px 10px #f5a623cc,0 0 120px 44px #f5a62355}}
  .lh-mark{position:absolute;left:50%;top:38%;transform:translate(-50%,-18%);width:min(38vmin,260px);color:#eaf2fb;filter:drop-shadow(0 20px 40px #00000066)}
  .lh-mark svg{width:100%;height:auto;display:block}
  .lh-mark svg path[fill="#F5A623"]{fill:#ffd27a}

  /* ninety days under the light: they wake up as the beam passes */
  .days{position:relative;display:flex;gap:3px;height:34px;align-items:flex-end;margin-bottom:14px}
  .days i{flex:1;height:100%;border-radius:2px;background:#12b76a;opacity:.22;animation:wake 9s linear infinite;animation-delay:calc(var(--i) * -.1s)}
  .days i.w{background:#f79009}.days i.b{background:#f04438}
  .days i.blip{animation:blip 9s linear infinite;animation-delay:calc(var(--i) * -.1s)}
  @keyframes wake{0%,72%{opacity:.22}80%{opacity:1}88%,100%{opacity:.22}}
  @keyframes blip{0%,72%{opacity:.22;background:#12b76a}78%{opacity:1;background:#f04438}84%{opacity:1;background:#f79009}88%{opacity:1;background:#12b76a}100%{opacity:.22;background:#12b76a}}
  .lh-cap{position:relative;display:flex;align-items:center;gap:10px;font-family:var(--mono);font-size:10.5px;letter-spacing:.14em;text-transform:uppercase;color:#7e9ab5}
  .lh-cap b{width:7px;height:7px;border-radius:50%;background:#12b76a;box-shadow:0 0 0 0 #12b76a66;animation:beat 2.4s var(--ease) infinite}
  @keyframes beat{0%{box-shadow:0 0 0 0 #12b76a66}70%{box-shadow:0 0 0 9px #12b76a00}100%{box-shadow:0 0 0 0 #12b76a00}}
  .lh-cap .r{margin-left:auto;color:#5d7794;letter-spacing:.08em;text-transform:none}
  .lh-line{position:relative;font-family:var(--sans);font-weight:800;letter-spacing:-.03em;line-height:1.05;font-size:clamp(22px,2.6vw,34px);color:#fff;margin:0 0 18px;max-width:18ch}
  .lh-line em{font-style:normal;color:#ffd27a}
  @media (max-width:860px){
    .lh{padding:18px 22px;justify-content:center}
    .lh-line,.days{display:none}
    .lh-mark{width:150px;top:50%;transform:translate(-50%,-52%)}
    .beam{top:50%}.lamp{top:50%}
    .lh-cap{position:absolute;left:22px;right:22px;bottom:14px}
  }

  /* ---------- the card ---------- */
  .au{display:flex;flex-direction:column;justify-content:center;align-items:center;padding:clamp(24px,5vw,64px) clamp(18px,4vw,48px);background:var(--bg)}
  .au-card{width:100%;max-width:420px;animation:rise .7s var(--ease) both}
  @keyframes rise{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:none}}
  .au-brand{display:flex;align-items:center;gap:12px;margin-bottom:28px}
  .au-brand .nm{font-weight:800;font-size:19px;letter-spacing:-.025em;color:var(--ink)}
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
    .days i,.days i.blip,.lamp,.lh-cap b,.au-card{animation:none}
    .days i{opacity:.55}
  }
</style>

<div class="auth">
  <aside class="lh" aria-hidden="true">
    <div class="lh-sky">
      <div class="beam"></div><div class="beam b2"></div>
      <div class="lamp"></div>
    </div>
    <div class="lh-mark">
      @include('partials.mark')
    </div>
    <p class="lh-line">The light is on. <em>Nothing slips past it.</em></p>
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
        <span>Pharos</span>
        <a href="{{ config('pharos.docs_url') }}" target="_blank" rel="noopener">Documentation</a>
        <a href="{{ url('/') }}">Status page</a>
      </div>
    </div>
  </main>
</div>
@endsection
