{{-- The page behind a confirmation or unsubscribe link. Same tokens and type as
     the status page, with only the rules this one card needs. Opening an
     unsubscribe link only asks (confirm-unsubscribe); the button does it. --}}
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"
      @if ($branding->theme() !== 'system') data-theme="{{ $branding->theme() }}" @endif>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex">
<title>{{ ['subscribed' => "You're subscribed", 'confirm-unsubscribe' => 'Unsubscribe'][$outcome] ?? 'Unsubscribed' }} · {{ $branding->name() }} Status</title>
<link rel="icon" href="{{ $branding->faviconUrl() }}">
@if ($branding->name() === 'Pharos' && ! $branding->logoUrl())
<link rel="apple-touch-icon" href="{{ $branding->builtInAssetUrl('apple-touch-icon.png') }}">
@endif
<link rel="stylesheet" href="{{ asset('fonts/fonts.css') }}">
@include('partials.tokens')
<style>
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--ink);font-family:var(--sans);font-size:15px;line-height:1.6;-webkit-font-smoothing:antialiased}
h1{margin:0;font-weight:700;letter-spacing:-.02em;font-size:24px}
p{margin:0}a{color:inherit}
.wrap{max-width:560px;margin:0 auto;padding:0 22px 90px}
.top{display:flex;align-items:center;gap:14px;padding:30px 0}
.logo{display:flex;align-items:center;gap:10px;font-weight:800;font-size:17px;letter-spacing:-.025em}
.card{background:var(--card);border:1px solid var(--line);border-radius:var(--radius-lg);box-shadow:var(--shadow-md);padding:28px;display:flex;flex-direction:column;gap:12px}
.card p{color:var(--ink-2)}
.card .mail{font-weight:600;color:var(--ink)}
.btn{display:inline-block;margin-top:6px;background:var(--brand);color:var(--brand-ink);font-weight:600;font-size:13.5px;padding:10px 18px;border-radius:10px;text-decoration:none;align-self:flex-start}
button.btn{border:0;font-family:inherit;line-height:inherit;cursor:pointer}
.btn:hover{filter:brightness(1.08)}
.btn:focus-visible{outline:2px solid var(--brand);outline-offset:3px}
form{margin:0;display:flex}
.small{font-size:12.5px;color:var(--ink-3)}
.small a{color:var(--ink-3)}
</style>
</head>
<body>
<div class="wrap">
  <header class="top">
    <span class="logo">@include('partials.logo', ['size' => 30])</span>
  </header>
  <section class="card">
    @if ($outcome === 'subscribed')
      <h1>You're subscribed</h1>
      <p><span class="mail">{{ $subscriber->email }}</span> will get an email when an incident is
        reported on the {{ $branding->name() }} status page, and when it is resolved.</p>
      <a class="btn" href="{{ \App\Services\PageUrls::route('status') }}">Back to the status page</a>
      <p class="small">Changed your mind? <a href="{{ $subscriber->unsubscribeUrl() }}">Unsubscribe</a>. The same link sits at the bottom of every mail.</p>
    @elseif ($outcome === 'confirm-unsubscribe')
      <h1>Unsubscribe from {{ $branding->name() }} status updates?</h1>
      <p><span class="mail">{{ $subscriber->email }}</span> gets an email when an incident is reported
        on the {{ $branding->name() }} status page, and when it is resolved. Unsubscribe to stop them.</p>
      <form method="post" action="{{ $action }}">
        <button class="btn" type="submit">Unsubscribe</button>
      </form>
      <p class="small">Opened this link by mistake? Nothing changes until you press the button. <a href="{{ \App\Services\PageUrls::route('status') }}">Back to the status page</a>.</p>
    @else
      <h1>Unsubscribed</h1>
      <p><span class="mail">{{ $subscriber->email }}</span> will get no more incident emails from
        the {{ $branding->name() }} status page.</p>
      <a class="btn" href="{{ \App\Services\PageUrls::route('status') }}">Back to the status page</a>
      <p class="small">Subscribed by mistake? Use "Get notified" on the status page and confirm again.</p>
    @endif
  </section>
</div>
</body>
</html>
