{{-- One component on the public page: its name, how it is now, and its 90 days on the shared axis.
     Loose components and the components inside a service use the same row, so they cannot drift apart. --}}
@php $isOk = $component->status === \App\Enums\ComponentStatus::Operational; @endphp
<div class="row is-{{ $component->status->tone() }}" data-live-key="component-{{ $component->id }}" data-live-value="{{ $component->status->value }}:{{ $percentages[$component->id] ?? 'unknown' }}" data-live-message="{{ $component->name }}: {{ __($component->status->label() ?? '') }}">
  <div class="nm">
    @if ($component->link)
      <a href="{{ $component->link }}" target="_blank" rel="noopener noreferrer">{{ $component->name }}</a>
    @else
      <span>{{ $component->name }}</span>
    @endif
    @unless ($isOk)<em>{{ __($component->status->label() ?? '') }}</em>@endunless
    @if ($component->description)<span class="desc">{{ $component->description }}</span>@endif
  </div>
  @if ($modules['page.show_component_uptime'] && $component->show_uptime)
    @include('partials.status-track', ['bar' => $bars[$component->id]])
    <div class="pc">{{ \App\Services\Uptime::format($percentages[$component->id]) }}</div>
  @else
    <div class="track"></div>
    <div class="pc">{{ $isOk ? __('Operational') : '' }}</div>
  @endif
</div>
@if ($component->show_latency)
  @include('partials.latency-chart', ['component' => $component])
@endif
