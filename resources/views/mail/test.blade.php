@extends('mail.layout')
@section('body')
<p style="margin:0 0 14px;font-size:20px;font-weight:700;letter-spacing:-.02em">Mail works</p>
<p style="margin:0 0 14px">Hi {{ $user->name }}, this is the test message from {{ $source }} on {{ $brand }}.
  It went out through {{ $transport }}, so subscriber notifications sent the same way will arrive too.</p>
<p style="margin:0;font-size:13px;color:#667085">Nothing else to do.</p>
@endsection
