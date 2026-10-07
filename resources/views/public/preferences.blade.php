<!doctype html><html lang="{{ app()->getLocale() }}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>{{ __('Email preferences') }}</title>
@include('partials.tokens')<style>body{font-family:var(--sans);background:var(--bg);color:var(--ink);line-height:1.6}main{max-width:640px;margin:40px auto;padding:24px}label{display:block;margin:10px 0}button{font:inherit;padding:10px 18px}a{color:inherit}</style></head><body><main>
<h1>{{ __('Email preferences') }}</h1><p>{{ $subscriber->email }}</p>
@if(session('saved'))<p role="status">{{ session('saved') }}</p>@endif
@if($errors->any())<ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>@endif
<form method="POST" action="{{ $action }}">@csrf
<input type="hidden" name="all_services" value="0"><label><input type="checkbox" name="all_services" value="1" @checked($subscriber->all_services)> {{ __('All services, including future services') }}</label>
<p>{{ __('Or choose the services you want to hear about:') }}</p>
@foreach($components as $component)<label><input type="checkbox" name="component_ids[]" value="{{ $component->id }}" @checked($subscriber->components->contains($component->id))> {{ $component->name }}</label>@endforeach
<button type="submit">{{ __('Save preferences') }}</button></form>
<p><a href="{{ $subscriber->unsubscribeUrl() }}">{{ __('Unsubscribe') }}</a> · <a href="{{ \App\Services\PageUrls::route('status') }}">{{ __('Status page') }}</a></p>
</main></body></html>
