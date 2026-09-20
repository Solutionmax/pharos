@extends('layouts.admin')
@php($editing = $statusPage->exists)
@section('title', $editing ? 'Edit status page' : 'Create status page')
@section('content')
@include('partials.pagehead', [
  'title' => $editing ? 'Edit '.$statusPage->name : 'Create status page',
  'sub' => $editing ? 'Change its address, publication and access' : 'New pages start empty and unpublished',
])

<form method="POST" action="{{ $editing ? route('admin.pages.update', $statusPage) : route('admin.pages.store') }}">
  @csrf
  @if ($editing) @method('PUT') @endif

  <div class="panel">
    <div class="panel-hd"><h3>Page</h3></div>
    <div class="panel-bd" style="display:flex;flex-direction:column;gap:16px">
      <div class="fields">
        <div class="field">
          <label for="page-name">Name</label>
          <input id="page-name" name="name" type="text" value="{{ old('name', $statusPage->name) }}" required maxlength="255">
        </div>
        <div class="field">
          <label for="page-slug">Slug</label>
          <input id="page-slug" name="slug" type="text" value="{{ old('slug', $statusPage->slug) }}" required maxlength="100" pattern="[a-z0-9]+(?:-[a-z0-9]+)*">
          <span class="help">Lowercase letters, numbers and hyphens.</span>
        </div>
      </div>

      <div class="field">
        <label for="page-domain">Custom domain</label>
        <input id="page-domain" name="domain" type="text" value="{{ old('domain', $statusPage->domain) }}" placeholder="status.example.com" maxlength="253">
        <span class="help">Enter the host name only. Pharos does not configure DNS or certificates.</span>
      </div>

      <label class="switchrow">
        <span class="t"><strong>DNS and TLS verified</strong><span class="s">Required when a custom domain is added or changed.</span></span>
        <span class="check"><input name="domain_verified" type="checkbox" value="1" @checked(old('domain_verified'))> Confirmed</span>
      </label>

      <label class="switchrow">
        <span class="t"><strong>Publish this page</strong><span class="s">Visitors can open it and public notifications may be sent.</span></span>
        <span class="check"><input name="is_published" type="checkbox" value="1" @checked(old('is_published', $statusPage->is_published))> Published</span>
      </label>

      @if ($statusPage->archived_at)
        <label class="switchrow">
          <span class="t"><strong>Reactivate this page</strong><span class="s">Uses one place under the current Multi-page licence.</span></span>
          <span class="check"><input name="reactivate" type="checkbox" value="1" @checked(old('reactivate'))> Reactivate</span>
        </label>
      @endif
    </div>
  </div>

  <div class="panel">
    <div class="panel-hd"><h3>User assignments</h3><span class="hint">Administrators can access every page</span></div>
    <div class="panel-bd">
      @forelse ($users as $user)
        <label class="check" style="margin-bottom:10px">
          <input name="user_ids[]" type="checkbox" value="{{ $user->id }}"
                 @checked(in_array($user->id, old('user_ids', $assignedUserIds)))>
          <span>{{ $user->name }} <span class="sub">{{ $user->email }}</span></span>
        </label>
      @empty
        <p class="sub" style="margin:0">There are no ordinary users to assign.</p>
      @endforelse
    </div>
  </div>

  <div class="actions">
    <button class="btn" type="submit">{{ $editing ? 'Save page' : 'Create page' }}</button>
    <a class="btn ghost" href="{{ route('admin.pages.index') }}">Cancel</a>
  </div>
</form>
@endsection
