@extends('layouts.admin')
@section('title', 'Delivery log')
@section('content')
@include('partials.pagehead', [
  'title' => 'Delivery log',
  'sub' => 'Every message this page sent to your team, and what came back',
])
@include('admin.integrations.partials.flow', ['active' => 'out'])

<div class="ix-kpis">
  <div class="ix-kpi"><span class="k">Delivered · 7 days</span><div class="v" style="color:var(--green-ink)">{{ $counters['delivered'] }}</div></div>
  <div class="ix-kpi"><span class="k">Retrying</span><div class="v" style="color:var(--amber-ink)">{{ $counters['pending'] }}</div></div>
  <div class="ix-kpi"><span class="k">Failed · 7 days</span><div class="v" style="color:var(--red-ink)">{{ $counters['failed'] }}</div></div>
</div>

@php
  $status = $deliveryFilters['delivery_status'] ?? '';
  $keep = array_filter(['delivery_endpoint' => $deliveryFilters['delivery_endpoint'] ?? null, 'delivery_channel' => $deliveryFilters['delivery_channel'] ?? null]);
  $channels = ['generic' => 'Generic JSON', 'slack' => 'Slack', 'teams' => 'Microsoft Teams', 'discord' => 'Discord', 'telegram' => 'Telegram', 'signal' => 'Signal'];
@endphp

<section class="ix-card" id="delivery-history" aria-labelledby="log-title">
  <h2 id="log-title" class="sr-only">Delivery history · {{ $deliveries->total() }} records</h2>
  <div class="ix-filters">
    @foreach (['' => ['All', null], 'delivered' => ['Delivered', 'var(--green)'], 'pending' => ['Retrying', 'var(--amber)'], 'failed' => ['Failed', 'var(--red)']] as $value => [$label, $dot])
      <a class="ix-chip" href="{{ \App\Services\PageUrls::route('admin.integrations.log', array_filter($keep + ['delivery_status' => $value])) }}" @if ($status === $value) aria-current="page" @endif>@if ($dot)<i style="background:{{ $dot }}"></i>@endif{{ $label }}</a>
    @endforeach
    <form method="GET" action="{{ \App\Services\PageUrls::route('admin.integrations.log') }}">
      @if ($status !== '')<input type="hidden" name="delivery_status" value="{{ $status }}">@endif
      <label class="sr-only" for="delivery-endpoint">Destination</label>
      <select id="delivery-endpoint" name="delivery_endpoint"><option value="">All destinations</option>@foreach ($deliveryEndpoints as $choice)<option value="{{ $choice->id }}" @selected((string) ($deliveryFilters['delivery_endpoint'] ?? '') === (string) $choice->id)>{{ $choice->label }}</option>@endforeach</select>
      <label class="sr-only" for="delivery-channel">Channel</label>
      <select id="delivery-channel" name="delivery_channel"><option value="">All channels</option>@foreach ($channels as $value => $label)<option value="{{ $value }}" @selected(($deliveryFilters['delivery_channel'] ?? '') === $value)>{{ $label }}</option>@endforeach</select>
      <button class="btn ghost op-sm" type="submit">Filter</button>
    </form>
  </div>
  @if ($deliveries->isEmpty())
    <div class="bd"><p class="op-dim">No deliveries match these filters.</p></div>
  @else
    <div class="scroll"><table class="ix-table">
      <thead><tr><th>When</th><th>Event</th><th>Destination</th><th class="hide-sm">Attempts</th><th>Result</th></tr></thead>
      <tbody>
      @foreach ($deliveries as $delivery)
        @php
          [$tone, $word] = $delivery->sent_at ? ['ok', 'Delivered'] : ($delivery->attempts >= 6 ? ['b', 'Failed'] : ['w', 'Retrying']);
        @endphp
        <tr>
          <td class="num" style="white-space:nowrap">{{ $delivery->created_at?->setTimezone(\App\Services\Clock::timezone())->format('j M H:i') }}</td>
          <td>{{ \App\Models\WebhookEndpoint::EVENTS[$delivery->event] ?? 'Incident event' }}</td>
          <td><b>{{ $delivery->endpoint?->label ?? 'Removed' }}</b><div class="op-dim">{{ $channels[$delivery->endpoint?->format] ?? '' }}</div></td>
          <td class="num hide-sm">{{ $delivery->attempts }}</td>
          <td><span class="ix-state {{ $tone }}">{{ $word }}{{ $delivery->last_status && ! $delivery->sent_at ? ' · '.$delivery->last_status : '' }}</span>
            @if ($canEditIntegrations && $delivery->error && ! $delivery->sent_at)<div class="op-dim" style="margin-top:3px">{{ $delivery->error }}</div>@endif</td>
        </tr>
      @endforeach
      </tbody>
    </table></div>
    @if ($deliveries->hasPages())<div class="bd">{{ $deliveries->links('vendor.pagination.pharos', ['previousLabel' => 'Newer', 'nextLabel' => 'Older']) }}</div>@endif
  @endif
</section>

<x-note id="integrations.delivery" style="margin-top:18px">Messages are queued and sent by the minute scheduler. Temporary failures retry up to six times with growing pauses; Send test on the Send out screen makes one immediate attempt.</x-note>
@endsection
