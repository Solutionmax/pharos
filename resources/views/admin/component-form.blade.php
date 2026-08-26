@extends('layouts.admin')
@section('title', $component->exists ? 'Edit component' : 'Add a component')
@section('content')
@php $check = $component->check; @endphp
@include('partials.pagehead', [
  'title' => $component->exists ? 'Edit '.$component->name : 'Add a component',
  'back' => ['url' => route('admin.components'), 'label' => 'Components'],
])

<form method="POST" action="{{ $component->exists ? route('admin.components.update', $component) : route('admin.components.store') }}">
  @csrf
  @if ($component->exists) @method('PUT') @endif

  <div class="panel">
    <div class="panel-hd"><h3>What it is</h3></div>
    <div class="panel-bd">
      <div class="fields">
        <div class="field">
          <label for="name">Name</label>
          <input id="name" name="name" type="text" value="{{ old('name', $component->name) }}" required placeholder="s1121">
        </div>
        <div class="field">
          <label for="status">Status</label>
          <select id="status" name="status">
            @foreach (\App\Enums\ComponentStatus::cases() as $case)
              <option value="{{ $case->value }}" @selected(old('status', $component->status?->value ?? 1) == $case->value)>{{ $case->label() }}</option>
            @endforeach
          </select>
        </div>
        <div class="field">
          <label for="component_group_id">Group</label>
          <select id="component_group_id" name="component_group_id">
            <option value="">Ungrouped</option>
            @foreach ($groups as $group)
              <option value="{{ $group->id }}" @selected(old('component_group_id', $component->component_group_id) == $group->id)>{{ $group->name }}</option>
            @endforeach
          </select>
        </div>
      </div>

      <div class="field wide">
        <label for="description">Description</label>
        <input id="description" name="description" type="text" value="{{ old('description', $component->description) }}"
               placeholder="Availability of s1121.example.net">
        <span class="help">Shown under the name on the status page. Keep it in customer language.</span>
      </div>

      <div class="fields">
        <div class="field">
          <label for="link">Link</label>
          <input id="link" name="link" type="url" value="{{ old('link', $component->link) }}" placeholder="https://s1121.example.net">
        </div>
        <div class="field">
          <label for="tags">Tags</label>
          <input id="tags" name="tags" type="text" value="{{ old('tags', $component->tags) }}" placeholder="shared, nl-1, cpanel">
          <span class="help">Comma separated.</span>
        </div>
      </div>
    </div>
  </div>

  <div class="panel">
    <div class="panel-hd"><h3>How it is checked</h3><span class="hint">This is what Cachet cannot do</span></div>
    <div class="panel-bd">
      <div class="fields">
        <div class="field">
          <label for="source">Source</label>
          <select id="source" name="source">
            @foreach (['manual' => 'Manual only', 'check' => 'Built-in check', 'kuma' => 'Uptime Kuma', 'webhook' => 'Webhook / API', 'heartbeat' => 'Heartbeat', 'upstream' => 'Upstream provider'] as $value => $label)
              <option value="{{ $value }}" @selected(old('source', $component->source) === $value)>{{ $label }}</option>
            @endforeach
          </select>
        </div>
        <div class="field">
          <label for="check_type">Check</label>
          <select id="check_type" name="check_type">
            <option value="http" @selected(old('check_type', $check?->type->value) === 'http')>HTTP GET</option>
            <option value="tcp" @selected(old('check_type', $check?->type->value) === 'tcp')>TCP port</option>
          </select>
        </div>
        <div class="field">
          <label for="check_interval">Interval (seconds)</label>
          <input id="check_interval" name="check_interval" type="number" min="30" max="86400"
                 value="{{ old('check_interval', $check?->interval_seconds ?? 60) }}">
        </div>
      </div>

      <div class="field wide">
        <label for="check_target">Target</label>
        <input id="check_target" name="check_target" type="text" value="{{ old('check_target', $check?->type?->value === 'heartbeat' ? '' : $check?->target) }}"
               placeholder="https://s1121.example.net/ or db1.example.net:3306">
        <span class="help">A URL for HTTP, host:port for TCP. Leave empty for the other sources.</span>
      </div>

      @if ($check && $check->type === \App\Enums\CheckType::Heartbeat)
        <div class="callout">
          <b>Heartbeat URL.</b> Have the job call this when it finishes. Silence for two intervals is the alarm.
          <div class="mono" style="margin-top:8px;word-break:break-all">{{ url("/api/v1/heartbeat/{$check->target}") }}</div>
        </div>
      @endif

      <div class="switchrow">
        <span class="t"><strong>Component enabled</strong><span class="s">Disabled components keep their history but are not checked and not shown.</span></span>
        <label class="check"><input type="checkbox" name="enabled" value="1" @checked(old('enabled', $component->exists ? $component->enabled : true))> Enabled</label>
      </div>
      <div class="switchrow">
        <span class="t"><strong>Show the uptime bar</strong><span class="s">Turn this off where a percentage would confuse more than help.</span></span>
        <label class="check"><input type="checkbox" name="show_uptime" value="1" @checked(old('show_uptime', $component->exists ? $component->show_uptime : true))> Show</label>
      </div>

      <div class="actions">
        <button class="btn" type="submit">{{ $component->exists ? 'Save component' : 'Add component' }}</button>
        <a class="btn ghost" href="{{ route('admin.components') }}">Cancel</a>
      </div>
    </div>
  </div>
</form>
@endsection
