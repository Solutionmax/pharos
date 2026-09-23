@extends('layouts.admin')
@section('title', 'Audit log')
@section('content')
@include('partials.pagehead', [
  'title' => 'Audit log',
  'sub' => 'Who changed what, and when',
])
@php
  $filtered = collect($filters)->filter(fn ($value) => $value !== '' && $value !== null)->isNotEmpty();
  $exportQuery = array_filter($filters, fn ($value) => $value !== '' && $value !== null);
@endphp

<section class="op-card" aria-labelledby="activity-title">
  <header>
    <h3 id="activity-title">Activity</h3>
    <span class="hint">{{ $entries->total() }} recorded · kept {{ $retentionDays }} days</span>
  </header>

  <form class="op-filters" method="GET" action="{{ route('admin.audit') }}" role="search">
    <label class="sr-only" for="actor">Who, by name, email or token</label>
    <input id="actor" name="actor" type="text" value="{{ $actor }}" placeholder="Name, email or token">
    <label class="sr-only" for="audit-user">Person</label>
    <select id="audit-user" name="user">
      <option value="">Everyone</option>
      @foreach ($people as $person)<option value="{{ $person->id }}" @selected($filters['user'] === $person->id)>{{ $person->name }}</option>@endforeach
    </select>
    <label class="sr-only" for="audit-page">Status page</label>
    <select id="audit-page" name="page_id">
      <option value="">Every page</option>
      @foreach ($pages as $page)<option value="{{ $page->id }}" @selected($filters['page_id'] === $page->id)>{{ $page->name }}</option>@endforeach
    </select>
    <label class="sr-only" for="audit-action">Action</label>
    <select id="audit-action" name="action">
      <option value="">Every action</option>
      @foreach ($subjects as $option)
        <optgroup label="{{ ucfirst(str_replace('_', ' ', $option)) }}">
          @foreach ($actions->filter(fn ($a) => str_starts_with($a, $option.'.') || $a === $option) as $fullAction)
            <option value="{{ $fullAction }}" @selected($filters['action'] === $fullAction)>{{ (new \App\Models\AuditEntry(['action' => $fullAction]))->actionLabel() }}</option>
          @endforeach
        </optgroup>
      @endforeach
    </select>
    @if ($subject !== '')<input type="hidden" name="subject" value="{{ $subject }}">@endif
    <button class="btn op-sm" type="submit">Filter</button>
    @if ($filtered)<a class="btn ghost op-sm" href="{{ route('admin.audit') }}">Clear</a>@endif
    @unless ($entries->isEmpty())
      <a class="btn ghost op-sm push" href="{{ route('admin.audit.export', $exportQuery) }}" title="Every line that matches the filter, as CSV">Download CSV</a>
    @endunless
  </form>

  @if ($entries->isEmpty())
    <div class="bd"><p class="op-dim">
      @if ($filtered)
        No lines match this filter. <a class="integration-link" href="{{ route('admin.audit') }}">Clear it</a> to see everything.
      @else
        Nothing recorded yet. Changes made here or through the API appear on this page;
        the automatic checks do not, because nobody made them.
      @endif
    </p></div>
  @else
    <div class="scroll">
      <table class="op-table">
        <thead><tr><th>When</th><th>Who</th><th>What</th><th>Change</th></tr></thead>
        <tbody>
        @foreach ($entries as $entry)
          <tr>
            <td class="num" style="white-space:nowrap">
              {{ $entry->created_at->format('j M H:i') }}
              <div class="sub">{{ $entry->created_at->diffForHumans() }}</div>
            </td>
            <td>
              <span class="op-actor">
                <span class="avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr(str_starts_with($entry->actor, 'API token') ? 'T' : $entry->actor, 0, 1)) }}</span>
                <span>{{ $entry->actor }}@if ($entry->ip)<span class="sub mono" style="display:block">{{ $entry->ip }}</span>@endif</span>
              </span>
            </td>
            <td>
              <span class="op-verb">{{ $entry->actionLabel() }}</span>
              @if ($entry->subject_label)<div style="font-weight:600;font-size:13px">{{ $entry->subject_label }}</div>@endif
              @if ($entry->statusPage)<div class="sub">{{ $entry->statusPage->name }}</div>@endif
            </td>
            <td>
              <div class="op-change">
                @forelse ($entry->changeLines() as $line)
                  <span><code>{{ $line['field'] }}</code>
                    @if ($line['plain'])
                      {{ $line['to'] }}
                    @else
                      @if ($line['from'] === null)<em class="op-dim">empty</em>@else<del>{{ $line['from'] }}</del>@endif
                      <span aria-hidden="true">→</span><span class="sr-only">changed to</span>
                      @if ($line['to'] === null)<em class="op-dim">empty</em>@else<ins>{{ $line['to'] }}</ins>@endif
                    @endif
                  </span>
                @empty
                  <span class="op-dim">No field details</span>
                @endforelse
              </div>
            </td>
          </tr>
        @endforeach
        </tbody>
      </table>
    </div>
    <div class="bd">{{ $entries->links('vendor.pagination.pharos', ['previousLabel' => 'Newer', 'nextLabel' => 'Older']) }}</div>
  @endif
</section>

<x-note id="audit.record" style="margin-top:18px">
  <p><b>This page is the record, not a backup.</b> Nothing here is edited or deleted from the
  interface. Lines are pruned once they pass {{ $retentionDays }} days; change that under
  <a href="{{ route('admin.settings') }}">Settings → General</a>.</p>

  <p>Anyone who can open this page can read it, so treat it as what it is: a list of your components,
  your integrations, and your colleagues' addresses.</p>
</x-note>
@endsection
