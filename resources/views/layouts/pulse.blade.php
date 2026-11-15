@extends('layouts.admin')
{{--
  The "Pulse" shell: the card on one side, on the other a wall the view brings
  itself, with its own script. Only the 404 uses it now (its wall is the relief
  of assets/not-found/scene.js); the sign in screens moved to layouts/auth.
  Built from the operator's brand tokens; with the credit hidden nothing here names Pharos.
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
      <a href="{{ url('/') }}">{{ __('Status page') }}</a>
      @unless ($whiteLabel)
        <a href="{{ config('pharos.docs_url') }}" target="_blank" rel="noopener">{{ __('Documentation') }}</a>
        <span>{{ __('Powered by Pharos') }}</span>
      @endunless
    </footer>
  </section>

  @yield('wall')
</div>
@endsection
