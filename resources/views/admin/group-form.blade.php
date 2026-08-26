@extends('layouts.admin')
@section('title', $group->exists ? 'Edit service' : 'Add a service')
@section('content')
@include('partials.pagehead', [
  'title' => $group->exists ? 'Edit '.$group->name : 'Add a service',
  'sub' => $group->exists
      ? $group->components_count.' '.\Illuminate\Support\Str::plural('component', $group->components_count).' in this service'
      : null,
  'back' => ['url' => $listUrl, 'label' => 'Services'],
])

<form method="POST" action="{{ $group->exists
        ? route('admin.groups.update', array_filter(['group' => $group->id, 'from' => $from]))
        : route('admin.groups.store', array_filter(['from' => $from])) }}">
  @csrf
  @if ($group->exists) @method('PUT') @endif

  <div class="panel">
    <div class="panel-hd"><h3>Details</h3></div>
    <div class="panel-bd">
      <div class="field">
        <label for="name">Name</label>
        <input id="name" name="name" type="text" value="{{ old('name', $group->name) }}"
               required maxlength="80" autofocus placeholder="Shared hosting">
        <span class="help">What a customer would call it, not what you call it internally.</span>
      </div>

      <div class="switchrow">
        <span class="t">
          <strong>Show on the status page</strong>
          <span class="s">Hide it and its components are still checked, just not published.</span>
        </span>
        <span class="check">
          <input type="checkbox" name="visible" value="1" @checked(old('visible', $group->exists ? $group->visible : true))>
        </span>
      </div>

      <div class="switchrow">
        <span class="t">
          <strong>Start collapsed</strong>
          <span class="s">Folded up until a visitor opens it. Sensible for a service with many components.</span>
        </span>
        <span class="check">
          <input type="checkbox" name="collapsed" value="1" @checked(old('collapsed', $group->exists ? $group->collapsed : true))>
        </span>
      </div>

      <div class="actions">
        <button class="btn" type="submit">{{ $group->exists ? 'Save service' : 'Add service' }}</button>
        <a class="btn ghost" href="{{ $listUrl }}">Cancel</a>
      </div>
    </div>
  </div>
</form>
@endsection
