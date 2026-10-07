@extends('layouts.admin')
@section('title', __('Incidents'))
@section('content')
@php $canEditPage = auth()->user()->canEditPage(app(\App\Services\PageContext::class)->id()); @endphp
@include('partials.pagehead', [
  'title' => __('Incidents'),
  'sub' => __('What you told customers, and when'),
  'action' => $canEditPage ? ['url' => \App\Services\PageUrls::route('admin.incidents.create'), 'label' => __('Report an incident')] : null,
])

@include('partials.page-context', [
  'contextTitle' => __('Incidents for'),
  'contextHelp' => __('Incidents reported here appear only on this page and are mailed only to its subscribers.'),
])

<div class="op-kpis">
  <div class="op-kpi {{ $summary['open'] > 0 ? 'warn' : 'good' }}">
    <span class="k">{{ __('Open now') }}</span>
    <span class="v">{{ $summary['open'] ?: __('None') }}</span>
    <span class="n">{{ $summary['total'] }} {{ __('in the archive') }}</span>
  </div>
  <div class="op-kpi">
    <span class="k">{{ __('Last 30 days') }}</span>
    <span class="v">{{ $summary['month'] }}</span>
    <span class="n">{{ $summary['automatic'] }} {{ __('opened by a check') }}</span>
  </div>
  <div class="op-kpi">
    <span class="k">{{ __('Typical time to resolve') }}</span>
    <span class="v">{{ $summary['mttr'] === null ? 'n/a' : ($summary['mttr'] >= 60 ? round($summary['mttr'] / 60, 1).'h' : $summary['mttr'].'m') }}</span>
    <span class="n">{{ __('Mean over the last 30 days') }}</span>
  </div>
  @if ($canEditPage)
    <a class="op-kpi" href="{{ \App\Services\PageUrls::route('admin.incidents.templates') }}">
      <span class="k">{{ __('Templates') }}</span>
      <span class="v" style="font-size:17px;padding-top:6px">{{ __('Ready wording') }}</span>
      <span class="n">{{ __('Manage templates →') }}</span>
    </a>
  @endif
</div>

<div class="op-card" style="margin-bottom:18px">
  <form class="op-filters" method="GET" action="{{ \App\Services\PageUrls::route('admin.incidents') }}" role="search">
    <label class="sr-only" for="incident-search">{{ __('Search by title') }}</label>
    <input id="incident-search" type="text" name="q" value="{{ $search }}" placeholder="{{ __('Search by title') }}">
    @if ($state)<input type="hidden" name="state" value="{{ $state }}">@endif
    <span class="seg">
      @foreach (['' => 'All', 'open' => 'Open', 'resolved' => 'Resolved'] as $value => $label)
        <a href="{{ \App\Services\PageUrls::route('admin.incidents', array_filter(['q' => $search, 'state' => $value])) }}"
           @if ((string) $state === (string) $value) aria-current="page" @endif>{{ __($label) }}</a>
      @endforeach
    </span>
    <button class="btn ghost op-sm" type="submit">{{ __('Search') }}</button>
  </form>
</div>

@if ($state !== 'resolved')
<section class="op-section" aria-labelledby="open-heading" style="margin-bottom:22px">
  <div class="op-section-hd"><h2 id="open-heading">{{ __('Open now') }}</h2><span class="hint">{{ $open->count() }} {{ \App\Services\Localization::plural('incident', $open->count()) }}</span></div>
  @forelse ($open as $incident)
    @php
      $updates = $incident->updates->sortByDesc('id')->values();
      $latest = $updates->first();
      $lonely = $updates->count() <= 1;
    @endphp
    <article class="op-inc status-{{ $incident->status->value }}" id="incident-{{ $incident->id }}">
      <header class="op-inc-hd">
        <div class="op-inc-title">
          <div class="op-pills">
            <span class="op-pill st-{{ ['1' => 'b', '2' => 'p', '3' => 'm', '4' => 'ok'][$incident->status->value] }} op-live">{{ __($incident->status->label() ?? '') }}</span>
            <span class="op-pill">{{ __($incident->impact->label() ?? '') }} {{ __('impact') }}</span>
            @if ($incident->visibility !== 'public')<span class="op-pill">{{ $incident->visibility === 'internal' ? __('Internal') : __('Signed in only') }}</span>@endif
            @if ($incident->source !== 'manual')<span class="op-pill">{{ $incident->source === 'check' ? __('Opened by a check') : __('From the API') }}</span>@endif
            @if ($lonely)<span class="op-pill st-w">{{ __('Awaiting an update') }}</span>@endif
            @if ($incident->grouping_key && ($repeats[$incident->grouping_key] ?? 0) > 1)<span class="op-pill st-b">{{ $repeats[$incident->grouping_key] }} {{ __('occurrences in 30 days') }}</span>@endif
          </div>
          <h3>@if ($canEditPage)<a href="{{ \App\Services\PageUrls::route('admin.incidents.update-form', $incident) }}">{{ $incident->name }}</a>@else{{ $incident->name }}@endif</h3>
          <p class="op-when">
            <span>{{ __('Started') }} <time datetime="{{ $incident->occurred_at->toIso8601String() }}">{{ $incident->occurred_at->translatedFormat('j M H:i') }}</time> ({{ $incident->occurred_at->diffForHumans() }})</span>
            @if ($latest)<span class="op-dim">{{ __('· last update') }} {{ $latest->created_at->diffForHumans() }}</span>@endif
          </p>
        </div>
      </header>
      @if ($updates->isNotEmpty())
        <div class="op-inc-msg">
          <ol class="op-tl">
            @foreach ($updates->take(3) as $update)
              <li><span class="t"><b>{{ __($update->status->label() ?? '') }}</b>{{ $update->created_at->translatedFormat('j M H:i') }}@if ($update->automatic) {{ __('· automatic') }} @endif</span><div class="md">{!! $update->messageHtml() !!}</div></li>
            @endforeach
          </ol>
          @if ($updates->count() > 3)<p class="op-dim" style="margin-top:8px">{{ $updates->count() - 3 }} {{ __('earlier') }} {{ \App\Services\Localization::plural('update', $updates->count() - 3) }} {{ __('in the full timeline.') }}</p>@endif
        </div>
      @endif
      <footer class="op-inc-ft">
        <span class="op-chips">
          @forelse ($incident->components as $component)
            @php $pivotStatus = \App\Enums\ComponentStatus::tryFrom((int) $component->pivot?->getAttribute('status')); @endphp
            <span class="op-chip st-{{ $pivotStatus?->tone() ?? 'off' }}" title="{{ __($pivotStatus?->label() ?? '') }}"><i></i>{{ $component->name }}</span>
          @empty
            <span class="op-dim">{{ __('No affected components') }}</span>
          @endforelse
        </span>
      </footer>
      @if ($canEditPage)
        <input type="checkbox" class="sr-only op-toggle" id="quick-{{ $incident->id }}" aria-controls="quick-form-{{ $incident->id }}" @checked($errors->any() && old('incident_id') == $incident->id)>
        <div class="op-quick-row op-quick">
          <label class="btn op-sm" for="quick-{{ $incident->id }}">{{ __('Post update') }}</label>
          <form method="POST" action="{{ \App\Services\PageUrls::route('admin.incidents.resolve', $incident) }}"
                data-confirm-title="Resolve {{ $incident->name }}?"
                data-confirm="Posts a closing update, puts {{ $incident->components->count() ? __('its ').$incident->components->count().' '.\App\Services\Localization::plural('component', $incident->components->count()) : 'nothing' }} back to operational and tells subscribers and destinations."
                data-confirm-action="{{ __('Resolve') }}">
            @csrf
            <button class="btn ghost op-sm" type="submit" style="color:var(--green-ink)">{{ __('✓ Resolve') }}</button>
          </form>
          <a class="btn ghost op-sm" href="{{ \App\Services\PageUrls::route('admin.incidents.update-form', $incident) }}">{{ __('Full timeline') }}</a>
          <span class="spacer"></span>
          <form method="POST" action="{{ \App\Services\PageUrls::route('admin.incidents.destroy', $incident) }}"
                data-confirm-title="Delete {{ $incident->name }}?"
                data-confirm="It disappears from the public page along with its {{ $updates->count() }} {{ \App\Services\Localization::plural('update', $updates->count()) }}. Delete a false alarm; <strong>resolve</strong> a real one instead, so customers keep the record."
                data-confirm-action="{{ __('Delete incident') }}">
            @csrf @method('DELETE')
            <button class="btn ghost op-sm" type="submit">{{ __('Delete') }}</button>
          </form>
        </div>
        <form class="op-quick-body composer-panel" id="quick-form-{{ $incident->id }}" method="POST" action="{{ \App\Services\PageUrls::route('admin.incidents.update', $incident) }}">
          @csrf
          <input type="hidden" name="incident_id" value="{{ $incident->id }}">
          @include('partials.incident-status-choice', ['selectedStatus' => $incident->status->value, 'legend' => 'Status after this update'])
          <div class="field">
            <label for="quick-message-{{ $incident->id }}">{{ __('Message to customers') }}</label>
            <textarea id="quick-message-{{ $incident->id }}" name="message" rows="3" required placeholder="{{ __('What changed, and when you will post again.') }}"></textarea>
            <span class="help">{{ __('Choosing Resolved also puts the affected components back to operational.') }}</span>
          </div>
          <div class="actions"><button class="btn" type="submit">{{ __('Post update') }}</button></div>
        </form>
      @endif
    </article>
  @empty
    <div class="op-empty">
      @include('partials.icon', ['name' => 'empty', 'size' => 28])
      <b>{{ $search !== '' ? __('No open incident matches.') : __('Nothing open.') }}</b>
      <span>{{ __('That is the good outcome. Incidents you publish, and ones your checks open, appear here first.') }}</span>
    </div>
  @endforelse
</section>
@endif

@if ($state !== 'open')
<section class="op-card" aria-labelledby="history-heading">
  <header><h3 id="history-heading">{{ __('History') }}</h3><span class="hint">{{ __('Resolved, newest first') }}</span></header>
  @if ($incidents->isEmpty())
    <div class="bd"><p class="op-dim">{{ $search !== '' ? __('No resolved incident matches.') : __('No resolved incidents yet.') }}</p></div>
  @else
  <div class="scroll"><table class="op-table">
    <thead><tr><th>{{ __('Incident') }}</th><th>{{ __('Started') }}</th><th class="hide-sm">{{ __('Took') }}</th><th class="hide-sm">{{ __('Components') }}</th><th><span class="sr-only">{{ __('Actions') }}</span></th></tr></thead>
    <tbody>
    @foreach ($incidents as $incident)
      <tr>
        <td>
          <b>{{ $incident->name }}</b>
          <div class="op-pills" style="margin-top:4px">
            <span class="op-pill">{{ __($incident->impact->label() ?? '') }}</span>
            @if ($incident->visibility !== 'public')<span class="op-pill">{{ $incident->visibility === 'internal' ? __('Internal') : __('Signed in only') }}</span>@endif
            @if ($incident->source === 'check')<span class="op-pill">{{ __('By a check') }}</span>@endif
            @if ($incident->grouping_key && ($repeats[$incident->grouping_key] ?? 0) > 1)<span class="op-pill st-b">{{ $repeats[$incident->grouping_key] }} {{ __('occurrences in 30 days') }}</span>@endif
          </div>
        </td>
        <td class="num">{{ $incident->occurred_at->translatedFormat('j M Y H:i') }}</td>
        <td class="num hide-sm">{{ $incident->resolved_at ? $incident->occurred_at->diffForHumans($incident->resolved_at, \Carbon\CarbonInterface::DIFF_ABSOLUTE, true, 2) : '' }}</td>
        <td class="hide-sm">{{ $incident->components->pluck('name')->join(', ') ?: __('None') }}</td>
        <td class="right">
          @if ($canEditPage)<span class="rowacts">
            <a href="{{ \App\Services\PageUrls::route('admin.incidents.update-form', $incident) }}">{{ __('Timeline') }}</a>
            <form method="POST" action="{{ \App\Services\PageUrls::route('admin.incidents.destroy', $incident) }}"
                  data-confirm-title="Delete {{ $incident->name }}?"
                  data-confirm="It disappears from the public page along with its {{ $incident->updates->count() }} {{ \App\Services\Localization::plural('update', $incident->updates->count()) }}. Delete a false alarm only; customers keep the record of a real one."
                  data-confirm-action="{{ __('Delete incident') }}">
              @csrf @method('DELETE')
              <button type="submit">{{ __('Delete') }}</button>
            </form>
          </span>@endif
        </td>
      </tr>
    @endforeach
    </tbody>
  </table></div>
  @if ($incidents->hasPages())<div class="bd">{{ $incidents->links('vendor.pagination.pharos', ['previousLabel' => __('Newer'), 'nextLabel' => __('Older')]) }}</div>@endif
  @endif
</section>
@endif
@endsection
