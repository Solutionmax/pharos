@php
$points = app(\App\Services\LatencyHistory::class)->for($component);
$values = array_filter(array_column($points, 'ms'), fn ($v) => $v !== null);
$max = max(1, $values ? max($values) : 1);
$paths = []; $path = '';
foreach ($points as $i => $point) {
    if ($point['ms'] === null) { if ($path !== '') $paths[] = $path; $path = ''; continue; }
    $path .= ($path === '' ? 'M' : 'L').round($i * 600 / 287, 2).','.round(90 - $point['ms'] * 80 / $max, 2).' ';
}
if ($path !== '') $paths[] = $path;
@endphp
<div class="panel latency-chart"><div class="panel-hd"><h3>{{ __('Response time · last 24 hours') }}</h3></div><div class="panel-bd">
@if (!$values)<p>{{ __('No measured response times yet.') }}</p>@else
<svg viewBox="0 0 600 105" role="img" aria-label="{{ __('Response time in milliseconds. Gaps mean no measurements.') }}" style="width:100%;height:130px"><line x1="0" y1="90" x2="600" y2="90" stroke="currentColor" opacity=".2"/>@foreach ($paths as $path)<path d="{{ $path }}" fill="none" stroke="currentColor" stroke-width="2"/>@endforeach</svg>
<p class="hint">{{ __('Maximum') }}: {{ round($max) }} {{ __('ms ·') }} {{ __('Average') }}: {{ round(array_sum($values)/count($values)) }} {{ __('ms ·') }} {{ __('Gaps mean no measurements.') }}</p>
@endif</div></div>
