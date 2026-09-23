@extends('layouts.admin')
@section('title', 'Services')
@section('content')
@php $canEditPage = auth()->user()->canEditPage(app(\App\Services\PageContext::class)->id()); @endphp
@include('partials.pagehead', array_filter([
  'title' => 'Services',
  'sub' => 'The headings your customers read. Components live inside them.',
  'action' => $canEditPage ? ['url' => \App\Services\PageUrls::route('admin.groups.create', array_filter(['from' => $from])), 'label' => 'Add a service'] : null,
  'back' => $origin,
]))

@include('partials.page-context', [
  'contextTitle' => 'Services for',
  'contextHelp' => 'Services, their order and their bars belong only to this page. Switch page to manage another set.',
])

<section class="op-card" aria-labelledby="services-title">
  <header><h3 id="services-title">Your services</h3><span class="hint">In the order the status page shows them · 30 day bars, 90 day availability</span></header>
  @if ($groups->isEmpty())
    <div class="empty">
      @include('partials.icon', ['name' => 'empty', 'size' => 28])
      <p><b>No services yet.</b></p>
      <p>Add a service, then put components in it.</p>
      @if ($canEditPage)<a class="btn" href="{{ \App\Services\PageUrls::route('admin.groups.create', array_filter(['from' => $from])) }}">Add a service</a>@endif
    </div>
  @else
  <div class="scroll">
    <table class="op-table">
      <thead><tr><th>Service</th><th>Last 30 days</th><th class="hide-sm">On the page</th>@if ($canEditPage)<th class="hide-sm">Order</th><th><span class="sr-only">Actions</span></th>@endif</tr></thead>
      <tbody>
      @foreach ($groups as $group)
        @php $groupStatus = $group->status(); @endphp
        <tr>
          <td>
            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
              <b>{{ $group->name }}</b>
              <span class="op-pill st-{{ $groupStatus->tone() }}">{{ $groupStatus->label() }}</span>
            </div>
            <div class="sub" style="margin-top:4px">{{ $group->components_count }} {{ \Illuminate\Support\Str::plural('component', $group->components_count) }} · {{ $group->collapsed ? 'starts collapsed' : 'starts open' }}</div>
            @if ($group->components->isNotEmpty())
              <div class="op-chips" style="margin-top:6px">
                @foreach ($group->components->take(8) as $component)
                  <span class="op-chip st-{{ $component->status->tone() }}" style="font-size:11px;padding:2px 8px"><i></i>{{ $component->name }}</span>
                @endforeach
                @if ($group->components->count() > 8)<span class="op-dim">and {{ $group->components->count() - 8 }} more</span>@endif
              </div>
            @endif
          </td>
          <td>@include('partials.service-history', ['serviceBar' => $serviceBars[$group->id], 'historyName' => $group->name])</td>
          <td class="hide-sm"><span class="ix-state {{ $group->visible ? 'ok' : 'w' }}">{{ $group->visible ? 'Visible' : 'Hidden' }}</span></td>
          @if ($canEditPage)
          <td class="hide-sm">
            <span class="rowacts" style="justify-content:flex-start">
              <form method="POST" action="{{ \App\Services\PageUrls::route('admin.groups.move', $group) }}">
                @csrf <input type="hidden" name="direction" value="up">
                <button type="submit" @disabled($loop->first) aria-label="Move {{ $group->name }} up">↑</button>
              </form>
              <form method="POST" action="{{ \App\Services\PageUrls::route('admin.groups.move', $group) }}">
                @csrf <input type="hidden" name="direction" value="down">
                <button type="submit" @disabled($loop->last) aria-label="Move {{ $group->name }} down">↓</button>
              </form>
            </span>
          </td>
          <td class="right">
            <span class="rowacts">
              <a href="{{ \App\Services\PageUrls::route('admin.groups.edit', array_filter(['group' => $group->id, 'from' => $from])) }}">Edit</a>
              <form method="POST" action="{{ \App\Services\PageUrls::route('admin.groups.destroy', $group) }}"
                    data-confirm-title="Delete {{ $group->name }}?"
                    data-confirm="Its {{ $group->components_count }} {{ \Illuminate\Support\Str::plural('component', $group->components_count) }} and their uptime history are <strong>kept</strong>: they move to the page without a heading. Only the grouping is lost."
                    data-confirm-action="Delete service">
                @csrf @method('DELETE')
                <button type="submit">Delete</button>
              </form>
            </span>
          </td>
          @endif
        </tr>
      @endforeach
      </tbody>
    </table>
  </div>
  @endif
</section>
<p class="op-dim" style="margin-top:12px">Statuses are changed per component, on <a class="integration-link" href="{{ \App\Services\PageUrls::route('admin.components') }}">Components</a>. A service is as healthy as its worst enabled component.</p>
@endsection
