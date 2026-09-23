@extends('layouts.admin')
@section('title', 'Add update')
@section('content')
@include('partials.pagehead', [
  'title' => $incident->name,
  'sub' => 'Started '.$incident->occurred_at->format('j M Y H:i').' · '.$incident->status->label().' · '.$incident->impact->label().' impact',
  'back' => ['url' => \App\Services\PageUrls::route('admin.incidents'), 'label' => 'Incidents'],
])

<div class="op-cols">
  <div class="op-card composer-panel">
    <header class="panel-hd"><h3>Post an update</h3><span class="hint">Mailed to subscribers when the incident is public</span></header>
    <div class="bd">
      <form method="POST" action="{{ \App\Services\PageUrls::route('admin.incidents.update', $incident) }}" style="display:flex;flex-direction:column;gap:16px">
        @csrf
        <div class="field">
          @include('partials.incident-status-choice', ['selectedStatus' => $incident->status->value])
          <span class="help">Choosing Resolved also puts the affected components back to operational.</span>
        </div>
        <div class="field">
          <label for="message">Message</label>
          @include('partials.editor', ['for' => 'message'])
          <textarea id="message" name="message" rows="4" required>{{ old('message') }}</textarea>
        </div>
        <div class="actions">
          <button class="btn" type="submit">Post update</button>
          <a class="btn ghost" href="{{ \App\Services\PageUrls::route('admin.incidents') }}">Back</a>
        </div>
      </form>
    </div>
  </div>

  <div class="op-side">
    <section class="op-card" aria-labelledby="timeline-title">
      <header><h3 id="timeline-title">Timeline</h3><span class="hint">{{ $incident->updates->count() }} so far</span></header>
      <div class="bd">
        <ol class="op-tl">
          @foreach ($incident->updates as $update)
            <li class="update-entry-lite">
              <span class="t"><b>{{ $update->status->label() }}</b>{{ $update->created_at->format('j M H:i') }}@if ($update->automatic) · automatic @endif</span>
              <div class="md">{!! $update->messageHtml() !!}</div>
            </li>
          @endforeach
        </ol>
      </div>
    </section>
    @if ($incident->components->isNotEmpty())
      <section class="op-card">
        <header><h3>Affected components</h3></header>
        <div class="bd"><span class="op-chips">
          @foreach ($incident->components as $component)
            <span class="op-chip st-{{ $component->status->tone() }}"><i></i>{{ $component->name }} · {{ $component->status->label() }}</span>
          @endforeach
        </span></div>
      </section>
    @endif
  </div>
</div>
@endsection
