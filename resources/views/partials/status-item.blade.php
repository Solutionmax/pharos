{{-- One component row on the public page. Shared by the rows inside a service
     and the rows for components that belong to no service, so the two cannot
     drift apart. --}}
<div class="item" data-live-key="component-{{ $component->id }}" data-live-value="{{ $component->status->value }}:{{ $percentages[$component->id] ?? 'unknown' }}" data-live-message="{{ $component->name }}: {{ $component->status->label() }}">
  <span>
    @if ($component->link)
      <a class="nm" href="{{ $component->link }}" target="_blank" rel="noopener noreferrer">{{ $component->name }}</a>
    @else
      <span class="nm">{{ $component->name }}</span>
    @endif
    @if ($component->description)<br><span class="desc">{{ $component->description }}</span>@endif
  </span>
  <span class="service-metrics">
  @if ($modules['page.show_component_uptime'] && $component->show_uptime)
    {{-- One data-tip per day: the bar shows the latest 30 days, a hover is the only way to read one of them.
         The bar itself is the tab stop and speaks the summary; the slivers do not. --}}
    <span class="component-history">
    <span class="bar mini" role="img" tabindex="0" aria-label="{{ $component->name }}: {{ \App\Services\Uptime::format($percentages[$component->id]) }} uptime over {{ \App\Services\Uptime::WINDOW_DAYS }} days; bars show the latest 30 days">
      @foreach (array_slice($bars[$component->id], -30) as $d)<span class="{{ $d['tone'] === 'ok' ? '' : $d['tone'] }}" data-tip="{{ \Carbon\Carbon::parse($d['day'])->format('j M') }}{{ $d['known'] ? ' · '.number_format($d['pct'], 2).'%' : ' · no data' }}"></span>@endforeach
    </span>
    <span class="pct">{{ \App\Services\Uptime::format($percentages[$component->id]) }}</span>
    </span>
  @endif
  <span class="pill sm {{ $component->status->tone() }}">{{ $component->status->label() }}</span>
  </span>
</div>
