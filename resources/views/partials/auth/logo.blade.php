{{--
  The logo on the signed out screens: the operator's own under the brand pack,
  else the Pharos wordmark, whatever the installation is called (its name is on
  the line below). With the credit hidden and no logo of their own there is no
  logo at all, so nothing names Pharos.
--}}
@if ($branding->logoUrl() || $branding->logoDarkUrl())
  <div class="pa-logo">@include('partials.logo', ['size' => 26])</div>
@elseif (! $branding->creditHidden())
  <div class="pa-logo">
    <img class="pa-logo-light" src="{{ $branding->builtInAssetUrl('pharos-logo.svg') }}" alt="Pharos" width="86" height="26">
    <img class="pa-logo-dark" src="{{ $branding->builtInAssetUrl('pharos-logo-white.svg') }}" alt="Pharos" width="86" height="26">
  </div>
@endif
