@extends('layouts.admin')
@section('title', 'Components')
@section('content')
@include('partials.pagehead', [
  'title' => 'Components',
  'sub' => 'The individual things a service is made of',
  'action' => ['url' => route('admin.components.create'), 'label' => 'Add a component'],
])

@if ($summary['total'] > 0)
  <div class="tiles">
    <div class="tile {{ $summary['down'] > 0 ? 'bad' : ($summary['degraded'] > 0 ? 'warn' : 'good') }}">
      <span class="k">Right now</span>
      <span class="v">{{ $summary['down'] > 0 ? $summary['down'].' down' : ($summary['degraded'] > 0 ? $summary['degraded'].' degraded' : 'All good') }}</span>
      <span class="n">{{ $summary['total'] }} {{ \Illuminate\Support\Str::plural('component', $summary['total']) }} in total</span>
    </div>
    <div class="tile">
      <span class="k">Uptime</span>
      <span class="v">{{ number_format($summary['uptime'], 2) }}%</span>
      <span class="n">Average over 90 days</span>
    </div>
    <div class="tile">
      <span class="k">Checked automatically</span>
      <span class="v">{{ $summary['checked'] }}<span style="font-size:16px;color:var(--ink-3)">/{{ $summary['total'] }}</span></span>
      <span class="n">{{ $summary['total'] - $summary['checked'] }} rely on someone noticing</span>
    </div>
  </div>
@endif

<div class="panel">
  <div class="panel-hd"><h3>All components</h3><span class="hint">Last 30 days shown</span></div>
  @if ($components->isEmpty())
    <div class="empty">
      @include('partials.icon', ['name' => 'empty', 'size' => 28])
      <p><b>Nothing on the status page yet.</b></p>
      <p>Add a component and it appears for your customers straight away.</p>
      <a class="btn" href="{{ route('admin.components.create') }}">Add a component</a>
    </div>
  @else
  <div class="scroll">
    <table>
      <thead><tr><th>Component</th><th>Source</th><th>Last 30 days</th><th>Uptime</th><th>Status</th><th></th></tr></thead>
      <tbody>
      @foreach ($components as $component)
        <tr>
          <td>
            <span style="font-weight:600">{{ $component->name }}</span>
            <div class="sub">
              {{ $component->group?->name ?? 'Ungrouped' }}@if($component->description) · {{ $component->description }}@endif
            </div>
          </td>
          <td>
            <span class="src">{{ $component->source }}</span>
            @if ($component->check)
              <div class="sub mono" style="margin-top:3px">{{ \Illuminate\Support\Str::limit($component->check->target, 26) }}</div>
            @endif
          </td>
          <td>
            <span class="strip" aria-hidden="true">
              @foreach ($strips[$component->id] as $d)<span class="{{ $d['tone'] === 'ok' ? '' : $d['tone'] }}"></span>@endforeach
            </span>
          </td>
          <td class="num">{{ number_format($uptime[$component->id], 2) }}%</td>
          <td>
            <span class="state-cell">
              <span class="state-dot {{ $component->status->tone() }}"></span>
              <span class="txt">{{ $component->status->label() }}</span>
            </span>
            @unless ($component->enabled)<div class="sub">disabled</div>@endunless
          </td>
          <td>
            <span class="rowacts">
              <a href="{{ route('admin.components.edit', $component) }}">Edit</a>
              <form method="POST" action="{{ route('admin.components.destroy', $component) }}"
                    onsubmit="return confirm('Delete {{ $component->name }}? Its uptime history goes with it.')">
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
  @endif
</div>
@endsection
