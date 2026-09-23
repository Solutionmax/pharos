{{-- Global search (Ctrl K or Cmd K, or the Search button in the sidebar).
     Results come from admin.search and hold only what the signed in user may
     open. Before anything is typed the palette shows "Jump to" actions, built
     here from the same page roles as the menu. The behaviour lives in
     public/assets/pharos-search.js; rows are built there with textContent only. --}}
@php
  $searchStart = app(\App\Services\AdminSearch::class)->start(auth()->user(), $selectedPage?->id ?? null);
  $searchScope = $searchStart['page'];
@endphp
<div class="pui-modal search-modal" id="pharos-search" data-modal role="dialog" aria-modal="true" aria-labelledby="pharos-search-title" hidden
     data-page="{{ $searchScope['id'] ?? '' }}" data-user="{{ auth()->id() }}">
  <div class="pui-scrim" data-close></div>
  <div class="pui-sheet search-sheet">
    <h2 id="pharos-search-title" class="sr-only">Search</h2>
    <div class="search-field">
      <span class="search-glyph">@include('partials.icon', ['name' => 'search', 'size' => 20])</span>
      <input type="text" id="pharos-search-input" role="combobox" aria-expanded="false" aria-controls="pharos-search-results"
             aria-autocomplete="list" aria-label="Search pages, services, components, incidents and screens" aria-describedby="pharos-search-keys"
             autocomplete="off" autocapitalize="off" spellcheck="false" enterkeyhint="go"
             placeholder="Search or jump to" data-endpoint="{{ route('admin.search') }}" autofocus>
      <button type="button" class="search-esc" data-close aria-label="Close search"><kbd>Esc</kbd><span>Cancel</span></button>
      <span class="search-bar" aria-hidden="true"></span>
    </div>
    <div class="search-body">
      <div class="search-state" data-search-state hidden></div>
      <div id="pharos-search-results" class="search-results" role="listbox" aria-label="Search results"></div>
    </div>
    <footer class="search-foot">
      <p class="search-keys" id="pharos-search-keys">
        <span><kbd aria-label="Up">&uarr;</kbd><kbd aria-label="Down">&darr;</kbd> to move</span>
        <span><kbd>Enter</kbd> to open</span>
        <span><kbd>Esc</kbd> to close</span>
      </p>
      <button type="button" class="search-clear" data-search-clear hidden>Clear recent</button>
      @if ($searchScope)
        <p class="search-scope"><span class="search-scope-k">Current page first</span>
          <span class="page-tag page-tag--{{ $searchScope['color'] }}">{{ $searchScope['tag'] }}</span>
          <span class="search-scope-name">{{ $searchScope['name'] }}</span></p>
      @endif
    </footer>
    <p class="sr-only" data-search-status role="status" aria-live="polite"></p>
  </div>
  <script type="application/json" data-search-start>@json($searchStart['actions'])</script>
  <template data-search-icons>
    @foreach (['pages', 'components', 'services', 'incidents', 'maintenance', 'users', 'screen', 'action', 'recent', 'enter', 'search'] as $searchIcon)
      <span data-icon="{{ $searchIcon }}">@include('partials.icon', ['name' => $searchIcon, 'size' => 16])</span>
    @endforeach
  </template>
</div>
