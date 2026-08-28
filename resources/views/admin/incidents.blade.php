@extends('layouts.admin')
@section('title', 'Incidents')
@section('content')
@include('partials.pagehead', [
  'title' => 'Incidents',
  'sub' => 'What you told customers, and when',
  'action' => ['url' => route('admin.incidents.create'), 'label' => 'Report an incident'],
])

<div class="tiles">
  <div class="tile {{ $summary['open'] > 0 ? 'warn' : 'good' }}">
    <span class="k">Open now</span>
    <span class="v">{{ $summary['open'] ?: 'None' }}</span>
    <span class="n">{{ $incidents->total() }} in the archive</span>
  </div>
  <div class="tile">
    <span class="k">Last 30 days</span>
    <span class="v">{{ $summary['month'] }}</span>
    <span class="n">{{ $summary['automatic'] }} opened by a check</span>
  </div>
  <div class="tile">
    <span class="k">Typical time to resolve</span>
    <span class="v">{{ $summary['mttr'] === null ? '—' : ($summary['mttr'] >= 60 ? round($summary['mttr'] / 60, 1).'h' : $summary['mttr'].'m') }}</span>
    <span class="n">Mean over the last 30 days</span>
  </div>
</div>

<div class="panel">
  <form class="filters" method="GET">
    <input type="text" name="q" value="{{ $search }}" placeholder="Search by title">
    <span class="seg">
      @foreach (['' => 'All', 'open' => 'Open', 'resolved' => 'Resolved'] as $value => $label)
        <a href="{{ route('admin.incidents', array_filter(['q' => $search, 'state' => $value])) }}"
           @if((string) $state === (string) $value) aria-current="page" @endif>{{ $label }}</a>
      @endforeach
    </span>
    <button class="btn ghost" type="submit">Search</button>
  </form>

  @if ($incidents->isEmpty())
    <div class="empty">
      @include('partials.icon', ['name' => 'empty', 'size' => 28])
      <p><b>Nothing here.</b></p>
      <p>That is the good outcome. Incidents you publish, and ones your checks open, appear here.</p>
    </div>
  @else
  <div class="scroll">
    <table>
      <thead><tr><th>Incident</th><th>Status</th><th>Components</th><th>When</th><th></th></tr></thead>
      <tbody>
      @foreach ($incidents as $incident)
        <tr>
          <td>
            {{ $incident->name }}
            <div class="sub">
              {{ $incident->impact->label() }} impact ·
              {{ $incident->updates->count() }} {{ \Illuminate\Support\Str::plural('update', $incident->updates->count()) }}
              @if ($incident->updates->count() <= 1 && $incident->isOpen())
                · <span class="pill w" style="font-size:10px;padding:1px 8px">never updated</span>
              @endif
              @if ($incident->grouping_key && ($repeats[$incident->grouping_key] ?? 0) > 1)
                · <span class="pill b" style="font-size:10px;padding:1px 8px">{{ $repeats[$incident->grouping_key] }}× in 30 days</span>
              @endif
              @if ($incident->source !== 'manual') · <span class="src">{{ $incident->source }}</span> @endif
            </div>
          </td>
          <td>
            <span class="state-cell">
              <span class="state-dot {{ $incident->isOpen() ? 'p' : 'ok' }}"></span>
              <span class="txt">{{ $incident->status->label() }}</span>
            </span>
          </td>
          <td class="sub">{{ $incident->components->pluck('name')->join(', ') ?: '—' }}</td>
          <td class="num">{{ $incident->occurred_at->format('d M H:i') }}</td>
          <td>
            <span class="rowacts">
              <a href="{{ route('admin.incidents.update-form', $incident) }}">Add update</a>
              <form method="POST" action="{{ route('admin.incidents.destroy', $incident) }}"
                    data-confirm-title="Delete {{ $incident->name }}?"
                    data-confirm="It disappears from the public page along with its {{ $incident->updates->count() }} {{ \Illuminate\Support\Str::plural('update', $incident->updates->count()) }}. Delete a false alarm; <strong>resolve</strong> a real one instead, so customers keep the record."
                    data-confirm-action="Delete incident">
                @csrf @method('DELETE')
                <button type="submit">Delete</button>
              </form>
            </span>
          </td>
        </tr>
      @endforeach
      </tbody>
    </table>
  </div>
  <div style="padding:14px 20px;border-top:1px solid var(--line)">{{ $incidents->links() }}</div>
  @endif
</div>
@endsection
