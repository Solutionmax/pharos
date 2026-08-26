{{-- One header for every admin screen, so "how do I get out of here" has the
     same answer everywhere. $back is optional; pages without a parent omit it. --}}
<div class="head">
  <div style="min-width:0">
    @isset($back)
      <a href="{{ $back['url'] }}" class="backlink">← {{ $back['label'] }}</a>
    @endisset
    <h1>{{ $title }}</h1>
    @isset($sub)<span class="sub">{{ $sub }}</span>@endisset
  </div>
  <span class="act">
    @isset($action)
      <a class="btn" href="{{ $action['url'] }}">{{ $action['label'] }}</a>
    @endisset
    @include('partials.theme-toggle')
  </span>
</div>
