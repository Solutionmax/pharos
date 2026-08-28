{{-- One component row on the public page. Shared by the rows inside a service
     and the rows for components that belong to no service, so the two cannot
     drift apart. --}}
<div class="item">
  <span>
    @if ($component->link)
      <a class="nm" href="{{ $component->link }}" target="_blank" rel="noopener noreferrer">{{ $component->name }}</a>
    @else
      <span class="nm">{{ $component->name }}</span>
    @endif
    @if ($component->description)<br><span class="desc">{{ $component->description }}</span>@endif
  </span>
  @if ($modules['page.show_component_uptime'] && $component->show_uptime)
    {{-- One data-tip per day: the bar is 90 slivers, a hover is the only way to read one of them.
         The bar itself is the tab stop and speaks the summary; the slivers do not. --}}
    <span class="bar mini" role="img" tabindex="0" aria-label="{{ $component->name }}: {{ number_format($percentages[$component->id], 2) }}% uptime over {{ \App\Services\Uptime::WINDOW_DAYS }} days">
      @foreach ($bars[$component->id] as $d)<span class="{{ $d['tone'] === 'ok' ? '' : $d['tone'] }}" data-tip="{{ \Carbon\Carbon::parse($d['day'])->format('j M') }}{{ $d['known'] ? ' · '.number_format($d['pct'], 2).'%' : ' · no data' }}"></span>@endforeach
    </span>
    <span class="pct">{{ number_format($percentages[$component->id], 2) }}%</span>
  @endif
  <span class="pill sm {{ $component->status->tone() }}">{{ $component->status->label() }}</span>
</div>
