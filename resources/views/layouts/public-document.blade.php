@php
  $branding = app(\App\Services\Branding::class);
  $documentTheme = $branding->theme();
  $documentPage = app(\App\Services\PageContext::class)->page();
@endphp
<!doctype html>
<html lang="{{ app()->getLocale() }}" @if ($documentTheme !== 'system') data-theme="{{ $documentTheme }}" @endif>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>@yield('title') · {{ $documentPage->name }}</title>
  @include('partials.theme-early', ['theme' => $documentTheme])
  <link rel="icon" href="{{ $branding->faviconUrl() }}">
  <link rel="stylesheet" href="{{ asset('fonts/fonts.css') }}">
  @include('partials.tokens')
  <link rel="stylesheet" href="{{ asset('assets/pharos-documents.css') }}?v={{ filemtime(public_path('assets/pharos-documents.css')) }}">
  @stack('head')
</head>
<body>
  <main class="document @yield('document-class')">
    <nav class="document-nav"><a href="{{ \App\Services\PageUrls::route('status') }}">← {{ __('Status page') }}</a><span>{{ $documentPage->name }}</span>@include('partials.theme-toggle')</nav>
    <header class="document-head"><h1>@yield('title')</h1>@yield('subtitle')</header>
    @yield('content')
  </main>
  @include('partials.theme-script', ['theme' => $documentTheme])
</body>
</html>
