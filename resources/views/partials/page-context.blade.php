@php($contextPage = app(\App\Services\PageContext::class)->page())
<div class="page-context">
  <div>
    @include('partials.page-tag', ['tagPage' => $contextPage])
    <h2>{{ $contextTitle }} {{ $contextPage->name }}</h2>
    <p>{{ $contextHelp }}</p>
    <p class="mono" style="margin-top:4px;overflow-wrap:anywhere">{{ $contextPage->publicUrl() }}</p>
  </div>
</div>
