@if ($branding->logoUrl())
  <img src="{{ $branding->logoUrl() }}" alt="{{ $branding->name() }}"
       style="max-height:{{ $size ?? 30 }}px;max-width:200px;display:block">
@else
  <span class="mark" aria-hidden="true" style="color:var(--brand);display:flex">
    <svg width="{{ $size ?? 30 }}" height="{{ $size ?? 30 }}" viewBox="0 0 64 64" fill="currentColor">
      <path d="M32 20 L60 6 L60 12 L32 24 Z" opacity=".30"/>
      <path d="M32 20 L4 6 L4 12 L32 24 Z" opacity=".30"/>
      <path d="M32 20 L58 20 L58 24 L32 24 Z" opacity=".14"/>
      <path d="M32 20 L6 20 L6 24 L32 24 Z" opacity=".14"/>
      <rect x="25" y="14" width="14" height="11" rx="2.5"/>
      <path d="M27 27 h10 l4 25 h-18 Z"/>
      <rect x="17" y="53" width="30" height="6" rx="2"/>
    </svg>
  </span>
  <span>{{ $branding->name() }}</span>
@endif
