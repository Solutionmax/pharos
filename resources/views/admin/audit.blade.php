@extends('layouts.admin')
@section('title', 'Audit log')
@section('content')
@include('partials.pagehead', [
  'title' => 'Audit log',
  'sub' => 'Who changed what, and when',
])

<div class="panel">
  <div class="panel-hd">
    <h3>Activity</h3>
    <span class="hint">{{ $entries->total() }} recorded · kept {{ $retentionDays }} days</span>
  </div>

  <div class="panel-bd" style="border-bottom:1px solid var(--line)">
    <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
      <div class="field" style="flex:1;min-width:180px">
        <label for="actor">Who</label>
        <input id="actor" name="actor" type="text" value="{{ $actor }}" placeholder="name, e-mail or token">
      </div>
      <div class="field" style="min-width:160px">
        <label for="subject">What</label>
        <select id="subject" name="subject">
          <option value="">Everything</option>
          @foreach ($subjects as $option)
            <option value="{{ $option }}" @selected($subject === $option)>{{ ucfirst(str_replace('_', ' ', $option)) }}</option>
          @endforeach
        </select>
      </div>
      <button class="btn" type="submit">Filter</button>
      @if ($actor !== '' || $subject !== '')
        <a class="btn ghost" href="{{ route('admin.audit') }}">Clear</a>
      @endif
      @unless ($entries->isEmpty())
        <a class="btn ghost" style="margin-left:auto" href="{{ route('admin.audit.export', request()->only('actor', 'subject')) }}"
           title="Every line that matches the filter, as CSV">Download CSV</a>
      @endunless
    </form>
  </div>

  @if ($entries->isEmpty())
    <div class="empty">
      Nothing recorded yet. Changes made from here or through the API appear on this page;
      the automatic checks do not, because nobody made them.
    </div>
  @else
    <div class="scroll">
      <table>
        <thead><tr><th>When</th><th>Who</th><th>What</th><th>Details</th></tr></thead>
        <tbody>
        @foreach ($entries as $entry)
          <tr>
            <td class="num" style="white-space:nowrap">
              {{ $entry->created_at->format('d M H:i') }}
              <div class="sub">{{ $entry->created_at->diffForHumans() }}</div>
            </td>
            <td>
              {{ $entry->actor }}
              @if ($entry->ip)<div class="sub mono">{{ $entry->ip }}</div>@endif
            </td>
            <td>
              <span style="font-weight:600">{{ $entry->actionLabel() }}</span>
              @if ($entry->subject_label)
                <div class="sub">{{ $entry->subject_label }}</div>
              @endif
            </td>
            <td class="sub">
              @forelse ($entry->changeLines() as $line)
                <div>
                  <span style="color:var(--ink)">{{ $line['field'] }}</span>:
                  @if ($line['plain'])
                    {{ $line['to'] }}
                  @else
                    <span style="text-decoration:line-through;color:var(--ink-3)">{{ $line['from'] ?? '—' }}</span>
                    &rarr; {{ $line['to'] ?? '—' }}
                  @endif
                </div>
              @empty
                —
              @endforelse
            </td>
          </tr>
        @endforeach
        </tbody>
      </table>
    </div>
    <div class="panel-bd">{{ $entries->links() }}</div>
  @endif
</div>

<x-note id="audit.record">
  <p><b>This page is the record, not a backup.</b> Nothing here is edited or deleted from the
  interface. Lines are pruned once they pass {{ $retentionDays }} days — change that under
  <a href="{{ route('admin.settings') }}">Settings → General</a>.</p>

  <p>Anyone who can sign in can read it, so treat it as what it is: a list of your components,
  your integrations, and your colleagues' addresses.</p>
</x-note>

@endsection
