@extends('mail.layout')
@section('body')
<p style="margin:0 0 4px;font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:#667085">{{ $status }}</p>
<p style="margin:0 0 14px;font-size:20px;font-weight:700;letter-spacing:-.02em">{{ $name }}</p>
@if ($components !== [])
  <p style="margin:0 0 14px;font-size:13px;color:#475467">Affects <b style="color:#0e1726;font-weight:600">{{ implode(', ', $components) }}</b></p>
@endif
<div style="margin:0 0 6px;padding:14px 16px;border-left:3px solid {{ $accent }};background:#f2f6fb;border-radius:0 10px 10px 0">{!! $body !!}</div>
<p style="margin:0 0 22px;font-size:12px;color:#667085">{{ $when->format('j F Y, H:i') }}</p>
<p style="margin:0">
  <a href="{{ $link }}" style="display:inline-block;background:{{ $accent }};color:#ffffff;text-decoration:none;font-weight:600;padding:11px 20px;border-radius:10px">View status page</a>
</p>
@endsection
