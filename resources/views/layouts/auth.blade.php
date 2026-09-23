@extends('layouts.admin')
{{--
  The signed-out shell ("Pulse"): the form on one side, on the other a wall of
  uptime cells with a heartbeat running through it. The wall is decoration only:
  nothing on it is read from the database, because this page is public. Built
  from the operator's brand tokens; with the credit hidden nothing here names Pharos.

  Without JavaScript every form posts the normal way. pharos-auth.js takes over
  the sign in and two factor forms and plays the real outcome on the wall.
--}}
@php
  $whiteLabel = $branding->creditHidden();
@endphp
@push('head-assets')
<link rel="stylesheet" href="{{ asset('assets/pharos-auth.css') }}?v={{ @filemtime(public_path('assets/pharos-auth.css')) }}">
@endpush
@section('content')
<div class="pa" data-pa>
  <section class="pa-side" data-pa-side>
    <div class="pa-body">
      <div class="pa-brand">
        @include('partials.logo', ['size' => 28])
        <svg class="pa-trace" viewBox="0 0 120 28" aria-hidden="true" focusable="false"><path d="M0 14h34l6-10 8 20 7-14 5 4h60"/></svg>
      </div>
      @yield('card')
    </div>
    <footer class="pa-foot">
      <a href="{{ url('/') }}">Status page</a>
      @unless ($whiteLabel)
        <a href="{{ config('pharos.docs_url') }}" target="_blank" rel="noopener">Documentation</a>
        <span>Powered by Pharos</span>
      @endunless
    </footer>
  </section>

  <aside class="pa-wall" aria-hidden="true" data-pa-wall>
    <div class="pa-grid" data-pa-grid></div>
    <svg class="pa-ekg" viewBox="0 0 1200 140" preserveAspectRatio="none" focusable="false" data-pa-ekg>
      <path class="pa-ekg-glow" d="M0 70L104 70L108 65L112 60L116 67L120 93L124 118L128 92L132 53L136 26L140 44L144 63L148 70L168 70L172 69L176 64L180 60L184 58L188 59L192 63L196 68L200 70L364 70L368 65L372 60L376 67L380 93L384 118L388 92L392 53L396 26L400 44L404 63L408 70L428 70L432 69L436 64L440 60L444 58L448 59L452 63L456 68L460 70L624 70L628 65L632 60L636 67L640 93L644 118L648 92L652 53L656 26L660 44L664 63L668 70L688 70L692 69L696 64L700 60L704 58L708 59L712 63L716 68L720 70L884 70L888 65L892 60L896 67L900 93L904 118L908 92L912 53L916 26L920 44L924 63L928 70L948 70L952 69L956 64L960 60L964 58L968 59L972 63L976 68L980 70L1144 70L1148 65L1152 60L1156 67L1160 93L1164 118L1168 92L1172 53L1176 26L1180 44L1184 63L1188 70L1200 70"/>
      <path class="pa-ekg-line" d="M0 70L104 70L108 65L112 60L116 67L120 93L124 118L128 92L132 53L136 26L140 44L144 63L148 70L168 70L172 69L176 64L180 60L184 58L188 59L192 63L196 68L200 70L364 70L368 65L372 60L376 67L380 93L384 118L388 92L392 53L396 26L400 44L404 63L408 70L428 70L432 69L436 64L440 60L444 58L448 59L452 63L456 68L460 70L624 70L628 65L632 60L636 67L640 93L644 118L648 92L652 53L656 26L660 44L664 63L668 70L688 70L692 69L696 64L700 60L704 58L708 59L712 63L716 68L720 70L884 70L888 65L892 60L896 67L900 93L904 118L908 92L912 53L916 26L920 44L924 63L928 70L948 70L952 69L956 64L960 60L964 58L968 59L972 63L976 68L980 70L1144 70L1148 65L1152 60L1156 67L1160 93L1164 118L1168 92L1172 53L1176 26L1180 44L1184 63L1188 70L1200 70"/>
    </svg>
    {{-- Example hosts, not real ones: this page is public and shows no status. --}}
    <div class="pa-chips">
      <div class="pa-chip pa-chip-keep" style="left:9%;top:14%"><i></i><div><b>web-01</b><span>HTTP check</span></div></div>
      <div class="pa-chip" style="right:10%;top:24%;animation-delay:-2s"><i></i><div><b>mail</b><span>TCP port 993</span></div></div>
      <div class="pa-chip" style="left:16%;bottom:24%;animation-delay:-4s"><i></i><div><b>backups</b><span>heartbeat</span></div></div>
    </div>
    <div class="pa-cap">
      <p class="pa-cap-line">Every check runs. <em>Every minute.</em></p>
      <p class="pa-cap-kinds"><b>HTTP · TCP · heartbeat</b><span>kinds of checks</span></p>
    </div>
  </aside>
</div>
<p class="pa-sr" role="status" aria-live="polite" data-pa-live></p>
<script defer src="{{ asset('assets/pharos-auth.js') }}?v={{ @filemtime(public_path('assets/pharos-auth.js')) }}"></script>
@endsection
