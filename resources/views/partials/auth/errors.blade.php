{{-- Always in the page, so pharos-auth.js has a place to put the messages of an attempt it sent itself. --}}
<div class="errors pa-err" role="alert" data-pa-errors{{ $errors->any() ? '' : ' hidden' }}>
  @if ($errors->any())<ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>@endif
</div>
