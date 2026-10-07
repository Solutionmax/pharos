@extends('layouts.admin')
@section('title', $maintenance->exists ? 'Edit maintenance' : 'Schedule maintenance')
@section('content')
@php $started = $maintenance->started_at !== null; @endphp
@include('partials.pagehead', [
  'title' => $maintenance->exists ? 'Edit maintenance' : 'Schedule maintenance',
  'sub' => $started ? 'Under way: the start, the components and the announcement are fixed now' : 'Tell customers ahead of time, and let Pharos handle the status while it runs',
  'back' => ['url' => \App\Services\PageUrls::route('admin.maintenance'), 'label' => __('Scheduled maintenance')],
])

<form method="POST" action="{{ $maintenance->exists ? \App\Services\PageUrls::route('admin.maintenance.update', $maintenance) : \App\Services\PageUrls::route('admin.maintenance.store') }}" class="op-cols">
  @csrf
  @if ($maintenance->exists) @method('PUT') @endif
  <div class="op-card">
    <header><h3>{{ __('The window') }}</h3>@include('partials.zone-hint', ['class' => 'hint'])</header>
    <div class="bd">
      <ol class="ix-steps">
        <li><div>
          <h4>{{ __('What is happening?') }}</h4>
          <span class="sub">{{ __('The title is what customers read on the status page and in the announcement.') }}</span>
          <div class="field"><label for="title">{{ __('Title') }}</label><input id="title" name="title" type="text" maxlength="160" required value="{{ old('title', $maintenance->title) }}" placeholder="{{ __('Database upgrade') }}"></div>
          <div class="field" style="margin-top:12px"><label for="message">{{ __('Message to customers') }}</label>
            @include('partials.editor', ['for' => 'message'])
            <textarea id="message" name="message" rows="4" placeholder="{{ __('What changes for them, and what to expect while it runs.') }}">{{ old('message', $maintenance->message) }}</textarea>
          </div>
        </div></li>
        <li><div>
          <h4>{{ __('When?') }}</h4>
          <span class="sub">{{ __('At the start the affected components switch to Under maintenance. At the end they go back, unless someone changed them in between.') }}</span>
          <div class="ix-row">
            <div class="field"><label for="starts_at">{{ __('Starts') }}</label><input id="starts_at" name="starts_at" type="datetime-local" required @disabled($started) value="{{ old('starts_at', $maintenance->starts_at?->format('Y-m-d\TH:i')) }}"></div>
            <div class="field"><label for="ends_at">{{ __('Ends') }}</label><input id="ends_at" name="ends_at" type="datetime-local" required value="{{ old('ends_at', $maintenance->ends_at?->format('Y-m-d\TH:i')) }}"></div>
          </div>
        </div></li>
        <li><div>
          <h4>{{ __('Who hears about it, and when?') }}</h4>
          <span class="sub">{{ __('Confirmed subscribers get one email, and destinations that chose Maintenance get the same moment. Only when subscriptions are on and the page is published.') }}</span>
          <div class="field"><label for="announce_minutes">{{ __('Announce') }}</label>
            <select id="announce_minutes" name="announce_minutes" @disabled($started) style="max-width:280px">
              @foreach (\App\Models\Maintenance::LEAD_TIMES as $minutes => $label)
                <option value="{{ $minutes }}" @selected((int) old('announce_minutes', $maintenance->announce_minutes ?? 1440) === $minutes)>{{ __($label) }}</option>
              @endforeach
            </select>
            @if ($maintenance->announced_at)<span class="help">{{ __('Announced') }} {{ $maintenance->announced_at->translatedFormat('j M H:i') }}{{ __('. Changing the times announces it again.') }}</span>@endif
          </div>
        </div></li>
      </ol>
    </div>
  </div>

  <div class="op-side">
    <div class="op-card">
      <header><h3>{{ __('Affected components') }}</h3><span class="hint">{{ __('Optional') }}</span></header>
      <div class="bd">
        @if ($components->isEmpty())
          <p class="op-dim">{{ __('No components on this page yet.') }}</p>
        @else
          <fieldset class="op-pick" @disabled($started)>
            <legend class="sr-only">{{ __('Components under maintenance') }}</legend>
            @foreach ($components->groupBy(fn ($c) => $c->group?->name ?? 'Ungrouped') as $groupName => $members)
              <span class="op-grp">{{ $groupName }}</span>
              @foreach ($members as $component)
                <label class="op-pick-row">
                  <input type="checkbox" name="components[]" value="{{ $component->id }}" @checked(in_array($component->id, array_map('intval', (array) $selected), true))>
                  <span class="state-dot {{ $component->status->tone() }}"></span>
                  <span>{{ $component->name }}</span>
                  <small>{{ __($component->status->label() ?? '') }}</small>
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
          <button class="btn" type="submit">{{ $maintenance->exists ? __('Save maintenance') : __('Schedule maintenance') }}</button>
          <a class="btn ghost" href="{{ \App\Services\PageUrls::route('admin.maintenance') }}">{{ __('Cancel') }}</a>
        </div>
      </div>
    </div>
  </div>
</form>
@endsection
