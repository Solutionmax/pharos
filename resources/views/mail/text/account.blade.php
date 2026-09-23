{{-- Plain text of an account mail: URLs go out raw, an &amp; here breaks the link. --}}
{!! $greeting !!}

@foreach ($introLines as $line)
{!! $line !!}

@endforeach
@if ($actionUrl)
{!! $actionText !!}: {!! $actionUrl !!}

@endif
@foreach ($outroLines as $line)
{!! $line !!}

@endforeach
{!! $brand !!} status page: {!! $link !!}
