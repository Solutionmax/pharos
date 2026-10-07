@extends('layouts.admin')
@section('title', __('Probe locations'))
@section('content')
<div class="pagehead"><h1>{{ __('Probe locations') }}</h1></div>
<p>{{ __('The local probe and each assigned location have one vote. A strict majority is required. Missing or stale locations do not vote.') }}</p>
@if (session()->has('probe.setup'))<a class="btn" href="{{ \App\Services\PageUrls::route('admin.locations.credential') }}">{{ __('Download credential file once') }}</a>@endif
<div class="panel"><div class="panel-bd"><form method="post" action="{{ \App\Services\PageUrls::route('admin.locations.store') }}">@csrf
<label>{{ __('Location name') }}<input name="name" required maxlength="100"></label>
<p>{{ __('Checks this location may probe') }}</p>
@foreach ($checks as $check)<label class="check"><input type="checkbox" name="checks[]" value="{{ $check->id }}">{{ $check->component->name }}</label>@endforeach
<button class="btn">{{ __('Add location') }}</button></form></div></div>
@foreach ($locations as $location)<div class="panel"><div class="panel-bd"><h3>{{ $location->name }}</h3><p>{{ __('Last connected') }}: {{ $location->last_seen_at?->diffForHumans() ?? __('Never') }}</p>
@foreach ($location->checks as $check)<p>{{ $check->component?->name }} · {{ $check->quorum_summary ?? __('Waiting for results') }}</p>@endforeach
<form method="post" action="{{ \App\Services\PageUrls::route('admin.locations.destroy',$location) }}">@csrf @method('DELETE')<button class="btn danger">{{ __('Remove location') }}</button></form></div></div>@endforeach
<div class="panel"><div class="panel-bd"><h3>{{ __('Set up a remote Pharos probe') }}</h3><p>{{ __('Install Pharos on the remote location, add the downloaded values to its private .env file, and schedule the following command every minute. Use HTTPS for the hub.') }}</p><code>php artisan pharos:probe-remote</code><p>{{ __('Only assigned checks are sent to that credential. Removing the location revokes it immediately.') }}</p></div></div>
@endsection
