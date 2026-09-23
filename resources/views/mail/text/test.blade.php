{{-- Plain text: URLs go out raw, an &amp; here breaks the link in a text-only client. --}}
Mail works.

Hi {{ $user->name }}, this is the test message from {!! $source !!} on {!! $brand !!}. It went out through {!! $transport !!}, so subscriber notifications sent the same way will arrive too.

{!! $link !!}
