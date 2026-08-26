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
                    onsubmit="return confirm('Delete {{ $group->name }}? Its {{ $group->components_count }} component(s) are kept but become ungrouped.')">
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

<div class="callout">
  <b>Where the demo names came from.</b> "Shared hosting", "Email" and "Network &amp; DNS" are
  seeded demo data, shaped after a real hosting company's page. Rename them, delete them, or
  start over — deleting a service keeps its components and their history, they just become
  ungrouped.
</div>
@endsection
