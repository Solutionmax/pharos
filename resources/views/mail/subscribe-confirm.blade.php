@extends('mail.layout')
@section('body')
<p style="margin:0 0 14px;font-size:20px;font-weight:700;letter-spacing:-.02em">Confirm your subscription</p>
<p style="margin:0 0 18px">You asked to be told about incidents on the {{ $brand }} status page.
  Confirm the address and we will mail you when something happens — and when it is fixed.</p>
<p style="margin:0 0 22px">
  <a href="{{ $confirm }}" style="display:inline-block;background:{{ $accent }};color:#ffffff;text-decoration:none;font-weight:600;padding:11px 20px;border-radius:10px">Confirm subscription</a>
</p>
<p style="margin:0;font-size:13px;color:#667085">The link is good for {{ $hours }} hours. If you did not ask for this, ignore this mail; the address is forgotten on its own.</p>
@endsection
