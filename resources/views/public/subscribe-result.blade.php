{{-- The page behind a confirmation or unsubscribe link. Same tokens and type as
     the status page, with only the rules this one card needs. --}}
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"
      @if ($branding->theme() !== 'system') data-theme="{{ $branding->theme() }}" @endif>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex">
<title>{{ $outcome === 'subscribed' ? "You're subscribed" : 'Unsubscribed' }} · {{ $branding->name() }} Status</title>
<link rel="icon" href="{{ $branding->faviconUrl() }}">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap">
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
      <p><span class="mail">{{ $subscriber->email }}</span> will get an e-mail when an incident is
        reported on the {{ $branding->name() }} status page, and when it is resolved.</p>
      <a class="btn" href="{{ route('status') }}">Back to the status page</a>
      <p class="small">Changed your mind? <a href="{{ $subscriber->unsubscribeUrl() }}">Unsubscribe</a> — the same link sits at the bottom of every mail.</p>
    @else
      <h1>Unsubscribed</h1>
      <p><span class="mail">{{ $subscriber->email }}</span> will get no more incident e-mails from
        the {{ $branding->name() }} status page.</p>
      <a class="btn" href="{{ route('status') }}">Back to the status page</a>
      <p class="small">Subscribed by mistake? Use "Get notified" on the status page and confirm again.</p>
    @endif
  </section>
</div>
</body>
</html>
