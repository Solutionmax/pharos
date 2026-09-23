{{-- The page picker ("This page") and the "View status page" picker share this
     markup. Every page shows a dot for its worst live component status, with
     the status written out next to it so colour is never the only signal. --}}
@php
  $viewPages = $viewPages ?? false;
  $menuPages = $viewPages ? $selectorPages->where('is_published', true) : $selectorPages;
  $pageStates = $pageStates ?? [];
  $stateOf = fn ($p) => $pageStates[$p->id] ?? null;
@endphp
<details class="page-menu page-switcher" name="status-page-picker" aria-label="{{ $viewPages ? 'View a published status page' : 'Choose a page to manage' }}">
  <summary class="nav">
    @include('partials.icon', ['name' => $viewPages ? 'external' : 'pages'])
    <span class="page-name">
      <span class="page-title">{{ $viewPages ? 'View status page' : ($selectedPage?->name ?? 'Choose a page') }}</span>
      @if ($viewPages)
        <span class="page-tag page-tag--slate">{{ $menuPages->count() }} published</span>
      @elseif ($selectedPage)
        @include('partials.page-tag', ['tagPage' => $selectedPage])
        <span class="page-state"><span class="pdot s-{{ $stateOf($selectedPage)?->tone() ?? 'n' }}" aria-hidden="true"></span>{{ \App\Services\PageStatus::label($stateOf($selectedPage)) }}</span>
      @endif
      <span class="page-note">{{ $viewPages ? 'Open in a new tab' : 'Switch page' }}</span>
    </span>
  </summary>
  <div class="page-picker-panel">
    @if ($menuPages->count() > 5)
      <div class="page-search">
        <input type="search" data-page-filter placeholder="Find a page or tag" aria-label="{{ $viewPages ? 'Search published pages' : 'Search pages to manage' }}" autocomplete="off">
      </div>
    @endif
    <div class="page-options">
      @forelse ($menuPages as $menuPage)
        <a class="nav" data-page-choice data-page-search="{{ $menuPage->name.' '.$menuPage->tagLabel() }}"
           href="{{ $viewPages ? $menuPage->publicUrl() : route('page.admin.overview', ['statusPage' => $menuPage->id]) }}"
           @if ($viewPages) target="_blank" rel="noopener"
           @elseif ($selectedPageId === $menuPage->id) aria-current="page" @endif>
          <span class="pdot s-{{ $stateOf($menuPage)?->tone() ?? 'n' }}" aria-hidden="true"></span>
          <span class="page-name">
            <span class="page-title" title="{{ $menuPage->name }}">{{ $menuPage->name }}</span>
            @include('partials.page-tag', ['tagPage' => $menuPage])
            <span class="page-note">{{ \App\Services\PageStatus::label($stateOf($menuPage)) }}, {{ $viewPages ? ($menuPage->id === $selectedPageId ? 'currently selected' : 'published') : ($menuPage->is_published ? 'published' : 'draft, not public') }}</span>
          </span>
          @if ($viewPages) @include('partials.icon', ['name' => 'external', 'size' => 14]) @endif
        </a>
      @empty
        <span class="nav" aria-disabled="true">{{ $viewPages ? 'No published pages' : 'No pages assigned' }}</span>
      @endforelse
    </div>
    <p class="page-note page-search-empty" data-page-empty role="status" hidden>No matching pages</p>
  </div>
</details>
