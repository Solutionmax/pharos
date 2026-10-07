@extends('layouts.public-document')
@section('title', __('Email preferences'))
@section('document-class', 'narrow')
@section('subtitle')<p>{{ $subscriber->email }}</p>@endsection
@section('content')
@if(session('saved'))<p class="document-flash" role="status">{{ session('saved') }}</p>@endif
@if($errors->any())<ul class="document-errors">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>@endif
<form method="POST" action="{{ $action }}" class="document-card document-stack">
  @csrf<input type="hidden" name="all_services" value="0">
  <label class="document-check"><input type="checkbox" name="all_services" value="1" @checked($subscriber->all_services)><span>{{ __('All services, including future services') }}</span></label>
  <div class="document-services">
    <p>{{ __('Or choose the services you want to hear about:') }}</p>
    @foreach($components as $component)
      <label class="document-check"><input type="checkbox" name="component_ids[]" value="{{ $component->id }}" @checked($subscriber->components->contains($component->id))><span>{{ $component->name }}</span></label>
    @endforeach
  </div>
  <button type="submit" class="btn">{{ __('Save preferences') }}</button>
</form>
<p class="document-foot"><a href="{{ $subscriber->unsubscribeUrl() }}">{{ __('Unsubscribe') }}</a><a href="{{ \App\Services\PageUrls::route('status') }}">{{ __('Status page') }}</a></p>
@endsection
