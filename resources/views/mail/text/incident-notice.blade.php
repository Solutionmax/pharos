{{-- Plain text: URLs go out raw, an &amp; here breaks the link in a text-only client. --}}
{{ $status }}: {{ $name }}
@if ($components !== [])
Affects: {{ implode(', ', $components) }}
@endif

{{ $bodyText }}

{{ $when->format('j F Y, H:i') }}

Status page: {!! $link !!}
Unsubscribe: {!! $unsubscribe !!}
