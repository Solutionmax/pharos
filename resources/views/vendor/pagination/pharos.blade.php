@if ($paginator->hasPages())
  {{-- Pharos' own pager: the framework default is Tailwind markup, and without
       Tailwind its arrows render as full-width SVGs. --}}
  <nav class="pager" aria-label="Pages">
    <span class="pager-count">Page {{ $paginator->currentPage() }} of {{ $paginator->lastPage() }}
      <span class="sub">· {{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} of {{ $paginator->total() }}</span></span>
    <span class="pager-links">
      @if ($paginator->onFirstPage())
        <span class="btn ghost" aria-disabled="true">&larr; Newer</span>
      @else
        <a class="btn ghost" href="{{ $paginator->previousPageUrl() }}" rel="prev">&larr; Newer</a>
      @endif
      @if ($paginator->hasMorePages())
        <a class="btn ghost" href="{{ $paginator->nextPageUrl() }}" rel="next">Older &rarr;</a>
      @else
        <span class="btn ghost" aria-disabled="true">Older &rarr;</span>
      @endif
    </span>
  </nav>
@endif
