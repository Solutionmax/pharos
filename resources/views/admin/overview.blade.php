@extends('layouts.admin')
@section('title', 'Overview')
@section('content')
@php
  use App\Enums\ComponentStatus;
  use App\Services\PageOverview;
  use App\Services\PageUrls;
  use App\Services\Uptime;

  $palette = ['ok' => 'green', 'w' => 'amber', 'p' => 'orange', 'b' => 'red', 'm' => 'blue'];
  $components = $data['components'];
  $total = $components->count();
  $worst = $data['worst'];
  $tone = $palette[$worst->tone()];
  $open = $data['open'];
  $incident = $open->first();
  $published = $page->is_published && ! $page->archived_at;
  $host = parse_url($page->publicUrl(), PHP_URL_HOST) ?: $page->publicUrl();

  // Donut: one arc per status that has components, in status order.
  $radius = 58;
  $circumference = 2 * M_PI * $radius;
  $offset = 0.0;
  $arcs = [];
  foreach (ComponentStatus::cases() as $case) {
      $n = $data['counts'][$case->value];
      if ($n === 0 || $total === 0) {
          continue;
      }
      $length = $circumference * $n / $total;
      $arcs[] = [
          'color' => $palette[$case->tone()],
          'dash' => max($length - ($n === $total ? 0 : 3), 0.1),
          'offset' => -$offset,
          'title' => $n.' '.strtolower($case->label()),
          'names' => $components->filter(fn ($c) => $c->status === $case)->pluck('name')->take(8)->implode(', '),
      ];
      $offset += $length;
  }

  // Sparkline of the page wide daily uptime; a day without data draws as the day before it.
  $sparkValues = [];
  $previous = 100.0;
  foreach ($data['days'] as $day) {
      $previous = $day['known'] ? $day['pct'] : $previous;
      $sparkValues[] = $previous;
  }
  $sparkMin = min([...$sparkValues, 99.5]);
  $sparkPath = '';
  foreach ($sparkValues as $i => $value) {
      $x = $i * (220 / max(count($sparkValues) - 1, 1));
      $y = 32 - ($value - $sparkMin) / max(100 - $sparkMin, 0.0001) * 28;
      $sparkPath .= ($i ? 'L' : 'M').number_format($x, 1, '.', '').' '.number_format($y, 1, '.', '').' ';
  }
  $cellTip = fn (array $day) => $day['known'] ? Uptime::format($day['pct']).' up' : 'No data';
  $cellDay = fn (array $day) => \Illuminate\Support\Carbon::parse($day['day'])->format('j M Y');
  $good = collect($health)->where('state', 'good')->count();
@endphp

@include('partials.pagehead', [
  'crumbs' => ['Overview'],
  'title' => 'Overview',
  'sub' => 'Everything on this page at a glance',
  'actions' => array_values(array_filter([
      $canEdit ? ['url' => PageUrls::route('admin.incidents.create'), 'label' => 'Report an incident', 'ghost' => true] : null,
      $published ? ['url' => $page->publicUrl(), 'label' => 'View status page', 'external' => true] : null,
  ])),
])

<div id="ov" class="ov" data-tips>
  @if ($readiness)
    <section class="ov-card ov-ready" aria-labelledby="ov-ready-title">
      <header><h3 id="ov-ready-title">Get this page ready</h3>
        <span class="hint">{{ collect($readiness)->where('done', true)->count() }} of {{ count($readiness) }} done</span></header>
      <div class="bd">
        <ol class="ov-steps">
          @foreach ($readiness as $step)
            <li class="{{ $step['done'] ? 'done' : '' }}">
              <div>
                <h4>{{ $step['label'] }} @if ($step['done'])<span class="sr-only">(done)</span>@endif</h4>
                <p>{{ $step['detail'] }}</p>
              </div>
              @if (! $step['done'] && $step['url'])
                <a class="btn ghost" href="{{ $step['url'] }}">{{ $step['action'] }}</a>
              @elseif (! $step['done'])
                <span class="sub">Ask an administrator</span>
              @endif
            </li>
          @endforeach
        </ol>
      </div>
    </section>
  @endif

  <section class="ov-hero" style="--st:var(--{{ $tone }});--st-soft:var(--{{ $tone }}-soft);--st-ink:var(--{{ $tone }}-ink)" aria-labelledby="ov-state-title">
    <div>
      <span class="ov-state"><span class="ov-pulse" aria-hidden="true"></span>{{ $published ? 'Live on the status page' : 'Draft, not public yet' }}</span>
      <h2 id="ov-state-title">
        @if ($total === 0) No components yet
        @elseif ($worst === ComponentStatus::Operational) All systems operational
        @else {{ $worst->label() }}
        @endif
      </h2>
      <p>
        @if ($total === 0)
          Add components to show visitors how your services are doing.
        @elseif ($worst === ComponentStatus::Operational)
          Every component is up. Visitors see a green page.
        @else
          {{ $data['affected']->pluck('name')->take(4)->implode(', ') }}@if ($data['affected']->count() > 4) and {{ $data['affected']->count() - 4 }} more @endif
          {{ $data['affected']->count() === 1 ? 'is' : 'are' }} affected.
          {{ $data['operational'] }} of {{ $total }} components are fully operational.
        @endif
      </p>
      <div class="ov-meta">
        @if ($incident)
          <span>Incident <b>{{ $incident->name }}</b></span>
          <span>Status <b>{{ $incident->status->label() }}</b></span>
          <span>Started <b>{{ $incident->occurred_at?->diffForHumans() }}</b></span>
        @else
          <span>No open incidents</span>
        @endif
        <span>Public page <b>{{ $host }}</b></span>
      </div>
    </div>
    <div class="ov-ring">
      <svg width="148" height="148" viewBox="0 0 148 148" role="img" aria-label="{{ $data['operational'] }} of {{ $total }} components operational">
        <circle r="{{ $radius }}" cx="74" cy="74" fill="none" stroke="var(--bg-tint)" stroke-width="12"/>
        @foreach ($arcs as $arc)
          <circle r="{{ $radius }}" cx="74" cy="74" fill="none" stroke="var(--{{ $arc['color'] }})" stroke-width="12"
                  stroke-dasharray="{{ number_format($arc['dash'], 2, '.', '') }} {{ number_format($circumference, 2, '.', '') }}"
                  stroke-dashoffset="{{ number_format($arc['offset'], 2, '.', '') }}" stroke-linecap="round"
                  data-tip-title="{{ $arc['title'] }}" data-tip="{{ $arc['names'] }}"/>
        @endforeach
      </svg>
      <div class="c" aria-hidden="true"><b>{{ $data['operational'] }}/{{ $total }}</b><span>operational</span></div>
    </div>
  </section>

  <div class="ov-kpis">
    <div class="ov-kpi">
      <span class="k">Uptime, 90 days</span>
      <span class="v">{{ Uptime::format($data['uptime']) }}</span>
      <span class="n">All components, averaged per component</span>
      <svg width="100%" height="34" viewBox="0 0 220 34" preserveAspectRatio="none" aria-hidden="true">
        <path d="{{ $sparkPath }}L220 34 L0 34Z" fill="var(--brand-soft)"/>
        <path d="{{ $sparkPath }}" fill="none" stroke="var(--brand)" stroke-width="2" vector-effect="non-scaling-stroke" stroke-linejoin="round"/>
      </svg>
    </div>
    <div class="ov-kpi">
      <span class="k">Open incidents</span>
      <span class="v" style="color:var(--{{ $open->isEmpty() ? 'green' : 'orange' }}-ink)">{{ $open->isEmpty() ? 'None' : $open->count() }}</span>
      <span class="n">
        @if ($incident)
          {{ $incident->status->label() }}, {{ $incident->updates->count() }} {{ \Illuminate\Support\Str::plural('update', $incident->updates->count()) }} posted
        @else
          Nothing needs attention
        @endif
      </span>
    </div>
    <div class="ov-kpi">
      <span class="k">Last 30 days</span>
      <span class="v">{{ $data['incidents30'] }} <small>{{ \Illuminate\Support\Str::plural('incident', $data['incidents30']) }}</small></span>
      <span class="n">Typical time to resolve <b>{{ PageOverview::duration($data['mttr']) }}</b></span>
    </div>
    <div class="ov-kpi">
      <span class="k">Subscribers</span>
      <span class="v">{{ $data['subscribers'] }}</span>
      <span class="n"><span class="ov-onoff {{ $data['subscriptions'] ? 'on' : 'off' }}">{{ $data['subscriptions'] ? 'On' : 'Off' }}</span>
        {{ $data['subscriptions'] ? 'Visitors can subscribe' : 'Sign up is switched off' }}</span>
    </div>
  </div>

  <div class="ov-grid">
    <div class="ov-col">
      <section class="ov-card" aria-labelledby="ov-availability">
        <header><h3 id="ov-availability">Availability</h3><span class="hint">Whole page, last 90 days, hover a day</span></header>
        <div class="bd">
          <div class="ov-days" role="img" aria-label="Daily availability over the last 90 days, {{ Uptime::format($data['uptime']) }} overall">
            @foreach ($data['days'] as $day)
              <i class="s-{{ $day['known'] ? $day['tone'] : 'n' }}" data-tip-title="{{ $cellDay($day) }}" data-tip="{{ $cellTip($day) }}"></i>
            @endforeach
          </div>
          <div class="ov-axis" aria-hidden="true"><span>90 days ago</span><span>60</span><span>30</span><span>Today</span></div>
          <div class="ov-legend">
            <span><i class="s-ok"></i>Fully up</span><span><i class="s-w"></i>Below 99.99%</span>
            <span><i class="s-p"></i>Below 99%</span><span><i class="s-b"></i>Below 95%</span><span><i class="s-n"></i>No data</span>
          </div>
        </div>
      </section>

      <section class="ov-card" aria-labelledby="ov-services">
        <header><h3 id="ov-services">Services</h3><span class="hint">30 days, uptime</span></header>
        <div class="bd">
          @forelse ($data['services'] as $section)
            <div class="ov-grp">{{ $section['name'] }}</div>
            @foreach ($section['rows'] as $row)
              @php($c = $row['component'])
              <div class="ov-row{{ $c->status === ComponentStatus::Operational ? '' : ' bad' }}">
                <span class="ov-name">
                  <span class="ov-dot s-{{ $c->status->tone() }}" role="img" aria-label="{{ $c->status->label() }}"></span>
                  @if ($canEdit)
                    <a href="{{ PageUrls::route('admin.components.edit', $c) }}"><strong>{{ $c->name }}</strong></a>
                  @else
                    <strong>{{ $c->name }}</strong>
                  @endif
                  <span class="ov-src">{{ $c->check?->type?->value ?? $c->source }}</span>
                  @unless ($c->status === ComponentStatus::Operational)<span class="ov-lbl">{{ $c->status->label() }}</span>@endunless
                </span>
                <span class="ov-mini" aria-hidden="true">
                  @foreach ($row['days'] as $day)
                    <i class="s-{{ $day['known'] ? $day['tone'] : 'n' }}" data-tip-title="{{ $cellDay($day) }}" data-tip="{{ $cellTip($day) }}"></i>
                  @endforeach
                </span>
                <span class="ov-pct">{{ $row['uptime'] === null ? 'no data' : Uptime::format($row['uptime']) }}</span>
              </div>
            @endforeach
          @empty
            <p class="sub">No components on this page yet.</p>
          @endforelse
        </div>
      </section>
    </div>

    <div class="ov-col">
      <section class="ov-card" aria-labelledby="ov-incident">
        <header><h3 id="ov-incident">{{ $incident ? 'Open incident' : 'Incidents' }}</h3>
          <a class="hint link" href="{{ PageUrls::route('admin.incidents') }}">All incidents</a></header>
        <div class="bd">
          @if ($incident)
            <div class="ov-inc">
              <div class="ov-pills">
                <span class="ov-pill st">{{ $incident->status->label() }}</span>
                @if ($incident->impact)<span class="ov-pill">{{ ucfirst($incident->impact->value) }} impact</span>@endif
                @foreach ($incident->components->take(4) as $affected)<span class="ov-pill">{{ $affected->name }}</span>@endforeach
              </div>
              <h4>
                @if ($canEdit)<a href="{{ PageUrls::route('admin.incidents.update-form', $incident) }}">{{ $incident->name }}</a>@else{{ $incident->name }}@endif
              </h4>
              <ol class="ov-tl">
                @foreach ($incident->updates->take(4) as $update)
                  <li>
                    <div class="t"><b>{{ $update->status?->label() }}</b>{{ $update->created_at?->format('H:i') }}, {{ $update->created_at?->diffForHumans() }}</div>
                    <p>{{ \Illuminate\Support\Str::limit(strip_tags((string) $update->message), 220) }}</p>
                  </li>
                @endforeach
              </ol>
              @if ($open->count() > 1)
                <p class="sub" style="margin-top:12px">{{ $open->count() - 1 }} more open {{ \Illuminate\Support\Str::plural('incident', $open->count() - 1) }}.</p>
              @endif
            </div>
          @else
            <p class="sub">Nothing open.</p>
          @endif
          @if ($data['lastResolved'])
            <div class="ov-past">
              <span class="ov-dot s-ok" aria-hidden="true"></span>
              <span>{{ $data['lastResolved']->name }}</span>
              <span class="ok">Resolved {{ $data['lastResolved']->resolved_at?->diffForHumans() }}</span>
            </div>
          @endif
        </div>
      </section>

      <section class="ov-card" aria-labelledby="ov-health">
        <header><h3 id="ov-health">Page health</h3><span class="hint">{{ $good }} of {{ count($health) }} in order</span></header>
        <div class="bd">
          <ul class="ov-health">
            @foreach ($health as $check)
              <li data-health="{{ $check['key'] }}">
                <span class="ic {{ $check['state'] }}" aria-hidden="true">{{ ['good' => '✓', 'warn' => '!', 'off' => '○'][$check['state']] }}</span>
                <span>
                  <span class="t">{{ $check['label'] }} <span class="sr-only">{{ ['good' => 'in order', 'warn' => 'needs attention', 'off' => 'not in use'][$check['state']] }}</span></span>
                  <span class="d">{{ $check['detail'] }}</span>
                </span>
                @if ($check['url'])
                  <a href="{{ $check['url'] }}" @if ($check['external'] ?? false) target="_blank" rel="noopener" @endif>{{ $check['action'] }}</a>
                @else
                  <span></span>
                @endif
              </li>
            @endforeach
          </ul>
        </div>
      </section>
    </div>
  </div>
</div>
@endsection
