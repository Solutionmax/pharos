@php $servicePct = app(\App\Services\Uptime::class)->percentageOf($serviceBar); @endphp
<span class="service-history">
  <span class="history-bar" role="img" tabindex="0" aria-label="{{ $historyName }}: {{ \App\Services\Uptime::format($servicePct) }} mean daily component availability over 90 days; bars show the latest 30 days">
    @foreach (array_slice($serviceBar, -30) as $day)
      <span class="{{ $day['tone'] }}" data-tip="{{ \Carbon\Carbon::parse($day['day'])->format('j M') }} · {{ $day['known'] ? number_format($day['pct'], 2).'%' : 'no data' }}"></span>
    @endforeach
  </span>
  <span class="history-pct">{{ \App\Services\Uptime::format($servicePct) }}</span>
</span>
