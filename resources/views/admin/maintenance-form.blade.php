@extends('layouts.admin')
@section('title', $maintenance->exists ? 'Edit maintenance' : 'Schedule maintenance')
@section('content')
@php $started = $maintenance->started_at !== null; @endphp
@include('partials.pagehead', [
  'title' => $maintenance->exists ? 'Edit maintenance' : 'Schedule maintenance',
  'sub' => $started ? 'Under way: the start, the components and the announcement are fixed now' : 'Tell customers ahead of time, and let Pharos handle the status while it runs',
  'back' => ['url' => \App\Services\PageUrls::route('admin.maintenance'), 'label' => 'Scheduled maintenance'],
])

<form method="POST" action="{{ $maintenance->exists ? \App\Services\PageUrls::route('admin.maintenance.update', $maintenance) : \App\Services\PageUrls::route('admin.maintenance.store') }}" class="op-cols">
  @csrf
  @if ($maintenance->exists) @method('PUT') @endif
  <div class="op-card">
    <header><h3>The window</h3>@include('partials.zone-hint', ['class' => 'hint'])</header>
    <div class="bd">
      <ol class="ix-steps">
        <li><div>
          <h4>What is happening?</h4>
          <span class="sub">The title is what customers read on the status page and in the announcement.</span>
          <div class="field"><label for="title">Title</label><input id="title" name="title" type="text" maxlength="160" required value="{{ old('title', $maintenance->title) }}" placeholder="Database upgrade"></div>
          <div class="field" style="margin-top:12px"><label for="message">Message to customers</label>
            @include('partials.editor', ['for' => 'message'])
            <textarea id="message" name="message" rows="4" placeholder="What changes for them, and what to expect while it runs.">{{ old('message', $maintenance->message) }}</textarea>
          </div>
        </div></li>
        <li><div>
          <h4>When?</h4>
          <span class="sub">At the start the affected components switch to Under maintenance. At the end they go back, unless someone changed them in between.</span>
          <div class="ix-row">
            <div class="field"><label for="starts_at">Starts</label><input id="starts_at" name="starts_at" type="datetime-local" required @disabled($started) value="{{ old('starts_at', $maintenance->starts_at?->format('Y-m-d\TH:i')) }}"></div>
            <div class="field"><label for="ends_at">Ends</label><input id="ends_at" name="ends_at" type="datetime-local" required value="{{ old('ends_at', $maintenance->ends_at?->format('Y-m-d\TH:i')) }}"></div>
          </div>
        </div></li>
        <li><div>
          <h4>Who hears about it, and when?</h4>
          <span class="sub">Confirmed subscribers get one email, and destinations that chose Maintenance get the same moment. Only when subscriptions are on and the page is published.</span>
          <div class="field"><label for="announce_minutes">Announce</label>
            <select id="announce_minutes" name="announce_minutes" @disabled($started) style="max-width:280px">
              @foreach (\App\Models\Maintenance::LEAD_TIMES as $minutes => $label)
                <option value="{{ $minutes }}" @selected((int) old('announce_minutes', $maintenance->announce_minutes ?? 1440) === $minutes)>{{ $label }}</option>
              @endforeach
            </select>
            @if ($maintenance->announced_at)<span class="help">Announced {{ $maintenance->announced_at->format('j M H:i') }}. Changing the times announces it again.</span>@endif
          </div>
        </div></li>
      </ol>
    </div>
  </div>

  <div class="op-side">
    <div class="op-card">
      <header><h3>Affected components</h3><span class="hint">Optional</span></header>
      <div class="bd">
        @if ($components->isEmpty())
          <p class="op-dim">No components on this page yet.</p>
        @else
          <fieldset class="op-pick" @disabled($started)>
            <legend class="sr-only">Components under maintenance</legend>
            @foreach ($components->groupBy(fn ($c) => $c->group?->name ?? 'Ungrouped') as $groupName => $members)
              <span class="op-grp">{{ $groupName }}</span>
              @foreach ($members as $component)
                <label class="op-pick-row">
                  <input type="checkbox" name="components[]" value="{{ $component->id }}" @checked(in_array($component->id, array_map('intval', (array) $selected), true))>
                  <span class="state-dot {{ $component->status->tone() }}"></span>
                  <span>{{ $component->name }}</span>
                  <small>{{ $component->status->label() }}</small>
                </label>
              @endforeach
            @endforeach
          </fieldset>
        @endif
      </div>
    </div>
    <div class="op-card">
      <div class="bd">
        <div class="actions">
          <button class="btn" type="submit">{{ $maintenance->exists ? 'Save maintenance' : 'Schedule maintenance' }}</button>
          <a class="btn ghost" href="{{ \App\Services\PageUrls::route('admin.maintenance') }}">Cancel</a>
        </div>
      </div>
    </div>
  </div>
</form>
@endsection
