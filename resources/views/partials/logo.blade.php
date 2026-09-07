@php
    $wordmark = $branding->name() === 'Pharos';
    $customLight = $branding->logoUrl();
    $customDark = $branding->logoDarkUrl();
    $custom = $customLight || $customDark;
    $light = $custom ? ($customLight ?? $customDark) : $branding->builtInAssetUrl($wordmark ? 'pharos-logo.svg' : 'pharos-mark.svg');
    $dark = $custom ? ($customDark ?? $customLight) : $branding->builtInAssetUrl($wordmark ? 'pharos-logo-white.svg' : 'pharos-mark-white.svg');
    $fixedDark = ($logoTheme ?? null) === 'dark';
@endphp
<style>
  .pharos-logo{display:flex;align-items:center;flex-shrink:0}
  .pharos-logo .brand-logo-light{display:block}
  .pharos-logo .brand-logo-dark{display:none}
  :root[data-theme="dark"] .pharos-logo .brand-logo-light{display:none}
  :root[data-theme="dark"] .pharos-logo .brand-logo-dark{display:block}
  @media (prefers-color-scheme:dark){
    :root:not([data-theme]) .pharos-logo .brand-logo-light{display:none}
    :root:not([data-theme]) .pharos-logo .brand-logo-dark{display:block}
  }
</style>
<span class="pharos-logo">
  @if ($fixedDark)
    <img src="{{ $dark }}" alt="{{ $custom || $wordmark ? $branding->name() : '' }}"
         style="height:{{ $size ?? 30 }}px;width:auto;max-width:200px;object-fit:contain">
  @else
    <img class="brand-logo-light" src="{{ $light }}" alt="{{ $custom || $wordmark ? $branding->name() : '' }}"
         style="height:{{ $size ?? 30 }}px;width:auto;max-width:200px;object-fit:contain">
    <img class="brand-logo-dark" src="{{ $dark }}" alt="{{ $custom || $wordmark ? $branding->name() : '' }}"
         style="height:{{ $size ?? 30 }}px;width:auto;max-width:200px;object-fit:contain">
  @endif
</span>
@if (! $custom && ! $wordmark)<span>{{ $branding->name() }}</span>@endif
