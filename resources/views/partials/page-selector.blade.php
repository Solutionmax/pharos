<details class="page-menu" aria-label="Choose a page to manage">
  <summary class="nav">
    @include('partials.icon', ['name' => 'pages'])
    <span class="page-name">{{ $selectedPage?->name ?? 'Choose a page' }}<span class="page-note">Switch page</span></span>
  </summary>
  <div class="page-options">
    @forelse ($selectorPages as $selectorPage)
      <a class="nav" href="{{ route('page.admin.components', ['statusPage' => $selectorPage->id]) }}"
         @if ($selectedPageId === $selectorPage->id) aria-current="page" @endif>
        <span class="page-name">{{ $selectorPage->name }}
          <span class="page-note">{{ $selectorPage->is_published ? 'Published' : 'Draft — not public' }}</span>
        </span>
      </a>
    @empty
      <span class="nav" aria-disabled="true">No pages assigned</span>
    @endforelse
  </div>
</details>
