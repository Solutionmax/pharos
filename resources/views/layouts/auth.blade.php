@extends('layouts.admin')
{{--
  The signed out shell: one calm column with the form. The sign in page may add
  a status line under it (section "line") and, when the installation chose the
  status layout, a "Right now" panel beside it (section "aside"). Forgot
  password, reset password and two factor use the column alone.

  Built from the operator's brand tokens; with the credit hidden nothing here
  names Pharos. Without JavaScript every form posts the normal way;
  pharos-auth.js takes over the sign in and two factor forms.
--}}
@php
  $whiteLabel = $branding->creditHidden();
  $split = View::hasSection('aside');
  $hasLine = View::hasSection('line');
@endphp
@push('head-assets')
<link rel="stylesheet" href="{{ asset('assets/pharos-signin.css') }}?v={{ @filemtime(public_path('assets/pharos-signin.css')) }}">
@endpush
@section('content')
<div @class(['pa', 'pa-split' => $split]) data-pa>
  <main class="pa-side">
    <div class="pa-col" data-pa-side>
      @include('partials.auth.logo')
      <p class="pa-lbl pa-where"><b>{{ $branding->name() }}</b><span>{{ request()->getHost() }}</span></p>
      @yield('card')
      @yield('line')
    </div>

    <footer class="pa-foot">
      {{-- The status line carries this link; beside the panel (wide screens) it is back here. --}}
      @if (! $hasLine || $split)
        <a @class(['pa-foot-status' => $hasLine]) href="{{ url('/') }}">{{ __('Status page') }}</a>
      @endif
      @unless ($whiteLabel)
        <a href="{{ config('pharos.docs_url') }}" target="_blank" rel="noopener">{{ __('Documentation') }}</a>
        <span class="pa-by">{{ __('Powered by Pharos') }}</span>
      @endunless
    </footer>
  </main>

  @yield('aside')
</div>
<p class="pa-sr" role="status" aria-live="polite" data-pa-live></p>
<script defer src="{{ asset('assets/pharos-auth.js') }}?v={{ @filemtime(public_path('assets/pharos-auth.js')) }}"></script>
@endsection
