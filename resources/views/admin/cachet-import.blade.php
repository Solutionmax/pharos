@extends('layouts.admin')
@section('title', __('Import Cachet 2.x'))
@section('content')
@include('partials.pagehead', ['title' => __('Import Cachet 2.x'), 'sub' => __('Preview groups, components, incident history and email subscribers before importing into this page.')])
<section class="op-card" style="padding:24px">
<p>{{ __('Upload a local JSON export with groups, components, incidents and subscribers lists, or Cachet API response envelopes. Dates without an offset are interpreted as UTC. Existing subscribers keep their consent and preferences. No email or webhook notifications are sent.') }}</p>
<form method="POST" enctype="multipart/form-data" action="{{ \App\Services\PageUrls::route('admin.integrations.cachet.preview') }}">@csrf<label>{{ __('Cachet JSON export (maximum 4 MB)') }} <input type="file" name="export" accept=".json,application/json" required></label><button type="submit" class="btn">{{ __('Preview import') }}</button></form>
@if(isset($preview))
<h2>{{ __('Import preview') }}</h2><table class="op-table"><thead><tr><th>{{ __('Resource') }}</th><th>{{ __('Rows') }}</th></tr></thead><tbody>@foreach($preview['counts'] as $resource => $count)<tr><td>{{ __($resource) }}</td><td>{{ $count }}</td></tr>@endforeach</tbody></table>
<p>{{ __('Existing subscriber addresses to preserve') }}: {{ $preview['existing_subscribers'] }}</p>
@foreach(['groups', 'components', 'incidents'] as $resource)<details><summary>{{ __($resource) }}</summary><ul>@foreach($preview[$resource] as $name)<li>{{ $name }}</li>@endforeach</ul></details>@endforeach
<p>{{ __('This preview expires after 20 minutes. Import adds records to the selected page; existing groups, components and incidents remain.') }}</p>
<form method="POST" action="{{ \App\Services\PageUrls::route('admin.integrations.cachet.apply') }}">@csrf<input type="hidden" name="preview_token" value="{{ $previewToken }}"><button type="submit" class="btn">{{ __('Import these records') }}</button></form>
@endif
</section>
@endsection
