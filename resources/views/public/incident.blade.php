@extends('layouts.public-document')
{{-- One incident on its own page, in the words of the status page log: what happened and
     for how long, the course strip with a dot per message, then every message, newest first. --}}
@use('App\Services\StatusTimeline')
@php
  $now = \App\Services\Clock::now();
  $tone = StatusTimeline::tone($incident);
  $open = $incident->isOpen();
  $strip = StatusTimeline::strip($incident, $now);
  $updates = $incident->updates->sortBy('created_at')->values();
  $count = $updates->count();
  $lastIndex = $count - 1;
  $start = $incident->occurred_at;
  $end = $incident->resolved_at ?? $updates->last()?->created_at;
  $isDone = fn ($update) => $update->status === \App\Enums\IncidentStatus::Resolved;
  // The clock alone on the day the incident began, the date as well on any other day.
  $at = fn ($time, $day) => $time->isSameDay($day) ? $time->format('H:i') : $time->translatedFormat('j M, H:i');
  $lead = $open ? __('Ongoing since :time', ['time' => $at($start, $now)]) : __($incident->status->label());
  $facts = array_filter([
      $open ? null : ($end ? __(':from to :to', ['from' => $start->translatedFormat('j M, H:i'), 'to' => $at($end, $start)]) : $start->translatedFormat('j M, H:i')),
      StatusTimeline::duration(StatusTimeline::incidentMinutes($incident, $now)),
      $incident->components->isEmpty() ? null : ($open ? __('Affects') : __('Affected')).' '.$incident->components->pluck('name')->join(', ', __(' and ')),
  ]);
@endphp
@section('title', $incident->name)
@section('document-class', 'narrow incident-page is-'.$tone)
@push('head')
<link rel="stylesheet" href="{{ asset('assets/pharos-status.css') }}?v={{ @filemtime(public_path('assets/pharos-status.css')) }}">
<link rel="stylesheet" href="{{ asset('assets/pharos-incident.css') }}?v={{ @filemtime(public_path('assets/pharos-incident.css')) }}">
@include('partials.share-meta', [
  'shareTitle' => $incident->name.' · '.$lead,
  'shareDescription' => \Illuminate\Support\Str::limit(implode(' · ', array_merge([$lead], $facts)).'.', 155, '…'),
  'shareUrl' => \App\Services\PageUrls::route('public.incident', $incident),
])
@endpush
@section('subtitle')
<p class="incident-facts"><b @class(['done' => ! $open])>{{ $lead }}</b>@foreach ($facts as $fact) · {{ $fact }}@endforeach</p>
@endsection
@section('content')
@if ($count > 0)
  <div class="strip{{ $count === 1 && ! $open ? ' solo' : '' }}">
    <span>{{ $strip['from'] }}</span>
    <div class="bar">
      @foreach ($strip['points'] as $n => $p)
        @php $next = $n < $lastIndex ? $strip['points'][$n + 1] : ($open ? 1 : $p); @endphp
        @if ($next > $p)<i class="seg ph{{ min($n, 2) }}" style="left:{{ $p * 100 }}%;width:{{ ($next - $p) * 100 }}%"></i>@endif
      @endforeach
      @if ($open)<i class="open"></i>@endif
      @foreach ($updates as $n => $update)
        <a class="dot{{ $isDone($update) ? ' done' : '' }}{{ $n === $lastIndex ? ' cur' : '' }}{{ $n === $lastIndex && $open ? ' beat' : '' }}"
           style="left:{{ $strip['points'][$n] * 100 }}%" href="#message-{{ $update->id }}"
           aria-label="{{ __($update->status->label()) }}, {{ $at($update->created_at, $start) }}. {{ __('Show this message.') }}"></a>
      @endforeach
      @php $newest = $updates->last(); @endphp
      <span class="at{{ $isDone($newest) ? ' done' : '' }}" style="left:{{ $strip['points'][$lastIndex] * 100 }}%">{{ __($newest->status->label()) }}</span>
    </div>
    @if ($count === 1 && ! $open)<span></span>@else<span>{{ $strip['to'] }}</span>@endif
  </div>

  <ol class="msgs">
    @foreach ($updates->reverse() as $update)
      <li id="message-{{ $update->id }}" @class(['done' => $isDone($update)])>
        <strong>{{ __($update->status->label()) }}</strong>
        <time datetime="{{ $update->created_at->toIso8601String() }}">{{ $update->created_at->isSameDay($start) ? $update->created_at->format('H:i') : $update->created_at->translatedFormat('j M H:i') }}</time>
        <div class="md">{!! $update->messageHtml() !!}@if ($update->automatic) <span class="auto">{{ __('automatic') }}</span>@endif</div>
      </li>
    @endforeach
  </ol>
@endif
@endsection
