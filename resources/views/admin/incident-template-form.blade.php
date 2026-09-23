@extends('layouts.admin')
@section('title', $template->exists ? 'Edit template' : 'New template')
@section('content')
@include('partials.pagehead', [
  'title' => $template->exists ? 'Edit template' : 'New template',
  'sub' => $template->exists ? 'API name '.$template->slug.' stays the same' : 'The API name is made from the name and never changes',
  'back' => ['url' => \App\Services\PageUrls::route('admin.incidents.templates'), 'label' => 'Incident templates'],
])

<form method="POST" action="{{ $template->exists ? \App\Services\PageUrls::route('admin.incidents.templates.update', $template) : \App\Services\PageUrls::route('admin.incidents.templates.store') }}" class="op-card" style="max-width:760px">
  @csrf
  @if ($template->exists) @method('PUT') @endif
  <header><h3>Template</h3></header>
  <div class="bd" style="display:flex;flex-direction:column;gap:16px">
    <div class="field">
      <label for="tpl-name">Name</label>
      <input id="tpl-name" name="name" type="text" required maxlength="80" value="{{ old('name', $template->name) }}" placeholder="Mail delayed">
      <span class="help">Only your team sees this, on the report form and in this list.</span>
    </div>
    <div class="field">
      <label for="tpl-title">Incident title</label>
      <input id="tpl-title" name="title_template" type="text" required maxlength="255" value="{{ old('title_template', $template->title_template) }}" placeholder="@{{ component }} is delivering mail late">
    </div>
    <div class="field">
      <label for="tpl-body">Message to customers</label>
      <textarea id="tpl-body" name="body_template" rows="6" required placeholder="We see delays on @{{ component }}. Mail is queued, not lost. Next update within 30 minutes.">{{ old('body_template', $template->body_template) }}</textarea>
      <span class="help">Markdown works, as in any incident message.</span>
    </div>
    <div class="actions">
      <button class="btn" type="submit">Save template</button>
      <a class="btn ghost" href="{{ \App\Services\PageUrls::route('admin.incidents.templates') }}">Cancel</a>
    </div>
  </div>
</form>
@endsection
