@extends('layouts.admin')
@section('title', 'Components')
@section('content')
@php $canEditPage = auth()->user()->canEditPage(app(\App\Services\PageContext::class)->id()); @endphp
@include('partials.pagehead', [
  'title' => 'Components',
  'sub' => 'The individual things a service is made of, and who keeps their status up to date',
  'action' => $canEditPage ? ['url' => \App\Services\PageUrls::route('admin.components.create'), 'label' => 'Add a component'] : null,
])

@include('partials.page-context', [
  'contextTitle' => 'Components for',
  'contextHelp' => 'Components and their checks belong only to this page. Switch page to manage another set.',
])

@if ($summary['total'] > 0)
  <div class="op-kpis">
    <div class="op-kpi {{ $summary['down'] > 0 ? 'bad' : ($summary['degraded'] > 0 ? 'warn' : 'good') }}">
      <span class="k">Right now</span>
      <span class="v">{{ $summary['down'] > 0 ? $summary['down'].' down' : ($summary['degraded'] > 0 ? $summary['degraded'].' degraded' : 'All good') }}</span>
      <span class="n">{{ $summary['total'] }} {{ \Illuminate\Support\Str::plural('component', $summary['total']) }} in total</span>
    </div>
    <div class="op-kpi">
      <span class="k">Uptime</span>
      <span class="v">{{ \App\Services\Uptime::format($summary['uptime']) }}</span>
      <span class="n">Average over 90 days</span>
    </div>
    <div class="op-kpi">
      <span class="k">Checked by Pharos</span>
      <span class="v">{{ $summary['checked'] }}<span style="font-size:16px;color:var(--ink-3)">/{{ $summary['total'] }}</span></span>
      <span class="n">HTTP, TCP and heartbeat checks</span>
    </div>
    <div class="op-kpi">
      <span class="k">Set from outside</span>
      <span class="v">{{ $summary['outside'] }}</span>
      <span class="n">{{ $summary['total'] - $summary['checked'] - $summary['outside'] }} set by hand</span>
    </div>
  </div>
@endif

<section class="op-card" aria-labelledby="components-title">
  <header>
    <h3 id="components-title">By service</h3>
    {{-- Two windows sit side by side and they are not the same: the cells are
         30 days, the percentage is 90. Say so once. --}}
    @unless ($components->isEmpty())<span class="hint">Cells cover 30 days · uptime is measured over 90</span>@endunless
  </header>
  @if ($components->isEmpty())
    <div class="empty">
      @include('partials.icon', ['name' => 'empty', 'size' => 28])
      <p><b>Nothing on the status page yet.</b></p>
      <p>Add a component and it appears for your customers straight away.</p>
      @if ($canEditPage)<a class="btn" href="{{ \App\Services\PageUrls::route('admin.components.create') }}">Add a component</a>@endif
    </div>
  @else
    @foreach ($sections as $section)
      <div class="op-svc">
        <div class="op-svc-hd">
          <h4>{{ $section['group']?->name ?? 'Ungrouped' }}</h4>
          <span class="op-dim">{{ $section['components']->count() }} {{ \Illuminate\Support\Str::plural('component', $section['components']->count()) }}@if ($section['group'] && ! $section['group']->getAttribute('visible')) · hidden on the page @endif</span>
          @if ($section['group'])
            @php $groupStatus = $section['group']->setRelation('components', $section['components'])->status(); @endphp
            <span class="op-pill st-{{ $groupStatus->tone() }}">{{ $groupStatus->label() }}</span>
          @endif
        </div>
        @foreach ($section['components'] as $component)
          @php
            $check = $component->check;
            $checked = $check?->enabled;
            $outside = ! $checked && in_array($component->source, ['kuma', 'webhook', 'upstream'], true);
            $strip = $strips[$component->id];
            $badDays = collect($strip)->whereIn('tone', ['b', 'p', 'w'])->count();
          @endphp
          <div class="op-row @unless ($component->enabled) op-disabled @endunless">
            <span class="op-name">
              <strong>{{ $component->name }}</strong>
              <span>{{ $component->enabled ? ($component->description ?: 'No description') : 'Disabled: not on the page' }}</span>
            </span>
            <span class="op-src">
              @if ($checked)
                <span class="op-src-tag auto"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>Checked by Pharos · {{ $check->type === \App\Enums\CheckType::Heartbeat ? 'heartbeat' : strtoupper($check->type->value) }}</span>
                @if ($canEditPage && $check->type !== \App\Enums\CheckType::Heartbeat)<small>{{ \Illuminate\Support\Str::limit($check->target, 34) }}</small>@endif
              @elseif ($outside)
                <span class="op-src-tag ext">Set from outside · {{ ['kuma' => 'Uptime Kuma', 'webhook' => 'API', 'upstream' => 'Upstream'][$component->source] }}</span>
              @else
                <span class="op-src-tag ext">Set by hand or API</span>
              @endif
            </span>
            <span class="op-mini" role="img" tabindex="0" aria-label="{{ $component->name }}, last 30 days: {{ $badDays ? $badDays.' '.\Illuminate\Support\Str::plural('day', $badDays).' with a disruption' : 'no disruptions' }}">
              @foreach ($strip as $d)<i class="{{ $d['tone'] === 'ok' ? '' : $d['tone'] }}" data-tip="{{ \Carbon\Carbon::parse($d['day'])->format('j M') }}{{ $d['known'] ? ' · '.number_format($d['pct'], 2).'%' : ' · no data' }}"></i>@endforeach
            </span>
            <span class="op-pct">{{ \App\Services\Uptime::format($uptime[$component->id]) }}</span>
            <span class="op-status st-{{ $component->status->tone() }}">
              @if ($canEditPage)
                <form method="POST" action="{{ \App\Services\PageUrls::route('admin.components.status', $component) }}" data-autosubmit>
                  @csrf @method('PUT')
                  <label class="sr-only" for="status-{{ $component->id }}">Status of {{ $component->name }}</label>
                  <select id="status-{{ $component->id }}" name="status">
                    @foreach (\App\Enums\ComponentStatus::cases() as $case)
                      <option value="{{ $case->value }}" @selected($component->status === $case)>{{ $case->label() }}</option>
                    @endforeach
                  </select>
                  <button class="btn ghost op-sm" type="submit">Set</button>
                </form>
                <span class="rowacts">
                  <a href="{{ \App\Services\PageUrls::route('admin.components.edit', $component) }}">Edit</a>
                  <form method="POST" action="{{ \App\Services\PageUrls::route('admin.components.destroy', $component) }}"
                        data-confirm-title="Delete {{ $component->name }}?"
                        data-confirm="Its <strong>{{ \App\Services\Uptime::format($uptime[$component->id]) }} uptime history</strong> is deleted with it, and it disappears from the public page. This cannot be undone."
                        data-confirm-action="Delete component">
                    @csrf @method('DELETE')
                    <button type="submit">Delete</button>
                  </form>
                </span>
              @else
                <span class="op-pill st-{{ $component->status->tone() }}">{{ $component->status->label() }}</span>
              @endif
            </span>
          </div>
        @endforeach
      </div>
    @endforeach
  @endif
</section>

<script defer src="{{ asset('assets/pharos-ops.js') }}?v=0.7.0"></script>
@endsection
