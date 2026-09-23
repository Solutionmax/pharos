{{-- Global search (Ctrl K or Cmd K, or the Search button in the sidebar).
     Results come from admin.search and hold only what the signed in user may
     open; the behaviour lives in public/assets/pharos-ui.js. --}}
<div class="pui-modal search-modal" id="pharos-search" data-modal role="dialog" aria-modal="true" aria-labelledby="pharos-search-title" hidden>
  <div class="pui-scrim" data-close></div>
  <div class="pui-sheet search-sheet">
    <h2 id="pharos-search-title" class="sr-only">Search</h2>
    <div class="search-field">
      @include('partials.icon', ['name' => 'search', 'size' => 18])
      <input type="text" id="pharos-search-input" role="combobox" aria-expanded="false" aria-controls="pharos-search-results"
             aria-autocomplete="list" aria-label="Search pages, services, components and incidents" autocomplete="off" spellcheck="false"
             placeholder="Search pages, services, components, incidents" data-endpoint="{{ route('admin.search') }}" autofocus>
      <button type="button" class="search-esc" data-close aria-label="Close search">Esc</button>
    </div>
    <ul id="pharos-search-results" class="search-results" role="listbox" aria-label="Search results"></ul>
    <p class="search-status" data-search-status role="status" aria-live="polite">Type at least two letters.</p>
    <p class="search-help"><kbd>&uarr;</kbd> <kbd>&darr;</kbd> to move, <kbd>Enter</kbd> to open, <kbd>Esc</kbd> to close</p>
  </div>
</div>
