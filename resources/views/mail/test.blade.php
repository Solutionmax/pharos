@extends('mail.layout')
@section('body')
<p style="margin:0 0 14px;font-size:20px;font-weight:700;letter-spacing:-.02em">{{ __('Mail works') }}</p>
<p style="margin:0 0 14px">{{ __('Hi') }} {{ $user->name }}{{ __(', this is the test message from') }} {{ $source }} {{ __('on') }} {{ $brand }}{{ __('.
  It went out through') }} {{ $transport }}{{ __(', so subscriber notifications sent the same way will arrive too.') }}</p>
<p style="margin:0;font-size:13px;color:#667085">{{ __('Nothing else to do.') }}</p>
@endsection
