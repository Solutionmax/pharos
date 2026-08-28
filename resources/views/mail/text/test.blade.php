{{-- Plain text: URLs go out raw, an &amp; here breaks the link in a text-only client. --}}
Mail works.

Hi {{ $user->name }}, this is the test message from Settings → Mail on {{ $brand }}. It went out through the {{ $mailer }} mailer, so subscriber notifications will too.

{!! $link !!}
