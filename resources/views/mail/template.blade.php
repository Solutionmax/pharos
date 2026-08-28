{{-- A customer template's rendered body, inside the frame. The body is our own
     Markdown output (tags escaped at render time), so it is printed as is. --}}
@extends('mail.layout')
@section('body'){!! $body !!}@endsection
