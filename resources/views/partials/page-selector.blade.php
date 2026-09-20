@php
  $selectorUser = auth()->user();
  $selectorPages = $selectorUser->isAdmin()
      ? \App\Models\StatusPage::query()->whereNull('archived_at')->orderBy('name')->get()
      : $selectorUser->statusPages()->whereNull('archived_at')->orderBy('name')->get();
  $selectedPageId = app(\App\Services\PageContext::class)->id();
@endphp

<span class="lbl">Page</span>
@forelse ($selectorPages as $selectorPage)
  <a class="nav" href="{{ route('page.admin.components', ['statusPage' => $selectorPage->id]) }}"
     @if ($selectedPageId === $selectorPage->id) aria-current="page" @endif>
    @include('partials.icon', ['name' => 'external'])
    <span style="overflow:hidden;text-overflow:ellipsis">{{ $selectorPage->name }}</span>
  </a>
@empty
  <span class="nav" aria-disabled="true">No pages assigned</span>
@endforelse
