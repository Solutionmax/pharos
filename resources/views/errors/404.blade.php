@extends('layouts.auth')
{{--
  Not found, in the shell of the sign in screen. One answer for every missing
  address: a page that was deleted, archived, never published or never existed
  reads the same, so the 404 gives nothing away about what was once there.

  The wall is the relief drawn by assets/not-found/scene.js. Without WebGL the
  flat wall below is the whole picture.
--}}
@section('title', __('Page not found'))
@section('standalone', true)
@php
  // Someone signed in on an old admin link is shown the way back into the admin.
  $viewer = request()->is('admin', 'admin/*') ? auth()->user() : null;
  $back = match (true) {
      $viewer === null => ['url' => url('/'), 'label' => __('Go to the status page')],
      $viewer->isAdmin() => ['url' => route('admin.pages.index'), 'label' => __('Back to Status pages')],
      default => ['url' => route('admin.overview'), 'label' => __('Back to the admin')],
  };
  $asked = \Illuminate\Support\Str::limit('/'.ltrim(request()->decodedPath(), '/'), 60);
  $scene = 'assets/not-found/scene.js';
@endphp
@section('card')
  <p class="pa-kicker"><i aria-hidden="true"></i>{{ __('Error 404') }}</p>
  <h1 class="pa-h1">{{ __('This page does not exist') }}</h1>
  <p class="pa-lede">{{ $viewer ? 'This part of the admin is gone. The status page it belonged to may have been deleted.' : 'The address may be mistyped, or the page was moved or removed.' }}</p>
  <div class="pa-form">
    <a class="pa-btn nf-btn" href="{{ $back['url'] }}">{{ $back['label'] }}</a>
  </div>
@endsection
@section('wall')
  <aside class="pa-wall" aria-hidden="true" data-nf-scene>
    <canvas class="nf-canvas"></canvas>
    <div class="pa-grid" style="--cols:44">@for ($cell = 0; $cell < 44 * 16; $cell++)<i class="o"></i>@endfor</div>
    <svg class="pa-ekg" viewBox="0 0 1200 140" preserveAspectRatio="none" focusable="false">
      <path class="pa-ekg-glow" d="M0 70L404 70L410 64L416 58L422 68L428 86L434 70L1200 70"/>
      <path class="pa-ekg-line" d="M0 70L404 70L410 64L416 58L422 68L428 86L434 70L1200 70"/>
    </svg>
    <div class="pa-cap">
      <p class="pa-cap-line">{{ __('Every check ran.') }} <em>{{ __('Nothing answered.') }}</em></p>
      <p class="pa-cap-kinds"><b class="nf-path">{{ $asked }}</b><span>{{ __('requested address') }}</span></p>
    </div>
  </aside>
  <script defer src="{{ asset($scene) }}?v={{ @filemtime(public_path($scene)) }}"></script>
@endsection
