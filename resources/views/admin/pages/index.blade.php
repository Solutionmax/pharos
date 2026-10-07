@extends('layouts.admin')
@section('title', __('Status pages'))
@section('content')
@include('partials.pagehead', [
  'crumbs' => ['Status pages'],
  'crumbScope' => 'installation',
  'title' => __('Status pages'),
  'sub' => $pageLimit === null
      ? $pages->count().' '.\App\Services\Localization::plural('page', $pages->count()).' on this installation'
      : $activePages.' of '.$pageLimit.' pages in use',
  'actions' => $pageLimit !== null && $activePages >= $pageLimit
      ? [['url' => \App\Services\PageUrls::route('admin.branding').'#plan', 'label' => __('Page limit reached, see plans'), 'ghost' => true]]
      : [['url' => route('admin.pages.create'), 'label' => __('Create page')]],
])

<p class="sub" style="margin-bottom:20px">{{ __('Choose') }} <strong>{{ __('Manage') }}</strong> {{ __('to work on a page: its overview, services, branding and email.') }}
  <strong>{{ __('View') }}</strong> {{ __('opens its public page in a new tab. Each page has its own services and subscribers. Archived pages keep their history and settings; a deleted page is gone for good.') }}</p>

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
              <span class="pill off">{{ __('Archived') }}</span>
            @elseif ($page->is_published)
              <span class="pill ok">{{ __('Published') }}</span>
            @else
              <span class="pill off">{{ __('Unpublished') }}</span>
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
        <div><dt>{{ __('Uptime, 90 days') }}</dt><dd>{{ \App\Services\Uptime::format($uptimes[$page->id] ?? null) }}</dd></div>
        <div><dt>{{ __('Open incidents') }}</dt><dd class="{{ $open ? 'hot' : '' }}">{{ $open ?: __('None') }}</dd></div>
        <div><dt>{{ __('Subscribers') }}</dt>
          <dd data-subscriptions="{{ $page->id }}">
            @if ($subscriptionsOn)<span class="pill ok">{{ __('On') }}</span>@else<span class="pill off">{{ __('Off') }}</span>@endif
            <span class="pg-count">{{ (int) ($subscriberCounts[$page->id] ?? 0) }}</span>
          </dd></div>
        <div><dt>{{ __('Assigned users') }}</dt><dd>{{ $page->users_count }}</dd></div>
      </dl>

      <footer class="rowacts">
        @unless ($page->archived_at)
          <a class="primary" href="{{ route('page.admin.overview', ['statusPage' => $page->id]) }}">{{ __('Manage') }}</a>
          @if ($page->is_published)
            <a href="{{ $page->publicUrl() }}" target="_blank" rel="noopener">{{ __('View') }} @include('partials.icon', ['name' => 'external', 'size' => 12])</a>
          @endif
        @endunless
        <a href="{{ route('admin.pages.edit', $page) }}">{{ __('Edit') }}</a>
        @if (! $page->archived_at && $page->id !== $defaultPageId)
          <form method="POST" action="{{ route('admin.pages.archive', $page) }}"
                data-confirm-title="Archive {{ $page->name }}?"
                data-confirm="{{ __('Its public page and notifications stop. Services, incidents, subscribers and settings are kept.') }}"
                data-confirm-action="{{ __('Archive page') }}">
            @csrf
            <button type="submit">{{ __('Archive') }}</button>
          </form>
        @endif
        @if ($page->id !== $defaultPageId)
          @php $lost = \App\Services\PageDeletion::summary($deleteCounts[$page->id] ?? []); @endphp
          <form class="pg-del" method="POST" action="{{ route('admin.pages.destroy', $page) }}"
                data-confirm-title="Delete {{ $page->name }}?"
                data-confirm="This permanently deletes the page{!! $lost ? ' and everything on it: <strong>'.$lost.'</strong>' : ' and its settings' !!}. {{ $page->archived_at ? '' : __('Its public page stops working right away. ') }}This cannot be undone.{{ $page->archived_at ? '' : __(' To keep the history, archive the page instead.') }}"
                data-confirm-action="{{ __('Delete page') }}">
            @csrf @method('DELETE')
            <button type="submit" title="Delete {{ $page->name }}" aria-label="Delete {{ $page->name }}">@include('partials.icon', ['name' => 'trash', 'size' => 13])</button>
          </form>
        @endif
      </footer>
    </article>
  @endforeach
</div>
@endsection
