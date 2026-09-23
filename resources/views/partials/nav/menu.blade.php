{{-- The sidebar navigation. What is listed, and for whom, lives in
     App\Support\AdminMenu so it can be tested without rendering. --}}
@php($adminMenu = \App\Support\AdminMenu::build(auth()->user(), request()))
<button type="button" class="navsearch" data-search-open aria-haspopup="dialog" aria-controls="pharos-search">
  @include('partials.icon', ['name' => 'search', 'size' => 16])
  <span>Search</span>
  <kbd data-search-key>Ctrl K</kbd>
</button>

<span class="lbl">This page</span>
@include('partials.page-selector', ['viewPages' => false])
<nav class="navlist" aria-label="This page">
  @foreach ($adminMenu['page'] as $item)
    @include('partials.nav.item', ['item' => $item])
  @endforeach
</nav>

@if ($adminMenu['installation'])
  <span class="lbl">Installation</span>
  <nav class="navlist" aria-label="Installation">
    @foreach ($adminMenu['installation'] as $item)
      @include('partials.nav.item', ['item' => $item])
    @endforeach
  </nav>
@endif
