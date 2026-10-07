@extends('layouts.public-document')
@section('title', $incident->name)
@section('document-class', 'narrow')
@push('head')
<link rel="canonical" href="{{ \App\Services\PageUrls::route('public.incident', $incident) }}">
@endpush
@section('subtitle')
<p>{{ __($incident->status->label()) }} · {{ $incident->occurred_at->format('Y-m-d H:i T') }}</p>
@if($incident->components->isNotEmpty())<p>{{ __('Affected services') }}: {{ $incident->components->pluck('name')->join(', ') }}</p>@endif
@endsection
@section('content')
<div class="document-timeline">
  @foreach($incident->updates as $update)
    <article class="document-card document-update">
      <div class="hd"><strong>{{ __($update->status->label()) }}</strong><time datetime="{{ $update->created_at->toIso8601String() }}">{{ $update->created_at->format('Y-m-d H:i T') }}</time></div>
      <div class="document-message">{!! $update->messageHtml() !!}</div>
    </article>
  @endforeach
</div>
@endsection
