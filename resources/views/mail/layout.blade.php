{{-- One frame for every mail. Tables and inline styles on purpose: mail clients
     ignore most of what a browser honours. The accent colour is the only thing
     that changes per install; the logo is optional. --}}
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ $brand }}</title>
</head>
<body style="margin:0;padding:0;background:#f2f6fb;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#0e1726">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f2f6fb;padding:28px 12px">
  <tr><td align="center">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border:1px solid #e8edf4;{{ ($tone ?? null) ? 'border-left:4px solid '.$tone.';' : '' }}border-radius:14px">
      <tr><td style="padding:22px 28px 6px">
        @if ($logo)
          <img src="{{ $logo }}" alt="{{ $brand }}" style="max-height:32px;max-width:200px;display:block">
        @else
          <span style="font-size:17px;font-weight:800;letter-spacing:-.02em;color:{{ $accent }}">{{ $brand }}</span>
        @endif
      </td></tr>
      <tr><td style="padding:12px 28px 24px;font-size:15px;line-height:1.6">
        @yield('body')
      </td></tr>
      <tr><td style="padding:14px 28px 22px;border-top:1px solid #e8edf4;font-size:12px;line-height:1.6;color:#667085">
        <a href="{{ $link }}" style="color:#667085">{{ $brand }} status page</a>
        @isset($unsubscribe)
          &nbsp;·&nbsp; <a href="{{ $unsubscribe }}" style="color:#667085">Unsubscribe</a>
        @endisset
      </td></tr>
    </table>
  </td></tr>
</table>
</body>
</html>
