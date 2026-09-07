@if ($branding->logoUrl())
  @if ($branding->logoDarkUrl())
    {{-- Two files, CSS picks one: the explicit toggle (data-theme) wins over the OS preference. --}}
    <style>
      .logo-dark{display:none}
      [data-theme="dark"] .logo-light{display:none!important}
      [data-theme="dark"] .logo-dark{display:block}
      @media (prefers-color-scheme:dark){
        :root:not([data-theme="light"]) .logo-light{display:none!important}
        :root:not([data-theme="light"]) .logo-dark{display:block}
      }
    </style>
    <img class="logo-dark" src="{{ $branding->logoDarkUrl() }}" alt="{{ $branding->name() }}"
         style="max-height:{{ $size ?? 30 }}px;max-width:200px">
  @endif
  <img class="logo-light" src="{{ $branding->logoUrl() }}" alt="{{ $branding->name() }}"
       style="max-height:{{ $size ?? 30 }}px;max-width:200px;display:block">
@else
  @php
    $wordmark = $branding->name() === 'Pharos';
    $light = $wordmark ? 'pharos-logo.svg' : 'pharos-mark.svg';
    $dark = $wordmark ? 'pharos-logo-white.svg' : 'pharos-mark-white.svg';
  @endphp
  <style>
    .pharos-default-logo .logo-dark{display:none}
    [data-theme="dark"] .pharos-default-logo .logo-light{display:none}
    [data-theme="dark"] .pharos-default-logo .logo-dark{display:block}
    @media (prefers-color-scheme:dark){
      :root:not([data-theme="light"]) .pharos-default-logo .logo-light{display:none}
      :root:not([data-theme="light"]) .pharos-default-logo .logo-dark{display:block}
    }
  </style>
  <span class="pharos-default-logo" style="display:flex;flex-shrink:0">
    <img class="logo-light" src="{{ asset('brand/'.$light) }}" alt="{{ $wordmark ? $branding->name() : '' }}"
         style="height:{{ $size ?? 30 }}px;width:auto;max-width:200px">
    <img class="logo-dark" src="{{ asset('brand/'.$dark) }}" alt="{{ $wordmark ? $branding->name() : '' }}"
         style="height:{{ $size ?? 30 }}px;width:auto;max-width:200px">
  </span>
  @unless ($wordmark)<span>{{ $branding->name() }}</span>@endunless
@endif
