@extends('layouts.admin')
@section('title', 'Status pages')
@section('content')
<style>
.pages-table{table-layout:fixed;min-width:700px}
.pages-table th:nth-child(1){width:20%}.pages-table th:nth-child(2){width:31%}
.pages-table th:nth-child(3){width:14%}.pages-table th:nth-child(4){width:10%}
.pages-table td{overflow-wrap:anywhere}.pages-table .rowacts{flex-wrap:wrap}
</style>
@include('partials.pagehead', [
  'title' => 'Status pages',
  'sub' => $pages->count().' '.\Illuminate\Support\Str::plural('page', $pages->count()).' on this installation',
  'action' => ['url' => route('admin.pages.create'), 'label' => 'Create page'],
])

<p class="sub" style="margin-bottom:20px">Choose <strong>Manage</strong> to configure a page’s services, branding and email.
  <strong>View</strong> opens its public page in a new tab. Each page has its own services and subscribers.</p>
<div class="panel">
  <div class="panel-hd"><h3>Pages</h3><span class="hint">Archived pages keep their history and settings</span></div>
  <div class="scroll">
    <table class="pages-table">
      <thead><tr><th>Name</th><th>Address</th><th>Status</th><th>Assigned</th><th></th></tr></thead>
      <tbody>
      @foreach ($pages as $page)
        <tr>
          <td>
            <strong>{{ $page->name }}</strong>
            <div style="margin-top:6px">@include('partials.page-tag', ['tagPage' => $page])</div>
          </td>
          <td class="mono" style="font-size:13px">
            @if ($page->is_published && ! $page->archived_at)
              <a href="{{ $page->publicUrl() }}" target="_blank" rel="noopener">{{ $page->publicUrl() }}</a>
            @else
              {{ $page->publicUrl() }}
            @endif
          </td>
          <td>
            @if ($page->archived_at)
              <span class="pill off">Archived</span>
            @elseif ($page->is_published)
              <span class="pill ok">Published</span>
            @else
              <span class="pill off">Unpublished</span>
            @endif
          </td>
          <td>{{ $page->users_count }}</td>
          <td>
            <span class="rowacts">
              @unless ($page->archived_at)
                <a href="{{ route('page.admin.components', ['statusPage' => $page->id]) }}">Manage</a>
                @if ($page->is_published)
                  <a href="{{ $page->publicUrl() }}" target="_blank" rel="noopener">View @include('partials.icon', ['name' => 'external', 'size' => 12])</a>
                @endif
              @endunless
              <a href="{{ route('admin.pages.edit', $page) }}">Edit</a>
              @if (! $page->archived_at && $page->id !== $defaultPageId)
                <form method="POST" action="{{ route('admin.pages.archive', $page) }}"
                      data-confirm-title="Archive {{ $page->name }}?"
                      data-confirm="Its public page and notifications stop. Services, incidents, subscribers and settings are kept."
                      data-confirm-action="Archive page">
                  @csrf
                  <button type="submit">Archive</button>
                </form>
              @endif
            </span>
          </td>
        </tr>
      @endforeach
      </tbody>
    </table>
  </div>
</div>
@endsection
