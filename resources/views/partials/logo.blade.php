@if ($branding->logoUrl())
  <img src="{{ $branding->logoUrl() }}" alt="{{ $branding->name() }}"
       style="max-height:{{ $size ?? 30 }}px;max-width:200px;display:block">
@else
  <span class="mark" aria-hidden="true" style="color:var(--brand);display:flex">
    <svg width="{{ $size ?? 30 }}" height="{{ $size ?? 30 }}" viewBox="0 0 64 64" fill="currentColor">
      {{-- Same geometry as public/brand/pharos-mark.svg: two beams, an amber lamp, one tower mass. --}}
      <g opacity=".32">
        <path d="M32 17 L2 8 L2 15 L32 23 Z"/>
        <path d="M32 17 L62 8 L62 15 L32 23 Z"/>
      </g>
      <rect x="27" y="14" width="10" height="9" rx="2" fill="#F5A623"/>
      <path d="M25 11 h14 a2 2 0 0 1 2 2 v11 h-18 v-11 a2 2 0 0 1 2 -2 z M27 14 v8 h10 v-8 z"/>
      <rect x="21" y="25" width="22" height="3.5" rx="1"/>
      <path d="M26 30 h12 l4.5 22 h-21 Z"/>
      <rect x="15" y="53" width="34" height="6" rx="1.5"/>
    </svg>
  </span>
  <span>{{ $branding->name() }}</span>
@endif
