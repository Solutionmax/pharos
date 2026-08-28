<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"
      @if (($theme ?? $branding->theme()) !== 'system') data-theme="{{ $theme ?? $branding->theme() }}" @endif>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ $branding->name() }} Status</title>
<link rel="icon" href="{{ $branding->faviconUrl() }}">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap">
@include('partials.tokens')
<style>
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--ink);font-family:var(--sans);font-size:15px;line-height:1.6;-webkit-font-smoothing:antialiased}
h1,h2,h3,h4{margin:0;font-weight:700;letter-spacing:-.02em;text-wrap:balance}
p{margin:0}a{color:inherit}
button{font:inherit;color:inherit;background:none;border:0;cursor:pointer}
:focus-visible{outline:2px solid var(--brand);outline-offset:3px;border-radius:6px}
.adminbar{background:var(--navy);color:#cfe0f0;font-size:12.5px}
.adminbar .in{max-width:900px;margin:0 auto;padding:8px 22px;display:flex;align-items:center;gap:14px}
.adminbar a{color:#fff;text-decoration:none;font-weight:600}
.adminbar a:hover{text-decoration:underline}
.wrap{max-width:900px;margin:0 auto;padding:0 22px 90px}
.top{display:flex;align-items:center;gap:14px;padding:30px 0}
.logo{display:flex;align-items:center;gap:10px;font-weight:800;font-size:17px;letter-spacing:-.025em}
.top .right{margin-left:auto;display:flex;align-items:center;gap:10px}
.sub{font-size:13.5px;font-weight:600;color:var(--brand);background:var(--brand-soft);padding:7px 15px;border-radius:999px;text-decoration:none}
.theme-toggle{display:grid;place-items:center;width:34px;height:34px;border-radius:999px;border:1px solid var(--line);color:var(--ink-3);transition:.15s var(--ease)}
.theme-toggle:hover{color:var(--ink);border-color:var(--ink-3)}
.hero{background:var(--card);border:1px solid var(--line);border-radius:var(--radius-lg);box-shadow:var(--shadow-md);overflow:hidden}
.hero-top{display:flex;align-items:center;gap:14px;padding:26px 28px;flex-wrap:wrap}
.hero-top .dot{width:12px;height:12px;border-radius:50%;flex:none;background:currentColor;position:relative}
/* The dot is alive: a ring in its own colour breathes out and fades. Off for reduced motion. */
.hero-top .dot::after{content:'';position:absolute;inset:0;border-radius:50%;background:currentColor;opacity:.55;animation:pulse 2.2s ease-out infinite}
@keyframes pulse{0%{transform:scale(1);opacity:.55}70%,100%{transform:scale(2.6);opacity:0}}
@media (prefers-reduced-motion:reduce){.hero-top .dot::after,.inc .tl-i::after{animation:none;opacity:0}}
.hero-top h1{font-size:24px}
.hero-top .when{margin-left:auto;font-family:var(--mono);font-size:12px;color:var(--ink-3)}
.uptime{padding:4px 28px 26px;display:flex;flex-direction:column;gap:12px}
.uptime.solo{padding-top:26px}
.uptime .row{display:flex;align-items:baseline;gap:12px}
.uptime .big{font-size:30px;font-weight:800;letter-spacing:-.035em;font-variant-numeric:tabular-nums}
.uptime .cap{font-size:13px;color:var(--ink-3)}
.uptime .rng{margin-left:auto;font-family:var(--mono);font-size:11px;color:var(--ink-3)}
.bar{display:flex;gap:2px;height:34px;align-items:stretch}
.bar span{flex:1;border-radius:2px;background:var(--green);opacity:.9}
.bar span:hover{opacity:1}
.bar span.w{background:var(--amber)}.bar span.p{background:var(--orange)}
.bar span.b{background:var(--red)}.bar span.m{background:var(--blue)}
.bar span.unknown{background:var(--unknown)}
.bar.mini{height:18px;gap:1.5px;width:150px;flex:none}
.bar.mini span{border-radius:1px}
@media(max-width:640px){.bar.mini{display:none}}
.scale{display:flex;justify-content:space-between;font-family:var(--mono);font-size:10.5px;color:var(--ink-3)}
.sec{margin-top:34px}
.sec>h2{font-size:13px;font-weight:700;color:var(--ink-2);margin-bottom:12px}
.svc{background:var(--card);border:1px solid var(--line);border-radius:var(--radius);overflow:hidden}
.svc+.svc{margin-top:10px}
details>summary{list-style:none;cursor:pointer}
details>summary::-webkit-details-marker{display:none}
.svc-hd{display:flex;align-items:center;gap:14px;padding:16px 20px}
.svc-hd:hover{background:var(--bg-tint)}
.svc-hd .car{color:var(--ink-3);font-size:11px;transition:transform .2s var(--ease);flex:none}
details[open] .svc-hd .car{transform:rotate(90deg)}
.svc-hd .nm{font-weight:600;font-size:15px}
.svc-hd .cnt{font-size:12.5px;color:var(--ink-3)}
.svc-hd .pct{font-family:var(--mono);font-size:12px;color:var(--ink-3);font-variant-numeric:tabular-nums;margin-left:auto}
.svc-bd{border-top:1px solid var(--line);background:var(--bg-tint)}
.item{display:flex;align-items:center;gap:14px;padding:11px 20px 11px 45px}
.item+.item{border-top:1px solid var(--line)}
.item .nm{font-size:13.5px}
.item .desc{font-size:11.5px;color:var(--ink-3)}
.item .pct{font-family:var(--mono);font-size:11.5px;color:var(--ink-3);margin-left:auto;font-variant-numeric:tabular-nums}
.item .bar.mini{width:110px;height:14px}
.pill{font-size:11.5px;font-weight:700;padding:4px 11px;border-radius:999px;white-space:nowrap}
.pill.sm{font-size:10.5px;padding:3px 9px}
.pill.ok{color:var(--green-ink);background:var(--green-soft)}
.pill.w{color:var(--amber-ink);background:var(--amber-soft)}
.pill.p{color:var(--orange-ink);background:var(--orange-soft)}
.pill.b{color:var(--red-ink);background:var(--red-soft)}
.pill.m{color:var(--blue-ink);background:var(--blue-soft)}
.plan{display:flex;align-items:center;gap:13px;padding:14px 20px;margin-top:12px;border-radius:var(--radius);background:var(--blue-soft);border:1px solid var(--line)}
.plan .tx{font-size:13.5px;color:var(--ink-2)}
.plan .tx b{color:var(--ink);font-weight:600}
.plan time{margin-left:auto;font-family:var(--mono);font-size:11.5px;color:var(--ink-3);white-space:nowrap}
.day+.day{margin-top:8px}
.day-hd{display:flex;align-items:center;gap:12px;padding:14px 2px 10px}
.day-hd h3{font-size:13px;font-weight:700;color:var(--ink-2)}
.day-hd .ln{flex:1;height:1px;background:var(--line)}
.day-hd .none{font-size:12.5px;color:var(--ink-3)}
.inc{background:var(--card);border:1px solid var(--line);border-left:3px solid var(--ink-3);border-radius:var(--radius);padding:20px 22px;box-shadow:var(--shadow-sm)}
.inc+.inc{margin-top:10px}
.inc.ok{border-left-color:var(--green)}.inc.w{border-left-color:var(--amber)}
.inc.p{border-left-color:var(--orange)}.inc.b{border-left-color:var(--red)}.inc.m{border-left-color:var(--blue)}
.inc-hd{display:flex;align-items:center;gap:11px;flex-wrap:wrap;margin-bottom:6px}
.inc-hd h4{font-size:16px}
.inc .aff{font-size:12.5px;color:var(--ink-3);margin-bottom:16px}
.inc .aff b{color:var(--ink-2);font-weight:500}
.tl{display:flex;flex-direction:column;gap:15px;padding-left:18px;border-left:2px solid var(--line);margin-left:3px}
.tl-i{position:relative}
.tl-i::before{content:"";position:absolute;left:-24px;top:6px;width:9px;height:9px;border-radius:50%;background:var(--card);border:2px solid var(--line)}
.tl-i:first-child::before{background:var(--brand);border-color:var(--brand)}
/* The newest update of an open incident is the live one: same breathing ring as the hero dot. Resolved stays still. */
.inc:not(.ok) .tl-i:first-child::after{content:"";position:absolute;left:-24px;top:6px;width:9px;height:9px;border-radius:50%;background:var(--brand);opacity:.55;animation:pulse 2.2s ease-out infinite}
.tl-i .hd{display:flex;align-items:baseline;gap:10px;flex-wrap:wrap}
.tl-i strong{font-size:13px}
.tl-i time{font-family:var(--mono);font-size:11px;color:var(--ink-3)}
.tl-i p{font-size:13.5px;color:var(--ink-2);line-height:1.65;margin-top:2px}
.auto{font-family:var(--mono);font-size:9.5px;letter-spacing:.09em;text-transform:uppercase;color:var(--ink-3);border:1px solid var(--line);border-radius:5px;padding:1px 6px}
.foot{display:flex;gap:18px;flex-wrap:wrap;margin-top:44px;padding-top:20px;border-top:1px solid var(--line);font-size:12.5px;color:var(--ink-3)}
.foot a{text-decoration:none}
.foot .cr{margin-left:auto;text-decoration:none;color:inherit}
.foot .cr:hover{color:var(--accent);text-decoration:underline}

    .md > :first-child{margin-top:0}
    .md > :last-child{margin-bottom:0}
    .md p{margin:0 0 8px}
    .md ul,.md ol{margin:0 0 8px;padding-left:20px}
    .md li{margin:2px 0}
    .md a{color:var(--brand)}
    .md code{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:.92em;
      background:rgba(127,127,127,.12);padding:1px 5px;border-radius:4px}
</style>
</head>
<body>
@if (($chrome ?? true) && auth()->check())
<div class="adminbar">
  <div class="in">
    <span>Signed in as {{ auth()->user()->name }}</span>
    <a href="{{ route('admin.components') }}" style="margin-left:auto">← Back to admin</a>
  </div>
</div>
@endif

<div class="wrap">
  <header class="top">
    <span class="logo">@include('partials.logo', ['size' => 30])</span>
    <span class="right">
      @include('partials.theme-toggle')
    </span>
  </header>
  @include('partials.theme-script')

  @if ($modules['page.show_overall'] || $modules['page.show_uptime'])
    <section class="hero">
      @if ($modules['page.show_overall'])
        <div class="hero-top">
          <span class="dot" style="color:var(--{{ ['ok' => 'green', 'w' => 'amber', 'p' => 'orange', 'b' => 'red', 'm' => 'blue'][$worst->tone()] }})"></span>
          <h1>{{ $worst === \App\Enums\ComponentStatus::Operational ? 'All systems operational' : $worst->label() }}</h1>
          <span class="when">updated {{ \App\Services\Clock::now()->format('H:i') }}</span>
        </div>
      @endif

      @if ($modules['page.show_uptime'])
        <div class="uptime @unless($modules['page.show_overall']) solo @endunless">
          <div class="row">
            <span class="big">{{ number_format($overall, 2) }}<span style="font-size:19px">%</span></span>
            <span class="cap">uptime</span>
            <span class="rng">last {{ \App\Services\Uptime::WINDOW_DAYS }} days</span>
          </div>
          @php
            $overallBar = [];
            for ($i = 0; $i < \App\Services\Uptime::WINDOW_DAYS; $i++) {
                $tones = collect($bars)->map(fn ($b) => $b[$i]['tone'])->all();
                $overallBar[] = in_array('b', $tones, true) ? 'b'
                    : (in_array('p', $tones, true) ? 'p'
                    : (in_array('w', $tones, true) ? 'w'
                    : (count(array_filter($tones, fn ($t) => $t !== 'unknown')) ? '' : 'unknown')));
            }
          @endphp
          <div class="bar" aria-label="Daily availability over the last {{ \App\Services\Uptime::WINDOW_DAYS }} days">
            @foreach ($overallBar as $tone)<span class="{{ $tone }}"></span>@endforeach
          </div>
          <div class="scale"><span>{{ \App\Services\Uptime::WINDOW_DAYS }} days ago</span><span>today</span></div>
        </div>
      @endif
    </section>
  @endif

  @if ($modules['page.show_services'] && ($groups->isNotEmpty() || $loose->isNotEmpty()))
    <section class="sec">
      <h2>Services</h2>

      {{-- Components that belong to no service still belong on the page. Leaving
           them out made "Ungrouped" a silent way to publish nothing, and made
           deleting a service quietly empty the page it was on. --}}
      @if ($loose->isNotEmpty())
        <div class="svc">
          <div class="svc-bd">
            @foreach ($loose as $component)
              @include('partials.status-item', ['component' => $component])
            @endforeach
          </div>
        </div>
      @endif

      @foreach ($groups as $group)
        @continue($group->components->isEmpty())
        @php $gs = $group->status(); @endphp
        <details class="svc" @if(!$group->collapsed || $gs->isDown()) open @endif>
          <summary>
            <div class="svc-hd">
              <span class="car" aria-hidden="true">&#9654;</span>
              <span class="nm">{{ $group->name }}</span>
              <span class="cnt">{{ $group->components->count() }} {{ \Illuminate\Support\Str::plural('component', $group->components->count()) }}</span>
              @if ($modules['page.show_component_uptime'])
                @php $groupPct = round($group->components->avg(fn ($c) => $percentages[$c->id] ?? 100), 2); @endphp
                <span class="pct">{{ number_format($groupPct, 2) }}%</span>
              @endif
              <span class="pill {{ $gs->tone() }}">{{ $gs->label() }}</span>
            </div>
          </summary>
          <div class="svc-bd">
            @foreach ($group->components as $component)
              @include('partials.status-item', ['component' => $component])
            @endforeach
          </div>
        </details>
      @endforeach
    </section>
  @endif

  @if ($modules['page.show_incidents'])
    <section class="sec">
      <h2>Incidents</h2>
      @forelse ($days as $date => $incidents)
        @php $carbon = \Illuminate\Support\Carbon::parse($date, \App\Services\Clock::timezone()); @endphp
        <div class="day">
          <div class="day-hd">
            <h3>{{ $carbon->isToday() ? 'Today · '.$carbon->format('j F') : $carbon->format('j F') }}</h3>
            <span class="ln"></span>
            @if ($incidents->isEmpty())<span class="none">No incidents</span>@endif
          </div>
          @foreach ($incidents as $incident)
            @php
              $tone = $incident->status === \App\Enums\IncidentStatus::Resolved
                  ? 'ok'
                  : (($incident->components->max('pivot.status') ?? 0) >= 4 ? 'b' : 'p');
            @endphp
            <article class="inc {{ $tone }}">
              <div class="inc-hd">
                <h4>{{ $incident->name }}</h4>
                <span class="pill {{ $tone }}">{{ $incident->status->label() }}</span>
              </div>
              @if ($incident->components->isNotEmpty())
                <p class="aff">
                  {{ $incident->isOpen() ? 'Affects' : 'Affected' }}
                  <b>{{ $incident->components->pluck('name')->join(', ', ' and ') }}</b>
                </p>
              @endif
              <div class="tl">
                @foreach ($incident->updates as $update)
                  <div class="tl-i">
                    <span class="hd">
                      <strong>{{ $update->status->label() }}</strong>
                      <time>{{ $update->created_at->format('H:i') }}</time>
                      @if ($update->automatic)<span class="auto">automatic</span>@endif
                    </span>
                    <div class="md">{!! $update->messageHtml() !!}</div>
                  </div>
                @endforeach
              </div>
            </article>
          @endforeach
        </div>
      @empty
        <div class="day"><div class="day-hd"><h3>No incidents reported</h3><span class="ln"></span></div></div>
      @endforelse
    </section>
  @endif

  <footer class="foot">
    @if ($modules['page.show_api_link'])<a href="{{ url('/api/v1/components') }}">API</a>@endif
    @unless ($branding->creditHidden())<a class="cr" href="https://pharos.solutionmax.net" rel="noopener">Powered by Pharos</a>@endunless
  </footer>
</div>
</body>
</html>
