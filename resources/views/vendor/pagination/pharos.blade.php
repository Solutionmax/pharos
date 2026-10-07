@if ($paginator->hasPages())
  {{-- Pharos' own pager: the framework default is Tailwind markup, and without
       Tailwind its arrows render as full-width SVGs. --}}
  <nav class="pager" aria-label="{{ __('Pages') }}">
    <span class="pager-count">{{ __('Page') }} {{ $paginator->currentPage() }} {{ __('of') }} {{ $paginator->lastPage() }}
      <span class="sub">· {{ $paginator->firstItem() }} {{ __('to') }} {{ $paginator->lastItem() }} {{ __('of') }} {{ $paginator->total() }}</span></span>
    <span class="pager-links">
      @if ($paginator->onFirstPage())
        <span class="btn ghost" aria-disabled="true">{{ __('←') }} {{ $previousLabel ?? 'Newer' }}</span>
      @else
        <a class="btn ghost" href="{{ $paginator->previousPageUrl() }}" rel="prev">{{ __('←') }} {{ $previousLabel ?? 'Newer' }}</a>
      @endif
      @if ($paginator->hasMorePages())
        <a class="btn ghost" href="{{ $paginator->nextPageUrl() }}" rel="next">{{ $nextLabel ?? 'Older' }} {{ __('→') }}</a>
      @else
        <span class="btn ghost" aria-disabled="true">{{ $nextLabel ?? 'Older' }} {{ __('→') }}</span>
      @endif
    </span>
  </nav>
@endif
