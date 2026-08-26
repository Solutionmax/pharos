@php $branding = app(\App\Services\Branding::class); @endphp
<!doctype html>
<html lang="en" @if ($branding->theme() !== 'system') data-theme="{{ $branding->theme() }}" @endif>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>@yield('title', 'Admin') · {{ $branding->name() }}</title>
<link rel="icon" href="{{ $branding->faviconUrl() }}">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap">
@include('partials.tokens')
<style>
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--ink);font-family:var(--sans);font-size:15px;line-height:1.6;-webkit-font-smoothing:antialiased}
h1,h2,h3,h4{margin:0;font-weight:700;letter-spacing:-.02em}
p{margin:0}a{color:inherit}
button{font:inherit;color:inherit;background:none;border:0;cursor:pointer}
:focus-visible{outline:2px solid var(--brand);outline-offset:3px;border-radius:6px}
.mono{font-family:var(--mono)}

/* ---------- chrome ---------- */
.shell{display:grid;grid-template-columns:248px minmax(0,1fr);min-height:100vh}
@media(max-width:900px){.shell{grid-template-columns:1fr}}
.side{background:var(--card);border-right:1px solid var(--line);padding:18px 12px;display:flex;flex-direction:column;gap:2px;position:sticky;top:0;height:100vh}
@media(max-width:900px){.side{position:static;height:auto;border-right:0;border-bottom:1px solid var(--line)}}
.side .brand{display:flex;align-items:center;gap:9px;font-weight:800;font-size:16px;letter-spacing:-.025em;padding:8px 10px 20px}
.side .lbl{font-family:var(--mono);font-size:9.5px;letter-spacing:.15em;text-transform:uppercase;color:var(--ink-3);padding:16px 12px 7px}
.nav{position:relative;display:flex;align-items:center;gap:11px;padding:9px 12px;border-radius:10px;font-size:14px;font-weight:500;color:var(--ink-2);text-decoration:none;transition:background .14s var(--ease),color .14s var(--ease)}
.nav svg{flex:none;opacity:.75;transition:opacity .14s}
.nav:hover{background:var(--bg-tint);color:var(--ink)}
.nav:hover svg{opacity:1}
.nav[aria-current="page"]{background:var(--brand-soft);color:var(--brand);font-weight:600}
.nav[aria-current="page"] svg{opacity:1}
.nav[aria-current="page"]::before{content:"";position:absolute;left:-12px;top:9px;bottom:9px;width:3px;border-radius:0 3px 3px 0;background:var(--brand)}
.side .bottom{margin-top:auto;display:flex;flex-direction:column;gap:2px;padding-top:12px;border-top:1px solid var(--line)}
.dot-new{width:7px;height:7px;border-radius:50%;background:var(--amber);margin-left:auto;flex:none}
.whorow{display:flex;align-items:center;gap:10px;padding:10px 12px}
.avatar{width:28px;height:28px;border-radius:50%;background:var(--brand-soft);color:var(--brand);display:grid;place-items:center;font-size:12.5px;font-weight:700;flex:none}
.whotext{display:flex;flex-direction:column;min-width:0;line-height:1.3}
.whotext strong{font-size:13px;font-weight:600}
.whotext span{font-size:11.5px;color:var(--ink-3);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.main{min-width:0;padding:28px 28px 90px}
@media(max-width:900px){.main{padding:22px 18px 70px}}
.head{display:flex;align-items:flex-start;gap:14px;margin-bottom:22px;flex-wrap:wrap}
.head h1{font-size:24px;letter-spacing:-.03em}
.head .sub{font-size:13.5px;color:var(--ink-3)}
.backlink{display:inline-block;font-size:12.5px;font-weight:600;color:var(--ink-3);text-decoration:none;margin-bottom:3px}
.backlink:hover{color:var(--brand)}
.head .act{margin-left:auto;display:flex;gap:9px;align-items:center;padding-top:4px}
.theme-toggle{display:grid;place-items:center;width:36px;height:36px;border-radius:10px;border:1px solid var(--line);color:var(--ink-3);flex:none;transition:.15s var(--ease)}
.theme-toggle:hover{color:var(--ink);border-color:var(--ink-3);background:var(--card)}

/* ---------- stat tiles: a number, not a chart ---------- */
.tiles{display:grid;grid-template-columns:repeat(auto-fit,minmax(168px,1fr));gap:12px;margin-bottom:16px}
.tile{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:15px 17px;display:flex;flex-direction:column;gap:3px;box-shadow:var(--shadow-sm)}
.tile .k{font-family:var(--mono);font-size:9.5px;letter-spacing:.14em;text-transform:uppercase;color:var(--ink-3)}
.tile .v{font-size:26px;font-weight:800;letter-spacing:-.035em;font-variant-numeric:tabular-nums;line-height:1.15}
.tile .n{font-size:12px;color:var(--ink-3)}
.tile.good .v{color:var(--green-ink)}
.tile.warn .v{color:var(--amber-ink)}
.tile.bad .v{color:var(--red-ink)}

/* ---------- status: never colour alone ---------- */
.state-cell{display:flex;align-items:center;gap:8px;white-space:nowrap}
.state-dot{width:8px;height:8px;border-radius:50%;flex:none}
.state-dot.ok{background:var(--green)}.state-dot.w{background:var(--amber)}
.state-dot.p{background:var(--orange)}.state-dot.b{background:var(--red)}
.state-dot.m{background:var(--blue)}
.state-cell .txt{font-size:13px;font-weight:600}

/* ---------- 30-day strip, read next to its own number ---------- */
.strip{display:flex;gap:1.5px;height:20px;align-items:stretch;width:132px}
.strip span{flex:1;border-radius:1.5px;background:var(--green);opacity:.85}
.strip span.w{background:var(--amber)}.strip span.p{background:var(--orange)}
.strip span.b{background:var(--red)}.strip span.m{background:var(--blue)}
.strip span.unknown{background:var(--unknown)}
@media(max-width:900px){.strip{display:none}}

/* ---------- pieces ---------- */
.btn{background:var(--brand);color:var(--brand-ink);font-weight:600;font-size:13.5px;padding:9px 18px;border-radius:10px;display:inline-block;text-decoration:none;border:1px solid transparent;white-space:nowrap;box-shadow:var(--shadow-sm);transition:.15s var(--ease)}
.btn:hover{filter:brightness(1.07);transform:translateY(-1px)}
.btn:active{transform:none}
@media (prefers-reduced-motion:reduce){.btn:hover{transform:none}}
.btn.ghost{background:none;color:var(--ink-2);border-color:var(--line)}
.btn.ghost:hover{background:var(--bg-tint);color:var(--ink)}
.flash{background:var(--green-soft);color:var(--green-ink);border-radius:12px;padding:12px 16px;margin-bottom:18px;font-size:13.5px;font-weight:600}
.errors{background:var(--red-soft);color:var(--red-ink);border-radius:12px;padding:12px 16px;margin-bottom:18px;font-size:13.5px}
.errors ul{margin:0;padding-left:18px}
.panel{background:var(--card);border:1px solid var(--line);border-radius:var(--radius);box-shadow:var(--shadow-sm);overflow:hidden}
.panel-hd h3::before{content:"";display:inline-block;width:3px;height:13px;border-radius:2px;background:var(--brand);margin-right:9px;vertical-align:-1px}
.panel+.panel{margin-top:16px}
.panel-hd{display:flex;align-items:center;gap:12px;padding:15px 20px;border-bottom:1px solid var(--line);flex-wrap:wrap}
.panel-hd h3{font-size:14.5px}
.panel-hd .hint{margin-left:auto;font-size:12.5px;color:var(--ink-3)}
.panel-bd{padding:20px;display:flex;flex-direction:column;gap:18px}
.scroll{overflow-x:auto}
table{width:100%;border-collapse:collapse;font-size:14px}
th{position:sticky;top:0;z-index:1;background:var(--card);text-align:left;font-family:var(--mono);font-size:9.5px;letter-spacing:.13em;text-transform:uppercase;color:var(--ink-3);font-weight:500;padding:15px 20px 11px;white-space:nowrap;border-bottom:1px solid var(--line)}
td{padding:14px 20px;border-top:1px solid var(--line);vertical-align:middle}
tbody tr:first-child td{border-top:0}
tbody tr{transition:background .12s var(--ease)}
tbody tr:hover{background:var(--bg-tint)}
td .sub{font-size:12px;color:var(--ink-3)}
td.num{font-family:var(--mono);font-variant-numeric:tabular-nums;color:var(--ink-2);font-size:13px}
.pill{font-size:11.5px;font-weight:700;padding:4px 11px;border-radius:999px;white-space:nowrap;display:inline-block}
.pill.ok{color:var(--green-ink);background:var(--green-soft)}
.pill.w{color:var(--amber-ink);background:var(--amber-soft)}
.pill.p{color:var(--orange-ink);background:var(--orange-soft)}
.pill.b{color:var(--red-ink);background:var(--red-soft)}
.pill.m{color:var(--blue-ink);background:var(--blue-soft)}
.src{font-family:var(--mono);font-size:9.5px;letter-spacing:.09em;text-transform:uppercase;color:var(--ink-3);border:1px solid var(--line);border-radius:5px;padding:2px 7px}
.fields{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:16px}
.field{display:flex;flex-direction:column;gap:7px}
.field.wide{grid-column:1/-1}
.field label{font-size:12.5px;font-weight:600;color:var(--ink-2)}
.field .help{font-size:12px;color:var(--ink-3)}
input[type=text],input[type=email],input[type=password],input[type=url],input[type=color],input[type=number],input[type=datetime-local],input[type=file],select,textarea{
  font:inherit;font-size:14px;padding:10px 13px;border-radius:10px;border:1px solid var(--line);background:var(--bg-tint);color:var(--ink);width:100%}
input[type=color]{height:40px;padding:3px;cursor:pointer}
input[type=file]{padding:8px 10px;font-size:13px}
textarea{resize:vertical;line-height:1.6}
.check{display:flex;align-items:center;gap:10px;font-size:13.5px}
.check input{width:16px;height:16px;accent-color:var(--brand);flex:none}
.switchrow{display:flex;align-items:center;gap:12px;padding:12px 14px;border:1px solid var(--line);border-radius:10px;background:var(--bg-tint);flex-wrap:wrap}
.switchrow .t{display:flex;flex-direction:column;min-width:0}
.switchrow strong{font-size:13.5px}
.switchrow .s{font-size:12.5px;color:var(--ink-3)}
.switchrow .check{margin-left:auto}
.modules{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:10px}
.locked{border:1px dashed var(--line);background:var(--bg-tint);border-radius:12px;padding:16px;display:flex;flex-direction:column;gap:12px}
.pro{font-family:var(--mono);font-size:9.5px;letter-spacing:.1em;text-transform:uppercase;color:var(--brand);border:1px solid var(--brand);border-radius:6px;padding:2px 8px;white-space:nowrap}
.callout{border-left:3px solid var(--brand);background:var(--brand-soft);padding:14px 17px;border-radius:0 12px 12px 0;font-size:13.5px;line-height:1.65;color:var(--ink-2)}
.callout b{color:var(--ink)}
.filters{display:flex;gap:10px;padding:14px 20px;border-bottom:1px solid var(--line);background:var(--bg-tint);flex-wrap:wrap;align-items:center}
.filters input[type=text]{flex:1;min-width:200px;background:var(--card)}
.seg{display:flex;border:1px solid var(--line);border-radius:10px;overflow:hidden;background:var(--card)}
.seg a{padding:8px 14px;font-size:12.5px;font-weight:600;color:var(--ink-3);text-decoration:none;border-right:1px solid var(--line)}
.seg a:last-child{border-right:0}
.seg a[aria-current="page"]{background:var(--brand-soft);color:var(--brand)}
.empty{padding:52px 24px;display:flex;flex-direction:column;align-items:center;gap:9px;color:var(--ink-3);font-size:13.5px;text-align:center}
.empty svg{color:var(--ink-3);opacity:.5;margin-bottom:2px}
.empty b{color:var(--ink);font-weight:600;font-size:14.5px}
.empty .btn{margin-top:8px}
.actions{display:flex;gap:9px;flex-wrap:wrap}
.rowacts{display:flex;gap:7px;justify-content:flex-end}
.rowacts a,.rowacts button{font-size:12.5px;font-weight:600;color:var(--ink-2);border:1px solid var(--line);border-radius:8px;padding:5px 11px;text-decoration:none;transition:.13s var(--ease)}
.rowacts a:hover{background:var(--bg-tint);color:var(--ink)}
.rowacts button:hover{color:var(--red-ink);border-color:var(--red)}
.copy{display:flex;gap:8px;align-items:center}
.copy code{flex:1;font-family:var(--mono);font-size:12px;background:var(--bg-tint);border:1px solid var(--line);border-radius:8px;padding:9px 12px;overflow-x:auto;white-space:nowrap;color:var(--ink-2)}
pre{margin:0;background:var(--bg-tint);border:1px solid var(--line);border-radius:12px;padding:16px 18px;overflow-x:auto;font-family:var(--mono);font-size:12.5px;line-height:1.7;color:var(--ink-2)}
pre .k{color:var(--brand)}
</style>
</head>
<body>
@auth
<div class="shell">
  <aside class="side">
    <span class="brand">@include('partials.logo', ['size' => 26])</span>

    <span class="lbl">Status page</span>
    <a class="nav" href="{{ route('admin.groups') }}" @if(request()->routeIs('admin.groups*')) aria-current="page" @endif>
      @include('partials.icon', ['name' => 'services']) Services
    </a>
    <a class="nav" href="{{ route('admin.components') }}" @if(request()->routeIs('admin.component*')) aria-current="page" @endif>
      @include('partials.icon', ['name' => 'components']) Components
    </a>
    <a class="nav" href="{{ route('admin.incidents') }}" @if(request()->routeIs('admin.incident*')) aria-current="page" @endif>
      @include('partials.icon', ['name' => 'incidents']) Incidents
    </a>

    <span class="lbl">Configuration</span>
    <a class="nav" href="{{ route('admin.settings') }}" @if(request()->routeIs('admin.settings')) aria-current="page" @endif>
      @include('partials.icon', ['name' => 'settings']) Settings
    </a>
    <a class="nav" href="{{ route('admin.integrations') }}" @if(request()->routeIs('admin.integrations')) aria-current="page" @endif>
      @include('partials.icon', ['name' => 'integrations']) Integrations
    </a>
    <a class="nav" href="{{ route('admin.branding') }}" @if(request()->routeIs('admin.branding')) aria-current="page" @endif>
      @include('partials.icon', ['name' => 'branding']) Branding
    </a>
    <a class="nav" href="{{ route('admin.users') }}" @if(request()->routeIs('admin.users')) aria-current="page" @endif>
      @include('partials.icon', ['name' => 'users']) Users
    </a>
    <a class="nav" href="{{ route('admin.updates') }}" @if(request()->routeIs('admin.updates')) aria-current="page" @endif>
      @include('partials.icon', ['name' => 'update']) Updates
      @if (app(\App\Services\Updater::class)->updateAvailable())<span class="dot-new" aria-label="Update available"></span>@endif
    </a>

    <span class="bottom">
      <a class="nav" href="{{ route('status') }}">@include('partials.icon', ['name' => 'external']) View status page</a>
      <span class="whorow">
        <span class="avatar" aria-hidden="true">{{ mb_substr(auth()->user()->name, 0, 1) }}</span>
        <span class="whotext">
          <strong>{{ auth()->user()->name }}</strong>
          <span>{{ auth()->user()->email }}</span>
        </span>
      </span>
      <form method="POST" action="{{ route('admin.logout') }}">@csrf
        <button class="nav" type="submit" style="width:100%">@include('partials.icon', ['name' => 'signout']) Sign out</button>
      </form>
    </span>
  </aside>

  <main class="main">
    @if (session('status'))<div class="flash">{{ session('status') }}</div>@endif
    @if ($errors->any())<div class="errors"><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    @yield('content')
  </main>
</div>
@else
<div class="main" style="max-width:420px;margin:0 auto">
  @if (session('status'))<div class="flash">{{ session('status') }}</div>@endif
  @if ($errors->any())<div class="errors"><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
  @yield('content')
</div>
@endauth
@include('partials.theme-script')
</body>
</html>
