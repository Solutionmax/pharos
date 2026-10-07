@php
  $period = \Carbon\CarbonImmutable::createFromFormat('!Y-m', $report['month'], 'UTC')->translatedFormat('F Y');
  $withData = collect($report['rows'])->whereNotNull('uptime')->count();
@endphp
<div class="report">
  <section class="report-card" aria-label="{{ __('Month') }}">
    <form method="GET" class="report-toolbar">
      @include('partials.month-picker')
      <button type="submit" class="btn">{{ __('Show report') }}</button>
      <div class="report-exports">
        <a class="btn ghost" href="{{ \App\Services\PageUrls::route($admin ? 'admin.reports.csv' : 'public.reports.csv', ['month' => $report['month']]) }}">{{ __('Download CSV') }}</a>
        <a class="btn ghost" href="{{ \App\Services\PageUrls::route($admin ? 'admin.reports.pdf' : 'public.reports.pdf', ['month' => $report['month']]) }}">{{ __('Download PDF') }}</a>
      </div>
    </form>
  </section>
  <div class="report-summary">
    <div class="report-card report-stat"><span class="k">{{ __('Month') }}</span><strong>{{ $period }}</strong><small>UTC</small></div>
    <div class="report-card report-stat"><span class="k">{{ __('Services') }}</span><strong>{{ count($report['rows']) }}</strong></div>
    <div class="report-card report-stat"><span class="k">{{ __('Services with data') }}</span><strong>{{ $withData }}</strong><small>/ {{ count($report['rows']) }}</small></div>
  </div>
  <section class="report-card" aria-labelledby="report-services">
    <header class="report-section-title"><h2 id="report-services">{{ __('Services') }}</h2><span>{{ $period }} · UTC</span></header>
    <div class="report-scroll" tabindex="0" role="region" aria-label="{{ __('Monthly uptime report') }}">
      <table class="report-table">
        <thead><tr><th scope="col">{{ __('Service') }}</th><th scope="col">{{ __('Uptime') }}</th><th scope="col">{{ __('Coverage') }}</th><th scope="col">{{ __('Up seconds') }}</th><th scope="col">{{ __('Down seconds') }}</th><th scope="col">{{ __('Excluded maintenance seconds') }}</th><th scope="col">{{ __('Unobserved seconds') }}</th></tr></thead>
        <tbody>
          @forelse($report['rows'] as $row)
            <tr>
              <td>{{ $row['name'] }}</td>
              <td><span class="report-value {{ $row['uptime'] === null ? 'unknown' : ($row['down_seconds'] > 0 ? 'down' : '') }}">{{ $row['uptime'] === null ? __('No data') : number_format($row['uptime'], 2).'%' }}</span></td>
              <td>{{ $row['coverage'] === null ? __('No data') : number_format($row['coverage'], 2).'%' }}</td>
              <td>{{ number_format($row['up_seconds']) }}</td><td>{{ number_format($row['down_seconds']) }}</td><td>{{ number_format($row['excluded_seconds']) }}</td><td>{{ number_format($row['unobserved_seconds']) }}</td>
            </tr>
          @empty
            <tr><td colspan="7">{{ __('No services') }}</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </section>
  <aside class="report-card report-note">
    <h2>{{ __('How to read this report') }}</h2>
    <p>{{ __('UTC calendar months. Uptime uses measured seconds; unobserved time is not counted as uptime. Recorded maintenance windows are excluded from coverage.') }}</p>
    <p>{{ __('Historical daily totals may include maintenance observations. They are preserved and are not reconstructed.') }}</p>
  </aside>
</div>
<script defer src="{{ asset('assets/pharos-month-picker.js') }}?v={{ filemtime(public_path('assets/pharos-month-picker.js')) }}"></script>
