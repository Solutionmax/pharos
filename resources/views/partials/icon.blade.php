{{-- Line icons at a single 1.6 stroke weight. Unicode glyphs render differently
     on every platform and never line up; these do. --}}
@php $s = $size ?? 18; @endphp
<svg width="{{ $s }}" height="{{ $s }}" viewBox="0 0 24 24" fill="none" stroke="currentColor"
     stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
  @switch($name)
    @case('audit')
      <path d="M4 5.5A1.5 1.5 0 0 1 5.5 4H15l5 5v9.5a1.5 1.5 0 0 1-1.5 1.5h-13A1.5 1.5 0 0 1 4 18.5Z"/>
      <path d="M14.5 4v5h5"/><path d="M8 13h6M8 16.5h4"/>
      @break
    @case('services')
      <rect x="3" y="4" width="18" height="6" rx="2"/><rect x="3" y="14" width="18" height="6" rx="2"/>
      <path d="M7 7h.01M7 17h.01"/>
      @break
    @case('components')
      <rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/>
      <rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>
      @break
    @case('incidents')
      <path d="M12 3.5 2.5 20h19L12 3.5Z"/><path d="M12 10v4M12 17h.01"/>
      @break
    @case('settings')
      <circle cx="12" cy="12" r="3"/>
      <path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-2.9 1.2V21a2 2 0 1 1-4 0v-.1A1.7 1.7 0 0 0 7 19.4a1.7 1.7 0 0 0-1.9.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0-1.2-2.9H1a2 2 0 1 1 0-4h.1A1.7 1.7 0 0 0 2.6 7a1.7 1.7 0 0 0-.3-1.9l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.9.3H7a1.7 1.7 0 0 0 1-1.5V1a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.9-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.9V7a1.7 1.7 0 0 0 1.5 1H23a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1Z" transform="translate(0.5 0.5) scale(0.92)"/>
      @break
    @case('sliders')
      <path d="M4 7h8M16 7h4M4 12h3M11 12h9M4 17h11M19 17h1"/>
      <circle cx="14" cy="7" r="2"/><circle cx="9" cy="12" r="2"/><circle cx="17" cy="17" r="2"/>
      @break
    @case('integrations')
      <path d="M7 8 3 12l4 4M17 8l4 4-4 4M14 4l-4 16"/>
      @break
    @case('branding')
      <circle cx="12" cy="12" r="9"/><path d="M12 3a9 9 0 0 0 0 18Z" fill="currentColor" stroke="none"/>
      @break
    @case('users')
      <path d="M16 20v-1.5A3.5 3.5 0 0 0 12.5 15h-5A3.5 3.5 0 0 0 4 18.5V20"/>
      <circle cx="10" cy="8" r="3.5"/><path d="M20 20v-1.5a3.5 3.5 0 0 0-2.6-3.4M15.5 4.6a3.5 3.5 0 0 1 0 6.8"/>
      @break
    @case('mail')
      <rect x="3" y="5" width="18" height="14" rx="2.5"/><path d="m3.5 7 8.5 6 8.5-6"/>
      @break
    @case('external')
      <path d="M13 4h7v7M20 4l-9 9M18 14v5a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h5"/>
      @break
    @case('signout')
      <path d="M9 21H6a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h3M16 17l5-5-5-5M21 12H9"/>
      @break
    @case('update')
      <path d="M20.5 12a8.5 8.5 0 1 1-2.5-6"/><path d="M20.5 4v5h-5"/>
      @break
    @case('empty')
      <rect x="3" y="5" width="18" height="14" rx="2.5"/><path d="M3 10h18M8 15h5"/>
      @break
    @default
      <circle cx="12" cy="12" r="9"/>
  @endswitch
</svg>
