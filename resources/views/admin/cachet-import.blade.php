@extends('layouts.admin')
@section('title', __('Import Cachet 2.x'))
@section('content')
@include('partials.pagehead', ['crumbs' => ['Integrations & API', 'Import Cachet 2.x'], 'title' => __('Import Cachet 2.x'), 'sub' => __('Preview groups, components, incident history and email subscribers before importing into this page.')])
<section class="op-card">
  <header><h3>{{ __('Cachet JSON export (maximum 4 MB)') }}</h3></header>
  <div class="bd beta-form">
    <p class="op-dim">{{ __('Upload a local JSON export with groups, components, incidents and subscribers lists, or Cachet API response envelopes. Dates without an offset are interpreted as UTC. Existing subscribers keep their consent and preferences. No email or webhook notifications are sent.') }}</p>
    <form class="beta-form" method="POST" enctype="multipart/form-data" action="{{ \App\Services\PageUrls::route('admin.integrations.cachet.preview') }}">
      @csrf
      <div class="field"><label for="cachet-export">{{ __('Cachet JSON export (maximum 4 MB)') }}</label><input id="cachet-export" type="file" name="export" accept=".json,application/json" required></div>
      <div class="op-acts"><button type="submit" class="btn">{{ __('Preview import') }}</button></div>
    </form>
  </div>
</section>
@if(isset($preview))
  <section class="op-card">
    <header><h3>{{ __('Import preview') }}</h3></header>
    <div class="scroll"><table class="op-table"><thead><tr><th scope="col">{{ __('Resource') }}</th><th scope="col">{{ __('Rows') }}</th></tr></thead><tbody>@foreach($preview['counts'] as $resource => $count)<tr><td>{{ __($resource) }}</td><td class="num">{{ $count }}</td></tr>@endforeach</tbody></table></div>
    <div class="bd beta-form">
      <p class="op-dim">{{ __('Existing subscriber addresses to preserve') }}: {{ $preview['existing_subscribers'] }}</p>
      @foreach(['groups', 'components', 'incidents'] as $resource)
        <details class="beta-disclosure"><summary>{{ __($resource) }}<span class="beta-chevron" aria-hidden="true">⌄</span></summary><div class="beta-form"><ul class="import-names">@foreach($preview[$resource] as $name)<li>{{ $name }}</li>@endforeach</ul></div></details>
      @endforeach
      <p class="op-dim">{{ __('This preview expires after 20 minutes. Import adds records to the selected page; existing groups, components and incidents remain.') }}</p>
      <form method="POST" action="{{ \App\Services\PageUrls::route('admin.integrations.cachet.apply') }}">
        @csrf<input type="hidden" name="preview_token" value="{{ $previewToken }}"><button type="submit" class="btn">{{ __('Import these records') }}</button>
      </form>
    </div>
  </section>
@endif
@endsection
