{{--
  "Right now": what the default status page shows, beside the form on wide
  screens. Only public data, read by App\Services\SignInStatus; a part the
  public page hides (uptime, incidents) is simply not in $status.
--}}
<aside class="pa-now" aria-labelledby="pa-now-label">
  <div class="pa-now-in">
    <p class="pa-lbl" id="pa-now-label">{{ __('Right now') }}</p>
    <div class="pa-state is-{{ $status['tone'] }}"><i class="pa-dot" aria-hidden="true"></i><p>{{ $status['title'] }}</p></div>
    <p class="pa-sub">{{ $status['sub'] }}</p>

    @if ($status['strip'])
      <div class="pa-strip" style="--days:{{ $status['days'] }}" aria-hidden="true">@foreach ($status['strip'] as $day)@php
        $attributes = ($day['tone'] === 'ok' ? '' : ' class="'.e($day['tone']).'"').($day['h'] ? ' style="--h:'.(int) $day['h'].'%"' : '');
      @endphp<i{!! $attributes !!}></i>@endforeach</div>
      <div class="pa-axis pa-lbl">
        <span>{{ __('90 days ago') }}</span>
        @if ($status['uptime'])<span><b>{{ $status['uptime'] }}</b> {{ __('uptime') }}</span>@endif
        <span>{{ __('Today') }}</span>
      </div>
    @endif

    @if ($status['incident'] || $status['maintenance'])
      <dl class="pa-facts">
        @if ($status['incident'])
          <div><dt class="pa-lbl">{{ __('Latest incident') }}</dt><dd><b>{{ $status['incident']['name'] }}</b></dd><dd class="pa-when">{{ $status['incident']['when'] }}</dd></div>
        @endif
        @if ($status['maintenance'])
          <div><dt class="pa-lbl">{{ __('Next maintenance') }}</dt><dd><b>{{ $status['maintenance']['name'] }}</b></dd><dd class="pa-when">{{ $status['maintenance']['when'] }}</dd></div>
        @endif
      </dl>
    @endif
  </div>
</aside>
