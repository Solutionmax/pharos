@php
    $period = \Carbon\CarbonImmutable::createFromFormat('!Y-m', $report['month'], 'UTC');
    $measured = count(array_filter($report['rows'], fn ($row) => $row['uptime'] !== null));
    $accent = preg_match('/^#[a-f0-9]{6}$/i', $branding->accent()) ? $branding->accent() : '#0079d2';
@endphp
<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
<meta charset="utf-8">
<title>{{ __('Monthly uptime report') }} · {{ $pageName }}</title>
<style>
@font-face{font-family:'Plus Jakarta Sans';font-style:normal;font-weight:400;src:url('file://{{ public_path('fonts/plus-jakarta-sans-regular.ttf') }}') format('truetype')}
@font-face{font-family:'Plus Jakarta Sans';font-style:normal;font-weight:700;src:url('file://{{ public_path('fonts/plus-jakarta-sans-bold.ttf') }}') format('truetype')}
@page{margin:66pt 34pt 46pt}
body{margin:0;color:#0e1726;font-family:'Plus Jakarta Sans','DejaVu Sans',sans-serif;font-size:9pt;line-height:1.45}
h1,h2,p{margin:0}
.running-header{position:fixed;top:-43pt;left:0;right:0;padding-bottom:10pt;border-bottom:1pt solid #e8edf4;color:#667085;font-size:8pt}
.running-header b{color:#0a1729;font-size:10pt}
.running-period{float:right}
.hero{border-left:4pt solid {{ $accent }};padding:4pt 0 4pt 14pt;margin-bottom:18pt}
.eyebrow{font-size:8pt;color:#667085;text-transform:uppercase;letter-spacing:1pt;margin-bottom:4pt}
h1{font-size:25pt;line-height:1.2;font-weight:700;letter-spacing:-.6pt;color:#0a1729}
.period{font-size:11pt;color:#475467;margin-top:7pt}
.summary{width:100%;border-collapse:collapse;margin-bottom:20pt;table-layout:fixed}
.summary td{padding:12pt 14pt;background:#f2f6fb;border:3pt solid #fff;vertical-align:top}
.summary .number{font-size:21pt;font-weight:700;line-height:1.1;color:#0a1729}
.summary .label{font-size:8pt;color:#667085;margin-top:4pt}
.services{width:100%;border-collapse:collapse;table-layout:fixed}
.services thead{display:table-header-group}
.services tr{page-break-inside:avoid}
.services th{background:#0a1729;color:#fff;font-size:8pt;font-weight:700;text-align:right;padding:8pt 7pt;vertical-align:bottom}
.services th:first-child{text-align:left}
.services td{padding:7pt;border-bottom:.6pt solid #e8edf4;vertical-align:top;text-align:right;font-size:8.5pt}
.services tr.alt td{background:#f8fafc}
.services td.name{text-align:left;font-weight:700;word-wrap:break-word}
.services td.uptime{font-weight:700;color:{{ $accent }}}
.services td.muted{color:#667085;font-weight:400}
.services td.down{color:#b42318;font-weight:700}
.notes{margin-top:18pt;padding:12pt 14pt;background:#f2f6fb;border-left:2pt solid {{ $accent }};page-break-inside:avoid;color:#475467;font-size:8pt}
.notes h2{font-size:9pt;color:#0e1726;margin-bottom:6pt}
.notes p+p{margin-top:5pt}
.empty{text-align:center!important;color:#667085;padding:24pt!important}
</style>
</head>
<body>
<div class="running-header"><b>{{ $pageName }}</b><span class="running-period">{{ $report['month'] }} · UTC</span></div>
<div class="hero">
    <p class="eyebrow">{{ $pageName }}</p>
    <h1>{{ __('Monthly uptime report') }}</h1>
    <p class="period">{{ $period->translatedFormat('F Y') }} · {{ $report['month'] }} · UTC</p>
</div>
<table class="summary"><tr>
    <td><div class="number">{{ count($report['rows']) }}</div><div class="label">{{ __('Services') }}</div></td>
    <td><div class="number">{{ $measured }}</div><div class="label">{{ __('Services with data') }}</div></td>
    <td><div class="number">{{ count($report['rows']) - $measured }}</div><div class="label">{{ __('No data') }}</div></td>
</tr></table>
<table class="services">
    <thead><tr>
        <th style="width:27%">{{ __('Service') }}</th><th style="width:10%">{{ __('Uptime') }}</th><th style="width:10%">{{ __('Coverage') }}</th>
        <th style="width:12%">{{ __('Up seconds') }}</th><th style="width:12%">{{ __('Down seconds') }}</th><th style="width:15%">{{ __('Excluded maintenance seconds') }}</th><th style="width:14%">{{ __('Unobserved seconds') }}</th>
    </tr></thead>
    <tbody>
    @forelse($report['rows'] as $row)
        <tr class="{{ $loop->even ? 'alt' : '' }}">
            <td class="name">{{ $row['name'] }}</td>
            <td class="{{ $row['uptime'] === null ? 'muted' : 'uptime' }}">{{ $row['uptime'] === null ? __('No data') : number_format($row['uptime'], 2).'%' }}</td>
            <td class="{{ $row['coverage'] === null ? 'muted' : '' }}">{{ $row['coverage'] === null ? __('No data') : number_format($row['coverage'], 2).'%' }}</td>
            <td>{{ number_format($row['up_seconds']) }}</td><td class="{{ $row['down_seconds'] > 0 ? 'down' : '' }}">{{ number_format($row['down_seconds']) }}</td>
            <td>{{ number_format($row['excluded_seconds']) }}</td><td>{{ number_format($row['unobserved_seconds']) }}</td>
        </tr>
    @empty
        <tr><td colspan="7" class="empty">{{ __('No services') }}</td></tr>
    @endforelse
    </tbody>
</table>
<div class="notes">
    <h2>{{ __('How to read this report') }}</h2>
    <p>{{ __('UTC calendar months. Uptime uses measured seconds; unobserved time is not counted as uptime. Recorded maintenance windows are excluded from coverage.') }}</p>
    <p>{{ __('Historical daily totals may include maintenance observations. They are preserved and are not reconstructed.') }}</p>
</div>
</body>
</html>
