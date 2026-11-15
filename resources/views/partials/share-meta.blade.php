{{-- What a search result or a link preview in a chat shows. The logo is the
     picture only when the page has one of its own; Pharos sends none. --}}
@php $shareImage = $branding->logoUrl(); @endphp
<meta name="description" content="{{ $shareDescription }}">
<link rel="canonical" href="{{ $shareUrl }}">
<meta property="og:type" content="website">
<meta property="og:site_name" content="{{ $branding->name() }} {{ __('Status') }}">
<meta property="og:title" content="{{ $shareTitle }}">
<meta property="og:description" content="{{ $shareDescription }}">
<meta property="og:url" content="{{ $shareUrl }}">
@if ($shareImage)
<meta property="og:image" content="{{ $shareImage }}">
@endif
