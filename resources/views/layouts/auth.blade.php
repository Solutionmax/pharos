@extends('layouts.admin')
{{--
  The signed-out shell: the product itself, drawn. In the middle the installation
  (the operator's logo), around it the components it watches; probes leave the
  centre as pulses of the brand's light, every node answers, one stops answering
  and turns red, an incident writes itself, and the node comes back. Built from
  the operator's brand tokens; with the credit hidden nothing here names Pharos.
--}}
@php
  $ownLogo = $branding->logoDarkUrl() ?? $branding->logoUrl();
  $whiteLabel = $branding->creditHidden();
@endphp
@section('content')
<style>
  .auth{min-height:100vh;display:grid;grid-template-columns:minmax(0,1.05fr) minmax(0,1fr)}
  @media (max-width:860px){.auth{grid-template-columns:1fr;grid-template-rows:230px auto}}

  /* ---------- the panel ---------- */
  .pn{position:relative;overflow:hidden;isolation:isolate;color:#dbe7f5;
      --deep:color-mix(in srgb,var(--brand) 20%,#070f1c);
      --deeper:color-mix(in srgb,var(--brand) 8%,#05090f);
      --light:color-mix(in srgb,var(--brand) 55%,#ffffff);
      background:radial-gradient(75% 65% at 50% 45%,var(--deep) 0%,var(--deeper) 62%,#04070c 100%);
      display:flex;flex-direction:column;justify-content:flex-end;padding:clamp(24px,4vw,48px)}
  .pn::after{content:"";position:absolute;inset:0;pointer-events:none;opacity:.5;
      background-image:radial-gradient(#ffffff0d 1px,transparent 1.2px);background-size:26px 26px}
  .stage{position:absolute;inset:0 0 118px 0;display:flex;align-items:center;justify-content:center}
  .net{width:min(92%,640px);height:auto;max-height:100%;overflow:visible}
  .net .lines line{stroke:color-mix(in srgb,var(--light) 18%,transparent);stroke-width:1.2}
  .net .pulse{fill:var(--light);filter:drop-shadow(0 0 6px var(--light))}
  .net .dot{fill:#12b76a;transition:fill .4s}
  .net .ring{fill:none;stroke:#12b76a;stroke-width:2;opacity:0;transform-box:fill-box;transform-origin:center;
      animation:ping 5.6s var(--ease) infinite;animation-delay:calc(var(--d) + 2.25s)}
  @keyframes ping{0%,40%{opacity:0;transform:scale(1)}42%{opacity:.9;transform:scale(1)}70%{opacity:0;transform:scale(3.2)}100%{opacity:0}}
  /* web-06: answers, answers, then stops — and comes back */
  .net .dn .dot{animation:fail 22.4s linear infinite}
  .net .dn .ring{animation:pingfail 22.4s linear infinite}
  @keyframes fail{0%,48%{fill:#12b76a}50%,54%{fill:#f79009}55%,82%{fill:#f04438}84%,100%{fill:#12b76a}}
  @keyframes pingfail{0%,10%{opacity:0;transform:scale(1)}10.5%{opacity:.9;stroke:#12b76a}14%{opacity:0;transform:scale(3.2)}
    35%,35.5%{opacity:.9;transform:scale(1);stroke:#12b76a}39%{opacity:0;transform:scale(3.2)}
    60%,60.5%{opacity:.9;transform:scale(1);stroke:#f04438}64%{opacity:0;transform:scale(3.2)}
    85%,85.5%{opacity:.9;transform:scale(1);stroke:#12b76a}89%{opacity:0;transform:scale(3.2)}100%{opacity:0}}
  .net .lbl{font-family:var(--mono);font-size:11.5px;fill:#c7d5e6;letter-spacing:.02em}
  .net .lbl .sub{fill:#7d8fa3}
  .net .core-ring{fill:var(--deeper);stroke:color-mix(in srgb,var(--light) 45%,transparent);stroke-width:1.5}
  .core{position:absolute;left:50%;top:50%;transform:translate(-50%,-50%);width:min(11vmin,64px);display:flex;justify-content:center;color:#eaf2fb;
      filter:drop-shadow(0 6px 18px #00000088)}
  .core img{max-width:100%;max-height:min(8vmin,48px);width:auto;height:auto}
  .core-dot{width:18px;height:18px;border-radius:50%;background:#12b76a;box-shadow:0 0 0 0 #12b76a66,0 0 22px 4px #12b76a55;animation:corebeat 2.4s var(--ease) infinite}
  @keyframes corebeat{0%{box-shadow:0 0 0 0 #12b76a66,0 0 22px 4px #12b76a55}70%{box-shadow:0 0 0 14px #12b76a00,0 0 26px 6px #12b76a66}100%{box-shadow:0 0 0 0 #12b76a00,0 0 22px 4px #12b76a55}}
  .stage-in{position:relative;width:min(92%,640px);aspect-ratio:1;max-height:100%;display:flex;align-items:center;justify-content:center}
  .stage-in .net{position:absolute;inset:0;width:100%;height:100%}

  /* the incident that writes itself, timed to web-06 */
  .inc{position:absolute;right:clamp(16px,3vw,40px);top:clamp(16px,3vw,40px);width:min(300px,60%);
      background:#0e1a2bcc;border:1px solid #ffffff1a;border-left:3px solid #f04438;border-radius:10px;padding:12px 14px;
      backdrop-filter:blur(8px);font-size:12.5px;color:#dbe7f5;box-shadow:0 20px 40px -24px #000;
      opacity:0;transform:translateY(-8px);animation:incident 22.4s linear infinite}
  .inc b{display:block;font-weight:700;letter-spacing:-.01em;margin-bottom:4px;color:#fff}
  .inc .st{font-family:var(--mono);font-size:9.5px;letter-spacing:.12em;text-transform:uppercase;color:#fda29b;animation:incst 22.4s linear infinite}
  .inc .st::before{content:"investigating"}
  .inc .m{color:#a7b6c7;margin-top:4px}
  .inc .m::before{content:"Check failed twice: no response on HTTP.";animation:incm 22.4s linear infinite}
  @keyframes incident{0%,56%{opacity:0;transform:translateY(-8px)}58%,93%{opacity:1;transform:none}95%,100%{opacity:0}}
  @keyframes incst{0%,84%{color:#fda29b}85%,100%{color:#6ce9a6}}
  @keyframes incm{0%,84%{content:"Check failed twice: no response on HTTP."}85%,100%{content:"Responded normally for three consecutive checks."}}
  .inc.ok-phase .st::before{content:"resolved"}
  @keyframes incbar{0%,84%{border-left-color:#f04438}85%,100%{border-left-color:#12b76a}}
  .inc{animation:incident 22.4s linear infinite,incbar 22.4s linear infinite}

  .days{position:relative;display:flex;gap:3px;height:30px;align-items:flex-end;margin-bottom:12px}
  .days i{flex:1;height:100%;border-radius:2px;background:#12b76a;opacity:.28}
  .days i.w{background:#f79009}.days i.b{background:#f04438}
  .days i:last-child{animation:today 22.4s linear infinite}
  @keyframes today{0%,54%{background:#12b76a}55%,84%{background:#f04438}85%,100%{background:#f79009}}
  .cap{position:relative;display:flex;align-items:center;gap:10px;font-family:var(--mono);font-size:10.5px;letter-spacing:.14em;text-transform:uppercase;color:#9fb3c8}
  .cap b{width:7px;height:7px;border-radius:50%;background:#12b76a;box-shadow:0 0 0 0 #12b76a66;animation:beat 2.4s var(--ease) infinite}
  @keyframes beat{0%{box-shadow:0 0 0 0 #12b76a66}70%{box-shadow:0 0 0 9px #12b76a00}100%{box-shadow:0 0 0 0 #12b76a00}}
  .cap .r{margin-left:auto;color:#7d8fa3;letter-spacing:.08em;text-transform:none}
  .line{position:relative;font-family:var(--sans);font-weight:800;letter-spacing:-.03em;line-height:1.05;font-size:clamp(22px,2.4vw,32px);color:#fff;margin:0 0 16px;max-width:22ch}
  .line em{font-style:normal;color:var(--light)}
  @media (max-width:860px){
    .pn{padding:14px 18px}
    .stage{inset:0 0 34px 0}.net .lbl{display:none}.inc,.line,.days{display:none}
    .cap{position:absolute;left:18px;right:18px;bottom:10px}
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
    .net .pulse,.net .ring{display:none}
    .net .dn .dot,.inc,.days i:last-child,.cap b,.au-card{animation:none}
    .inc{opacity:1;transform:none}
  }
</style>

<div class="auth">
  <aside class="pn" aria-hidden="true">
    <div class="stage">
      <div class="stage-in">
        <svg class="net" viewBox="0 0 600 600" aria-hidden="true">
  <g class="lines"><line x1="300" y1="300" x2="300.0" y2="95.0"/><line x1="300" y1="300" x2="445.0" y2="155.0"/><line x1="300" y1="300" x2="505.0" y2="300.0"/><line x1="300" y1="300" x2="445.0" y2="445.0"/><line x1="300" y1="300" x2="300.0" y2="505.0"/><line x1="300" y1="300" x2="155.0" y2="445.0"/><line x1="300" y1="300" x2="95.0" y2="300.0"/><line x1="300" y1="300" x2="155.0" y2="155.0"/></g>
  <g class="pulses"><circle class="pulse" r="3.2" cx="300" cy="300"><animateMotion dur="5.6s" begin="0.0s" repeatCount="indefinite" keyPoints="0;0.42;1" keyTimes="0;0.4;1" calcMode="linear" path="M0,0 L0.0,-205.0 L0.0,-205.0"/></circle><circle class="pulse" r="3.2" cx="300" cy="300"><animateMotion dur="5.6s" begin="0.7s" repeatCount="indefinite" keyPoints="0;0.42;1" keyTimes="0;0.4;1" calcMode="linear" path="M0,0 L145.0,-145.0 L145.0,-145.0"/></circle><circle class="pulse" r="3.2" cx="300" cy="300"><animateMotion dur="5.6s" begin="1.4s" repeatCount="indefinite" keyPoints="0;0.42;1" keyTimes="0;0.4;1" calcMode="linear" path="M0,0 L205.0,0.0 L205.0,0.0"/></circle><circle class="pulse" r="3.2" cx="300" cy="300"><animateMotion dur="5.6s" begin="2.1s" repeatCount="indefinite" keyPoints="0;0.42;1" keyTimes="0;0.4;1" calcMode="linear" path="M0,0 L145.0,145.0 L145.0,145.0"/></circle><circle class="pulse" r="3.2" cx="300" cy="300"><animateMotion dur="5.6s" begin="2.8s" repeatCount="indefinite" keyPoints="0;0.42;1" keyTimes="0;0.4;1" calcMode="linear" path="M0,0 L0.0,205.0 L0.0,205.0"/></circle><circle class="pulse" r="3.2" cx="300" cy="300"><animateMotion dur="5.6s" begin="3.5s" repeatCount="indefinite" keyPoints="0;0.42;1" keyTimes="0;0.4;1" calcMode="linear" path="M0,0 L-145.0,145.0 L-145.0,145.0"/></circle><circle class="pulse" r="3.2" cx="300" cy="300"><animateMotion dur="5.6s" begin="4.2s" repeatCount="indefinite" keyPoints="0;0.42;1" keyTimes="0;0.4;1" calcMode="linear" path="M0,0 L-205.0,0.0 L-205.0,0.0"/></circle><circle class="pulse" r="3.2" cx="300" cy="300"><animateMotion dur="5.6s" begin="4.9s" repeatCount="indefinite" keyPoints="0;0.42;1" keyTimes="0;0.4;1" calcMode="linear" path="M0,0 L-145.0,-145.0 L-145.0,-145.0"/></circle></g>
  <g class="nodes"><g class="node" style="--d:0.0s"><circle class="ring" cx="300.0" cy="95.0" r="6"/><circle class="dot" cx="300.0" cy="95.0" r="6"/></g><g class="node" style="--d:0.7s"><circle class="ring" cx="445.0" cy="155.0" r="6"/><circle class="dot" cx="445.0" cy="155.0" r="6"/></g><g class="node" style="--d:1.4s"><circle class="ring" cx="505.0" cy="300.0" r="6"/><circle class="dot" cx="505.0" cy="300.0" r="6"/></g><g class="node" style="--d:2.1s"><circle class="ring" cx="445.0" cy="445.0" r="6"/><circle class="dot" cx="445.0" cy="445.0" r="6"/></g><g class="node" style="--d:2.8s"><circle class="ring" cx="300.0" cy="505.0" r="6"/><circle class="dot" cx="300.0" cy="505.0" r="6"/></g><g class="node dn" style="--d:3.5s"><circle class="ring" cx="155.0" cy="445.0" r="6"/><circle class="dot" cx="155.0" cy="445.0" r="6"/></g><g class="node" style="--d:4.2s"><circle class="ring" cx="95.0" cy="300.0" r="6"/><circle class="dot" cx="95.0" cy="300.0" r="6"/></g><g class="node" style="--d:4.9s"><circle class="ring" cx="155.0" cy="155.0" r="6"/><circle class="dot" cx="155.0" cy="155.0" r="6"/></g></g>
  <g class="labels"><text x="300.0" y="69.0" text-anchor="middle" class="lbl">web-01<tspan class="sub"> · HTTP</tspan></text><text x="466.2" y="137.8" text-anchor="start" class="lbl">mail<tspan class="sub"> · TCP :993</tspan></text><text x="535.0" y="304.0" text-anchor="start" class="lbl">dns<tspan class="sub"> · TCP :53</tspan></text><text x="466.2" y="470.2" text-anchor="start" class="lbl">api<tspan class="sub"> · HTTP</tspan></text><text x="300.0" y="539.0" text-anchor="middle" class="lbl">backups<tspan class="sub"> · heartbeat</tspan></text><text x="133.8" y="470.2" text-anchor="end" class="lbl">web-06<tspan class="sub"> · HTTP</tspan></text><text x="65.0" y="304.0" text-anchor="end" class="lbl">db<tspan class="sub"> · TCP :3306</tspan></text><text x="133.8" y="137.8" text-anchor="end" class="lbl">cdn<tspan class="sub"> · HTTP</tspan></text></g>
  <circle class="core-ring" cx="300" cy="300" r="34"/>
</svg>
        <div class="core">
          @if ($ownLogo)
            <img src="{{ $ownLogo }}" alt="">
          @else
            {{-- no logo: the installation is a node like the others, only bigger and alive --}}
            <span class="core-dot"></span>
          @endif
        </div>
      </div>
    </div>
    <div class="inc"><b>web-06 unreachable</b><span class="st"></span><div class="m"></div></div>
    <p class="line">Every check runs. <em>Every minute.</em></p>
    <div class="days">
      @for ($i = 0; $i < 90; $i++)
        <i class="{{ $i === 23 ? 'w' : ($i === 58 ? 'b' : '') }}"></i>
      @endfor
    </div>
    <div class="cap"><b></b>all systems operational<span class="r">last 90 days</span></div>
  </aside>

  <main class="au">
    <div class="au-card">
      <div class="au-brand">
        @if ($branding->logoUrl())
          @include('partials.logo', ['size' => 34])
        @else
          <span>{{ $branding->name() }}</span>
        @endif
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
