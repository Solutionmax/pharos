@extends('layouts.admin')
@section('title', 'Services')
@section('content')
@include('partials.pagehead', array_filter([
  'title' => 'Services',
  'sub' => 'The headings your customers read. Components live inside them.',
  'action' => ['url' => route('admin.groups.create', array_filter(['from' => $from])), 'label' => 'Add a service'],
  'back' => $origin,
]))

<div class="panel">
  <div class="panel-hd"><h3>Your services</h3><span class="hint">Order here is the order on the page</span></div>
  @if ($groups->isEmpty())
    <div class="empty">
      No services yet. <a href="{{ route('admin.groups.create', array_filter(['from' => $from])) }}">Add one</a>, then put components in it.
    </div>
  @else
  <div class="scroll">
    <table>
      <thead><tr><th>Service</th><th>Components</th><th>On the page</th><th>Order</th><th></th></tr></thead>
      <tbody>
      @foreach ($groups as $group)
        <tr>
          <td>
            {{ $group->name }}
            <div class="sub">{{ $group->collapsed ? 'Starts collapsed' : 'Starts open' }}</div>
          </td>
          <td class="num">{{ $group->components_count }}</td>
          <td>
            <span class="pill {{ $group->visible ? 'ok' : 'w' }}">{{ $group->visible ? 'Visible' : 'Hidden' }}</span>
          </td>
          <td>
            <span class="rowacts" style="justify-content:flex-start">
              <form method="POST" action="{{ route('admin.groups.move', $group) }}">
                @csrf <input type="hidden" name="direction" value="up">
                <button type="submit" @disabled($loop->first) aria-label="Move up">↑</button>
              </form>
              <form method="POST" action="{{ route('admin.groups.move', $group) }}">
                @csrf <input type="hidden" name="direction" value="down">
                <button type="submit" @disabled($loop->last) aria-label="Move down">↓</button>
              </form>
            </span>
          </td>
          <td>
            <span class="rowacts">
              <a href="{{ route('admin.groups.edit', array_filter(['group' => $group->id, 'from' => $from])) }}">Edit</a>
              <form method="POST" action="{{ route('admin.groups.destroy', $group) }}"
                    data-confirm-title="Delete {{ $group->name }}?"
                    data-confirm="Its {{ $group->components_count }} {{ \Illuminate\Support\Str::plural('component', $group->components_count) }} and their uptime history are <strong>kept</strong> — they move to the page without a heading. Only the grouping is lost."
                    data-confirm-action="Delete service">
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
