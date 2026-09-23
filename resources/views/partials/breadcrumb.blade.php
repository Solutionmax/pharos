{{-- Where you are: "Page name › Group › Screen".

     Usage, directly or through partials.pagehead:
       @include('partials.breadcrumb', ['crumbs' => ['Appearance', 'Layout']])
       @include('partials.pagehead', ['title' => 'Layout', 'crumbs' => ['Appearance', 'Layout']])
     For a page scoped screen the selected page's name is put in front
     automatically. Installation screens pass 'crumbScope' => 'installation'
     and start with "Installation" instead:
       @include('partials.pagehead', ['title' => 'Users', 'crumbs' => ['Users'], 'crumbScope' => 'installation'])
     The last crumb is the current screen. Crumbs are plain text: the sidebar
     already links every level, so a trail of links would only repeat it. --}}
@php
  $crumbTrail = ($crumbScope ?? 'page') === 'installation'
      ? ['Installation', ...$crumbs]
      : [app(\App\Services\PageContext::class)->page()->name, ...$crumbs];
@endphp
<nav class="crumbs" aria-label="Breadcrumb">
  <ol>
    @foreach ($crumbTrail as $crumb)
      <li @if ($loop->last) aria-current="page" @endif>{{ $crumb }}</li>
    @endforeach
  </ol>
</nav>
