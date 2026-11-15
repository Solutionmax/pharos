{{-- Planned work in the log: pinned while it is still to come, live while it runs. Today sits on its strip. --}}
@php
  $strip = \App\Services\StatusTimeline::maintenanceStrip($maintenance, $now);
  $isLive = $strip['live'];
  $daysToGo = \App\Services\StatusTimeline::daysUntil($maintenance->starts_at, $now);
  $ends = $maintenance->ends_at->isSameDay($maintenance->starts_at) ? $maintenance->ends_at->format('H:i') : $maintenance->ends_at->translatedFormat('j M H:i');
@endphp
<article id="maintenance-{{ $maintenance->id }}" class="entry is-m {{ $isLive ? 'live' : 'pin' }}" data-day="{{ $maintenance->starts_at->format('Y-m-d') }}"
         data-live-key="maintenance-{{ $maintenance->id }}" data-live-value="{{ $isLive ? 'on' : 'planned' }}:{{ $maintenance->updated_at?->timestamp }}">
  <i class="node"></i>
  <div class="date when">
    @if ($isLive)
      <b>{{ __('Now') }}</b><span class="label">{{ __('Live') }}</span>
    @else
      <b>{{ $daysToGo }}</b><span class="label">{{ $daysToGo === 1 ? __('day to go') : __('days to go') }}<br>{{ __('Pinned') }}</span>
    @endif
  </div>
  <div class="card">
    <header>
      <h3>{{ $maintenance->title }}</h3>
      <span class="meta">
        @if ($isLive)
          <b>{{ __('Maintenance in progress') }} · {{ __('until :time', ['time' => $ends]) }}</b>
        @else
          {{ __('Scheduled maintenance') }} · <time datetime="{{ $maintenance->starts_at->toIso8601String() }}">{{ $maintenance->starts_at->translatedFormat('D j M, H:i') }} {{ __('to') }} {{ $ends }}</time>
        @endif
      </span>
    </header>
    @if ($maintenance->components->isNotEmpty())
      <p class="aff">{{ __('Affects') }} {{ $maintenance->components->pluck('name')->join(', ', __(' and ')) }}</p>
    @endif
    <div class="strip when">
      <span>{{ $strip['from'] }}</span>
      <div class="bar">
        <i class="seg ph0" style="left:0;width:{{ $strip['now'] * 100 }}%"></i>
        <i class="seg todo" style="left:{{ $strip['now'] * 100 }}%;width:{{ (1 - $strip['now']) * 100 }}%"></i>
        <i class="goal"></i>
        <i class="dot" style="left:0"></i>
        <i class="dot cur beat" style="left:{{ $strip['now'] * 100 }}%"></i>
        <span class="at" style="left:{{ $strip['now'] * 100 }}%">{{ $isLive ? __('Now') : __('Today') }}</span>
      </div>
      <span>{{ $strip['to'] }}</span>
    </div>
    @if ($maintenance->message)
      <ol class="msgs">
        <li>
          <strong>{{ __($maintenance->stateLabel()) }}</strong>
          <time>{{ $isLive ? $maintenance->starts_at->format('H:i') : ($maintenance->created_at ?? $maintenance->starts_at)->translatedFormat('j M') }}</time>
          <div class="md">{!! \Illuminate\Support\Str::markdown($maintenance->message, \App\Services\MailTemplates::MARKDOWN) !!}</div>
        </li>
      </ol>
    @endif
  </div>
</article>
