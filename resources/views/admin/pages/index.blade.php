@extends('layouts.admin')
@section('title', 'Status pages')
@section('content')
@include('partials.pagehead', [
  'title' => 'Status pages',
  'sub' => $pages->count().' '.\Illuminate\Support\Str::plural('page', $pages->count()).' on this installation',
  'action' => ['url' => route('admin.pages.create'), 'label' => 'Create page'],
])

<div class="panel">
  <div class="panel-hd"><h3>Pages</h3><span class="hint">Archived pages keep their history and settings</span></div>
  <div class="scroll">
    <table>
      <thead><tr><th>Name</th><th>Address</th><th>Status</th><th>Assigned</th><th></th></tr></thead>
      <tbody>
      @foreach ($pages as $page)
        <tr>
          <td>
            <strong>{{ $page->name }}</strong>
            @if ($page->id === $defaultPageId)<span class="sub">— default</span>@endif
          </td>
          <td class="mono" style="font-size:13px">{{ $page->domain ?: '/status/'.$page->slug }}</td>
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
