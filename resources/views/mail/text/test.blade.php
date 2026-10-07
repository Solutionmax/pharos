{{-- Plain text: URLs go out raw, an &amp; here breaks the link in a text-only client. --}}
{{ __('Mail works.

Hi') }} {{ $user->name }}{{ __(', this is the test message from') }} {!! $source !!} {{ __('on') }} {!! $brand !!}{{ __('. It went out through') }} {!! $transport !!}{{ __(', so subscriber notifications sent the same way will arrive too.') }}

{!! $link !!}
