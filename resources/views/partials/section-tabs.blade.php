<nav class="section-tabs" aria-label="{{ $navigationLabel }}">
  @foreach ($items as $item)
    <a class="section-tab" href="{{ $item['url'] }}" @if ($item['active']) aria-current="page" @endif>
      <span class="section-tab-icon" aria-hidden="true">@include('partials.icon', ['name' => $item['icon'], 'size' => 18])</span>
      <span class="section-tab-copy">
        <span class="section-tab-title">{{ $item['label'] }}@if (!empty($item['hint']))<span class="tabhint">{{ $item['hint'] }}</span>@endif</span>
        <span class="section-tab-description">{{ $item['description'] }}</span>
      </span>
    </a>
  @endforeach
</nav>
