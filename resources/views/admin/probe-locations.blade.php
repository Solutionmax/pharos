@extends('layouts.admin')
@section('title', __('Probe locations'))
@section('content')
@include('partials.pagehead', ['crumbs' => ['Services', 'Probe locations'], 'title' => __('Probe locations'), 'sub' => __('The local probe and each assigned location have one vote. A strict majority is required. Missing or stale locations do not vote.')])
@if (session()->has('probe.setup'))
  <div class="flash"><a class="btn" href="{{ \App\Services\PageUrls::route('admin.locations.credential') }}">{{ __('Download credential file once') }}</a></div>
@endif
<div class="op-cols">
  <div class="op-side">
    <section class="op-card">
      <header><h3>{{ __('Probe locations') }}</h3><span class="hint">{{ $locations->count() }}</span></header>
      <div class="bd">
        @forelse ($locations as $location)
          <article class="probe-location">
            <h3>{{ $location->name }}</h3>
            <p class="op-dim">{{ __('Last connected') }}: {{ $location->last_seen_at?->diffForHumans() ?? __('Never') }}</p>
            <div class="probe-results">
              @foreach ($location->checks as $check)
                <div><strong>{{ $check->component?->name }}</strong><span>{{ $check->quorum_summary ?? __('Waiting for results') }}</span></div>
              @endforeach
            </div>
            <form method="post" action="{{ \App\Services\PageUrls::route('admin.locations.destroy', $location) }}">@csrf @method('DELETE')<button class="btn danger op-sm">{{ __('Remove location') }}</button></form>
          </article>
        @empty
          <div class="op-empty"><b>{{ __('No probe locations yet') }}</b><span>{{ __('Add a location to measure your services from another network.') }}</span></div>
        @endforelse
      </div>
    </section>
    <section class="op-card">
      <header><h3>{{ __('Add location') }}</h3></header>
      <form class="bd beta-form" method="post" action="{{ \App\Services\PageUrls::route('admin.locations.store') }}">
        @csrf
        <div class="field"><label for="location-name">{{ __('Location name') }}</label><input id="location-name" name="name" type="text" value="{{ old('name') }}" required maxlength="100"></div>
        <fieldset class="probe-checks"><legend>{{ __('Checks this location may probe') }}</legend>
          @forelse ($checks as $check)
            <label class="check"><input type="checkbox" name="checks[]" value="{{ $check->id }}" @checked(in_array($check->id, (array) old('checks', [])))><span>{{ $check->component->name }}</span></label>
          @empty
            <p class="op-dim">{{ __('No checks available') }}</p>
          @endforelse
        </fieldset>
        <div class="op-acts"><button class="btn">{{ __('Add location') }}</button></div>
      </form>
    </section>
  </div>
  <aside class="op-card">
    <header><h3>{{ __('Set up a remote Pharos probe') }}</h3></header>
    <div class="bd beta-form">
      <p class="op-dim">{{ __('Install Pharos on the remote location, add the downloaded values to its private .env file, and schedule the following command every minute. Use HTTPS for the hub.') }}</p>
      <pre><code>php artisan pharos:probe-remote</code></pre>
      <p class="op-dim">{{ __('Only assigned checks are sent to that credential. Removing the location revokes it immediately.') }}</p>
    </div>
  </aside>
</div>
@endsection
