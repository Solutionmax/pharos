@extends('layouts.public-document')
{{-- The visitor's report: per service the uptime, the time it was down and its incidents.
     The seconds behind those numbers are in the CSV. --}}
@use('App\Services\MonthlyUptimeReport')
@use('App\Services\Uptime')
@php
  $period = \Carbon\CarbonImmutable::createFromFormat('!Y-m', $report['month'], 'UTC')->translatedFormat('F Y');
  $lead = array_filter([
      ! $notes['measured'] ? __('Nothing was measured this month.')
          : ($notes['since'] ? __('Measured since :date.', ['date' => $notes['since']->translatedFormat('j M')]) : null),
      $notes['maintenance'] ? __('Planned maintenance does not count as downtime.') : null,
  ]);
@endphp
@section('title', __('Monthly uptime report'))
@section('document-class', 'narrow')
@push('head')
<link rel="stylesheet" href="{{ asset('assets/pharos-reports.css') }}?v={{ filemtime(public_path('assets/pharos-reports.css')) }}">
@endpush
@section('subtitle')
<p>{{ $period }}</p>
@endsection
@section('content')
<div class="report report-visitor">
  <form method="GET" class="report-toolbar" aria-label="{{ __('Month') }}">
    @include('partials.month-picker')
    <button type="submit" class="btn">{{ __('Show report') }}</button>
    <div class="report-exports">
      <a class="btn ghost" href="{{ \App\Services\PageUrls::route('public.reports.csv', ['month' => $report['month']]) }}">{{ __('Download CSV') }}</a>
      <a class="btn ghost" href="{{ \App\Services\PageUrls::route('public.reports.pdf', ['month' => $report['month']]) }}">{{ __('Download PDF') }}</a>
    </div>
  </form>
  @if ($lead)
    <p class="report-lead">{{ implode(' ', $lead) }}</p>
  @endif
  <div class="report-scroll" tabindex="0" role="region" aria-label="{{ __('Monthly uptime report') }}">
    <table class="report-table">
      <thead><tr><th scope="col">{{ __('Service') }}</th><th scope="col">{{ __('Uptime') }}</th><th scope="col">{{ __('Downtime') }}</th><th scope="col">{{ __('Incidents') }}</th></tr></thead>
      <tbody>
        @forelse ($report['rows'] as $row)
          @php $incidents = (int) ($notes['incidents'][$row['id']] ?? 0); @endphp
          <tr>
            <td>{{ $row['name'] }}</td>
            <td @class(['quiet' => $row['uptime'] === null])>{{ $row['uptime'] === null ? __('No data') : Uptime::format($row['uptime']) }}</td>
            <td @class(['quiet' => $row['down_seconds'] === 0])>{{ $row['uptime'] === null ? '' : MonthlyUptimeReport::downtime($row['down_seconds']) }}</td>
            <td @class(['quiet' => $incidents === 0])>{{ $incidents }}</td>
          </tr>
        @empty
          <tr><td colspan="4">{{ __('No services') }}</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>
<script @nonce defer src="{{ asset('assets/pharos-month-picker.js') }}?v={{ filemtime(public_path('assets/pharos-month-picker.js')) }}"></script>
@endsection
