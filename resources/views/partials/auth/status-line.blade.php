{{-- One line of what the default status page says right now, linking to it. $status comes from App\Services\SignInStatus. --}}
<a class="pa-line is-{{ $status['tone'] }}" href="{{ $status['url'] }}">
  <i class="pa-dot" aria-hidden="true"></i>
  <b>{{ $status['title'] }}</b>
  <span>{{ __('Status page') }}</span>
</a>
