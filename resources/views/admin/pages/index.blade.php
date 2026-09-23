@extends('layouts.admin')
@section('title', 'Status pages')
@section('content')
@include('partials.pagehead', [
  'crumbs' => ['Status pages'],
  'crumbScope' => 'installation',
  'title' => 'Status pages',
  'sub' => $pages->count().' '.\Illuminate\Support\Str::plural('page', $pages->count()).' on this installation',
  'action' => ['url' => route('admin.pages.create'), 'label' => 'Create page'],
])

<p class="sub" style="margin-bottom:20px">Choose <strong>Manage</strong> to work on a page: its overview, services, branding and email.
  <strong>View</strong> opens its public page in a new tab. Each page has its own services and subscribers. Archived pages keep their history and settings.</p>

<div class="pg-cards">
  @foreach ($pages as $page)
    @php
      $state = $states[$page->id] ?? null;
      $live = $page->is_published && ! $page->archived_at;
      $open = (int) ($openIncidents[$page->id] ?? 0);
      $subscriptionsOn = ! in_array($page->id, $subscriptionsOff, true);
    @endphp
    <article class="pg-card{{ $page->archived_at ? ' archived' : '' }}" data-page-card="{{ $page->id }}" aria-labelledby="pg-name-{{ $page->id }}">
      <header>
        <div class="pg-title">
          <h3 id="pg-name-{{ $page->id }}">{{ $page->name }}</h3>
          <div class="pg-tags">
            @include('partials.page-tag', ['tagPage' => $page])
            @if ($page->archived_at)
              <span class="pill off">Archived</span>
            @elseif ($page->is_published)
              <span class="pill ok">Published</span>
            @else
              <span class="pill off">Unpublished</span>
            @endif
          </div>
        </div>
        <span class="pg-live s-tone-{{ $state?->tone() ?? 'n' }}">
          <span class="pdot s-{{ $state?->tone() ?? 'n' }}" aria-hidden="true"></span>{{ \App\Services\PageStatus::label($state) }}
        </span>
      </header>

      <p class="pg-url mono">
        @if ($live)
          <a href="{{ $page->publicUrl() }}" target="_blank" rel="noopener">{{ $page->publicUrl() }}</a>
        @else
          {{ $page->publicUrl() }}
        @endif
      </p>

      <dl class="pg-stats">
        <div><dt>Uptime, 90 days</dt><dd>{{ \App\Services\Uptime::format($uptimes[$page->id] ?? null) }}</dd></div>
        <div><dt>Open incidents</dt><dd class="{{ $open ? 'hot' : '' }}">{{ $open ?: 'None' }}</dd></div>
        <div><dt>Subscribers</dt>
          <dd data-subscriptions="{{ $page->id }}">
            @if ($subscriptionsOn)<span class="pill ok">On</span>@else<span class="pill off">Off</span>@endif
            <span class="pg-count">{{ (int) ($subscriberCounts[$page->id] ?? 0) }}</span>
          </dd></div>
        <div><dt>Assigned users</dt><dd>{{ $page->users_count }}</dd></div>
      </dl>

      <footer class="rowacts">
        @unless ($page->archived_at)
          <a class="primary" href="{{ route('page.admin.overview', ['statusPage' => $page->id]) }}">Manage</a>
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
      </footer>
    </article>
  @endforeach
</div>
@endsection
