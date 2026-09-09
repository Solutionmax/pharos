{{-- The submitted value stays Markdown, preserving notifications and API compatibility. --}}
<div data-pharos-editor="{{ $for }}" hidden></div>
@once
@push('head-assets')
<link rel="stylesheet" href="{{ asset('assets/editor/editor.css') }}?v=0.6.0">
<link rel="stylesheet" href="{{ asset('assets/editor/dark.css') }}?v=0.6.0">
@endpush
<script defer src="{{ asset('assets/editor/editor.js') }}?v=0.6.0"></script>
@endonce
