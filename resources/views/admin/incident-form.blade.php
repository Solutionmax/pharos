@extends('layouts.admin')
@section('title', 'Report an incident')
@section('content')
@include('partials.pagehead', [
  'title' => 'Report an incident',
  'back' => ['url' => route('admin.incidents'), 'label' => 'Incidents'],
])

<form method="POST" action="{{ route('admin.incidents.store') }}">
  @csrf
  <div class="panel">
    <div class="panel-hd"><h3>What happened</h3>
      @if ($templates->isNotEmpty())<span class="hint">{{ $templates->count() }} {{ \Illuminate\Support\Str::plural('template', $templates->count()) }} available via the API</span>@endif
    </div>
    <div class="panel-bd">
      <div class="fields">
        <div class="field wide">
          <label for="name">Title</label>
          <input id="name" name="name" type="text" value="{{ old('name') }}" required placeholder="web-06 unreachable">
        </div>
        <div class="field">
          <label for="status">Status</label>
          <select id="status" name="status">
            @foreach (\App\Enums\IncidentStatus::cases() as $case)
              <option value="{{ $case->value }}" @selected(old('status', 1) == $case->value)>{{ $case->label() }}</option>
            @endforeach
          </select>
        </div>
        <div class="field">
          <label for="impact">Impact</label>
          <select id="impact" name="impact">
            @foreach (\App\Enums\Impact::cases() as $case)
              <option value="{{ $case->value }}" @selected(old('impact', 'minor') === $case->value)>{{ $case->label() }}</option>
            @endforeach
          </select>
        </div>
        <div class="field">
          <label for="visibility">Visibility</label>
          <select id="visibility" name="visibility">
            <option value="public" @selected(old('visibility', 'public') === 'public')>Public</option>
            <option value="authenticated" @selected(old('visibility') === 'authenticated')>Signed-in users only</option>
            <option value="internal" @selected(old('visibility') === 'internal')>Internal — team only</option>
          </select>
        </div>
        <div class="field">
          <label for="occurred_at">Occurred at</label>
          <input id="occurred_at" name="occurred_at" type="datetime-local" value="{{ old('occurred_at', now()->format('Y-m-d\TH:i')) }}">
          <span class="help">Backdate an incident you are logging afterwards.</span>
        </div>
      </div>

      <div class="field wide">
        <label for="message">Message to customers</label>
        @include('partials.editor', ['for' => 'message'])
        <textarea id="message" name="message" rows="4" required placeholder="What you know, what you are doing, when you will post again.">{{ old('message') }}</textarea>
      </div>
    </div>
  </div>

  <div class="panel">
    <div class="panel-hd"><h3>Affected components</h3><span class="hint">More than one is allowed</span></div>
    <div class="panel-bd">
      @if ($components->isEmpty())
        <p class="sub">No components yet.</p>
      @else
        <div class="scroll">
          <table>
            <thead><tr><th>Component</th><th>Set status to</th></tr></thead>
            <tbody>
            @foreach ($components as $component)
              <tr>
                <td>{{ $component->name }}<div class="sub">{{ $component->group?->name ?? 'Ungrouped' }}</div></td>
                <td>
                  <select name="components[{{ $component->id }}]" style="max-width:230px">
                    <option value="">— leave unchanged —</option>
                    @foreach (\App\Enums\ComponentStatus::cases() as $case)
                      <option value="{{ $case->value }}">{{ $case->label() }}</option>
                    @endforeach
                  </select>
                </td>
              </tr>
            @endforeach
            </tbody>
          </table>
        </div>
      @endif

      <div class="switchrow">
        <span class="t"><strong>Pin to the top of the status page</strong><span class="s">For an ongoing incident people should see first.</span></span>
        <label class="check"><input type="checkbox" name="pinned" value="1" @checked(old('pinned'))> Pin</label>
      </div>
      <div class="switchrow">
        <span class="t"><strong>Resolve automatically when checks recover</strong><span class="s">Closes the incident after three healthy checks and posts the closing update.</span></span>
        <label class="check"><input type="checkbox" name="auto_resolve" value="1" @checked(old('auto_resolve'))> Auto-resolve</label>
      </div>

      <div class="actions">
        <button class="btn" type="submit">Publish incident</button>
        <a class="btn ghost" href="{{ route('admin.incidents') }}">Cancel</a>
      </div>
    </div>
  </div>
</form>
@endsection
