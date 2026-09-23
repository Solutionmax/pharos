{{-- Where times are typed: which zone they mean. Personal zones say so, and name the zone customers read. --}}
@php
  $zoneShown = \App\Services\Clock::timezone();
  $zoneInstall = \App\Services\Clock::installationTimezone();
@endphp
<span class="{{ $class ?? 'help' }}" data-zone-hint>Times shown in {{ $zoneShown }} ({{ \App\Services\Clock::offsetLabel($zoneShown) }})@if ($zoneShown !== $zoneInstall), your own zone. Customers see {{ $zoneInstall }}.@else.@endif</span>
