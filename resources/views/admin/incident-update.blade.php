@extends('layouts.admin')
@section('title', 'Add update')
@section('content')
@include('partials.pagehead', [
  'title' => $incident->name,
  'sub' => 'Opened '.$incident->occurred_at->format('d M Y H:i').' · '.$incident->status->label(),
  'back' => ['url' => route('admin.incidents'), 'label' => 'Incidents'],
])

<div class="panel">
  <div class="panel-hd"><h3>Post an update</h3></div>
  <div class="panel-bd">
    <form method="POST" action="{{ route('admin.incidents.update', $incident) }}" style="display:flex;flex-direction:column;gap:16px">
      @csrf
      <div class="field">
        <label for="status">Status</label>
        <select id="status" name="status">
          @foreach (\App\Enums\IncidentStatus::cases() as $case)
            <option value="{{ $case->value }}" @selected($incident->status === $case)>{{ $case->label() }}</option>
          @endforeach
        </select>
        <span class="help">Choosing Resolved also puts the affected components back to operational.</span>
      </div>
      <div class="field">
        <label for="message">Message</label>
        @include('partials.editor', ['for' => 'message'])
        <textarea id="message" name="message" rows="4" required></textarea>
      </div>
      <div class="actions">
        <button class="btn" type="submit">Post update</button>
        <a class="btn ghost" href="{{ route('admin.incidents') }}">Back</a>
      </div>
    </form>
  </div>
</div>

<div class="panel">
  <div class="panel-hd"><h3>Timeline</h3><span class="hint">{{ $incident->updates->count() }} so far</span></div>
  <div class="panel-bd">
    @foreach ($incident->updates as $update)
      <div style="border-left:2px solid var(--line);padding-left:16px">
        <div style="display:flex;gap:10px;align-items:baseline;flex-wrap:wrap">
          <strong style="font-size:13px">{{ $update->status->label() }}</strong>
          <span class="mono" style="font-size:11px;color:var(--ink-3)">{{ $update->created_at->format('d M H:i') }}</span>
          @if ($update->automatic)<span class="src">automatic</span>@endif
        </div>
        <div class="md" style="font-size:13.5px;color:var(--ink-2);margin-top:2px">{!! $update->messageHtml() !!}</div>
      </div>
    @endforeach
  </div>
</div>
@endsection
