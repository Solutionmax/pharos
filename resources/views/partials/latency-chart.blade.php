@php
$points = app(\App\Services\LatencyHistory::class)->for($component);
$values = array_filter(array_column($points, 'ms'), fn ($v) => $v !== null);
$max = max(1, $values ? max($values) : 1);
$segments = []; $segment = [];
foreach ($points as $i => $point) {
    if ($point['ms'] === null) {
        if ($segment !== []) $segments[] = $segment;
        $segment = [];
        continue;
    }
    $segment[] = ['x' => round($i * 600 / 287, 2), 'y' => round(90 - $point['ms'] * 80 / $max, 2)];
}
if ($segment !== []) $segments[] = $segment;
@endphp
<div class="panel latency-chart"><div class="panel-hd"><h3>{{ __('Response time · last 24 hours') }}</h3></div><div class="panel-bd">
@if (!$values)<p>{{ __('No measured response times yet.') }}</p>@else
<svg viewBox="0 0 600 105" role="img" aria-label="{{ __('Response time in milliseconds. Gaps mean no measurements.') }}" style="width:100%;height:130px"><line x1="0" y1="90" x2="600" y2="90" stroke="currentColor" opacity=".2"/>@foreach ($segments as $segment)@if (count($segment) === 1)<circle cx="{{ $segment[0]['x'] }}" cy="{{ $segment[0]['y'] }}" r="3" fill="currentColor"/>@else<path d="@foreach ($segment as $i => $point){{ $i === 0 ? 'M' : 'L' }}{{ $point['x'] }},{{ $point['y'] }} @endforeach" fill="none" stroke="currentColor" stroke-width="2"/>@endif@endforeach</svg>
<p class="hint">{{ __('Maximum') }}: {{ round($max) }} {{ __('ms ·') }} {{ __('Average') }}: {{ round(array_sum($values)/count($values)) }} {{ __('ms ·') }} {{ __('Gaps mean no measurements.') }}</p>
@endif</div></div>
