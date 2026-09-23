{{-- One header for every admin screen, so "how do I get out of here" has the
     same answer everywhere. $back is optional; pages without a parent omit it.
     $crumbs (optional) adds the breadcrumb above the title; see
     partials/breadcrumb.blade.php. $actions (optional) is a list of extra
     buttons: ['url' => ..., 'label' => ..., 'ghost' => bool, 'external' => bool]. --}}
<div class="head">
  <div style="min-width:0">
    @isset($crumbs)
      @include('partials.breadcrumb', ['crumbs' => $crumbs, 'crumbScope' => $crumbScope ?? 'page'])
    @endisset
    @isset($back)
      <a href="{{ $back['url'] }}" class="backlink">← {{ $back['label'] }}</a>
    @endisset
    <h1>{{ $title }}</h1>
    @isset($sub)<span class="sub">{{ $sub }}</span>@endisset
  </div>
  <span class="act">
    @foreach ($actions ?? [] as $extra)
      <a class="btn{{ ($extra['ghost'] ?? false) ? ' ghost' : '' }}" href="{{ $extra['url'] }}" @if ($extra['external'] ?? false) target="_blank" rel="noopener" @endif>{{ $extra['label'] }}</a>
    @endforeach
    @isset($action)
      <a class="btn" href="{{ $action['url'] }}">{{ $action['label'] }}</a>
    @endisset
    @include('partials.theme-toggle')
  </span>
</div>
