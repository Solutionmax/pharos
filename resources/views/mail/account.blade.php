{{-- An account mail (invitation, password reset): the lines of a MailMessage
     inside the shared frame, with the action as a button in the brand accent. --}}
@extends('mail.layout')
@section('body')
<p style="margin:0 0 14px;font-size:20px;font-weight:700;letter-spacing:-.02em">{{ $greeting }}</p>
@foreach ($introLines as $line)
<p style="margin:0 0 14px">{{ $line }}</p>
@endforeach
@if ($actionUrl)
<p style="margin:4px 0 20px"><a href="{{ $actionUrl }}" style="display:inline-block;background:{{ $accent }};color:#ffffff;font-weight:600;font-size:14px;padding:11px 20px;border-radius:10px;text-decoration:none">{{ $actionText }}</a></p>
@endif
@foreach ($outroLines as $line)
<p style="margin:0 0 10px;font-size:13px;color:#667085">{{ $line }}</p>
@endforeach
@if ($actionUrl)
<p style="margin:14px 0 0;font-size:12px;color:#667085;word-break:break-all">If the button does not work, open this address in your browser: <a href="{{ $actionUrl }}" style="color:#667085">{{ $actionUrl }}</a></p>
@endif
@endsection
