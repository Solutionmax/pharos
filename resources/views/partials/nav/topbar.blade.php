{{-- The menu as a top bar: six section tabs, the screens of the open section on
     a second row. Rendered next to the sidebar; the html data-layout attribute
     (a preference, with a quick switch in every page head) decides which one
     shows, so switching needs no round trip. Below 900px the sidebar drawer
     always wins: six tabs do not fit a phone. --}}
@php($sections = \App\Support\AdminMenu::sections($adminMenu))
@php($openSection = collect($sections)->firstWhere('active', true))
<header class="topnav">
  <div class="row">
    <span class="brand">@include('partials.logo', ['size' => 22])</span>
    @include('partials.page-selector', ['viewPages' => false])
    <nav class="tabs" aria-label="{{ __('Sections') }}">
      @foreach ($sections as $section)
        <a href="{{ $section['url'] }}" @if ($section['active']) aria-current="page" @endif>{{ __($section['label']) }}</a>
      @endforeach
    </nav>
    <div class="right">
      <button type="button" class="navsearch" data-search-open aria-haspopup="dialog" aria-controls="pharos-search">
        @include('partials.icon', ['name' => 'search', 'size' => 15])
        <span>{{ __('Search') }}</span>
        <kbd data-search-key>{{ __('Ctrl K') }}</kbd>
      </button>
      <a class="avatar" href="{{ route('admin.profile') }}" title="{{ auth()->user()->name }}: {{ __('Your profile') }}"
         @if(request()->routeIs('admin.profile', 'page.admin.profile')) aria-current="page" @endif>{{ mb_substr(auth()->user()->name, 0, 1) }}</a>
      <form method="POST" action="{{ route('admin.logout') }}">@csrf
        <button class="iconbtn" type="submit" title="{{ __('Sign out') }}" aria-label="{{ __('Sign out') }}">@include('partials.icon', ['name' => 'signout', 'size' => 16])</button>
      </form>
    </div>
  </div>
  @if ($openSection && count($openSection['items']) > 1)
    <nav class="row subrow" aria-label="{{ __($openSection['label']) }}">
      @foreach ($openSection['items'] as $item)
        <a href="{{ $item['url'] }}" @if ($item['active']) aria-current="page" @endif>{{ __($item['label']) }}</a>
      @endforeach
    </nav>
  @endif
</header>
