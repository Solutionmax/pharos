<!doctype html>
<html lang="{{ app()->getLocale() }}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ $incident->name }} · {{ app(\App\Services\PageContext::class)->page()->name }}</title>
<link rel="canonical" href="{{ \App\Services\PageUrls::route('public.incident', $incident) }}">
@include('partials.tokens')
<style>body{font-family:var(--sans);background:var(--bg);color:var(--ink);line-height:1.6;margin:0}main{max-width:760px;margin:40px auto;padding:24px}.tl-i{padding:20px 0;border-top:1px solid var(--line)}a{color:inherit}.hd{display:flex;gap:16px}</style>
</head><body><main><a href="{{ \App\Services\PageUrls::route('status') }}">← {{ __('Status page') }}</a>
<h1>{{ $incident->name }}</h1><p>{{ __($incident->status->label()) }} · {{ $incident->occurred_at->format('Y-m-d H:i T') }}</p>
@if($incident->components->isNotEmpty())<p>{{ __('Affected services') }}: {{ $incident->components->pluck('name')->join(', ') }}</p>@endif
@foreach($incident->updates as $update)<article class="tl-i"><div class="hd"><strong>{{ __($update->status->label()) }}</strong><time datetime="{{ $update->created_at->toIso8601String() }}">{{ $update->created_at->format('Y-m-d H:i T') }}</time></div><div>{!! $update->messageHtml() !!}</div></article>@endforeach
</main></body></html>
