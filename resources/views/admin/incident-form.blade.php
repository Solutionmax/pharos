@extends('layouts.admin')
@section('title', 'Report an incident')
@section('content')
@include('partials.pagehead', [
  'title' => 'Report an incident',
  'sub' => 'Three steps: what happened, what it affects, and what you tell customers',
  'back' => ['url' => \App\Services\PageUrls::route('admin.incidents'), 'label' => 'Incidents'],
])

<form method="POST" action="{{ \App\Services\PageUrls::route('admin.incidents.store') }}" id="incident-report" class="op-cols">
  @csrf
  <div class="op-card">
    <header><h3>New incident</h3><span class="hint">Published as soon as you press the button</span></header>
    <div class="bd">
      <ol class="ix-steps">
        <li><div>
          <h4>What happened?</h4>
          <span class="sub">A short title customers recognise, and how far along you are.</span>
          @if ($templates->isNotEmpty())
            <span class="ix-lbl" id="template-label">Use a template</span>
            <div class="op-templates" role="group" aria-labelledby="template-label">
              @foreach ($templates as $template)
                <button type="button" aria-pressed="false" data-template="{{ json_encode(['title' => $template->title_template, 'body' => $template->body_template]) }}">{{ $template->name }}</button>
              @endforeach
            </div>
            <p class="help" style="margin:6px 0 4px">Fills the title and the message. Replace any placeholder in double braces before publishing.@if ($canUseTemplates) <a class="integration-link" href="{{ \App\Services\PageUrls::route('admin.incidents.templates') }}">Manage templates</a>@endif</p>
          @elseif ($canUseTemplates)
            <p class="help" style="margin-bottom:10px">Report the same kind of incident often? <a class="integration-link" href="{{ \App\Services\PageUrls::route('admin.incidents.templates.create') }}">Save a template</a> and start from it next time.</p>
          @endif
          <div class="field" style="margin-top:10px">
            <label for="name">Title</label>
            <input id="name" name="name" type="text" value="{{ old('name') }}" required maxlength="255" placeholder="Mail delivery delayed">
          </div>
          <div style="margin-top:14px">
            @include('partials.incident-status-choice', ['selectedStatus' => 1, 'legend' => 'Status'])
          </div>
          <div class="fields" style="margin-top:14px">
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
                <option value="authenticated" @selected(old('visibility') === 'authenticated')>Signed in users only</option>
                <option value="internal" @selected(old('visibility') === 'internal')>Internal: team only</option>
              </select>
            </div>
            <div class="field">
              <label for="occurred_at">Started at</label>
              <input id="occurred_at" name="occurred_at" type="datetime-local" value="{{ old('occurred_at', \App\Services\Clock::now()->format('Y-m-d\TH:i')) }}">
              <span class="help">Backdate an incident you log afterwards.</span>
            </div>
          </div>
        </div></li>

        <li><div>
          <h4>Which components, and their status?</h4>
          <span class="sub">Leave a component unchanged unless customers notice it. More than one is fine.</span>
          @if ($components->isEmpty())
            <p class="op-dim">No components on this page yet.</p>
          @else
            @foreach ($components->groupBy(fn ($c) => $c->group?->name ?? 'Ungrouped') as $groupName => $members)
              <span class="op-grp">{{ $groupName }}</span>
              @foreach ($members as $component)
                @php $oldStatus = old("components.{$component->id}"); @endphp
                <div class="op-affect">
                  <span class="op-name"><strong>{{ $component->name }}</strong><span><i class="state-dot {{ $component->status->tone() }}" style="display:inline-block;margin-right:6px;vertical-align:1px"></i>Now {{ \Illuminate\Support\Str::lower($component->status->label()) }}</span></span>
                  <label class="sr-only" for="component-{{ $component->id }}">New status for {{ $component->name }}</label>
                  <select id="component-{{ $component->id }}" name="components[{{ $component->id }}]" data-component="{{ $component->name }}">
                    <option value="" @selected(! $oldStatus)>Leave unchanged</option>
                    @foreach (\App\Enums\ComponentStatus::cases() as $case)
                      <option value="{{ $case->value }}" @selected($oldStatus == $case->value)>{{ $case->label() }}</option>
                    @endforeach
                  </select>
                </div>
              @endforeach
            @endforeach
          @endif
        </div></li>

        <li><div>
          <h4>What do you tell customers?</h4>
          <span class="sub">Shown on the status page and mailed to subscribers when the incident is public.</span>
          <div class="field">
            <label for="message">Message to customers</label>
            @include('partials.editor', ['for' => 'message'])
            <textarea id="message" name="message" rows="5" required placeholder="What you know, what you are doing, when you will post again.">{{ old('message') }}</textarea>
          </div>
          <div class="switchrow" style="margin-top:14px">
            <span class="t"><strong>Pin to the top of the status page</strong><span class="s">For an ongoing incident people should see first.</span></span>
            <label class="check"><input type="checkbox" name="pinned" value="1" @checked(old('pinned'))> Pin</label>
          </div>
          <div class="switchrow" style="margin-top:10px">
            <span class="t"><strong>Resolve automatically when checks recover</strong><span class="s">Closes the incident after three healthy checks and posts the closing update.</span></span>
            <label class="check"><input type="checkbox" name="auto_resolve" value="1" @checked(old('auto_resolve'))> Resolve on recovery</label>
          </div>
          <div class="actions" style="margin-top:16px">
            <button class="btn" type="submit">Publish incident</button>
            <a class="btn ghost" href="{{ \App\Services\PageUrls::route('admin.incidents') }}">Cancel</a>
          </div>
        </div></li>
      </ol>
    </div>
  </div>

  <aside class="op-side op-preview" aria-labelledby="preview-title">
    <div class="op-card">
      <header><h3 id="preview-title">Live preview</h3><span class="hint">As the status page shows it</span></header>
      <div class="bd" id="incident-preview">
        <div class="frame" aria-hidden="true">
          <div class="pv-inc">
            <div class="pv-hd"><h4 data-pv="title">Your title appears here</h4><span class="pill p" data-pv="status" style="font-size:11px;font-weight:700;padding:3px 10px;border-radius:999px;background:var(--orange-soft);color:var(--orange-ink)">Investigating</span></div>
            <p class="pv-aff" data-pv="affects" hidden>Affects <b></b></p>
            <div class="pv-tl"><strong data-pv="status-line">Investigating</strong><time>{{ \App\Services\Clock::now()->format('H:i') }}</time><p data-pv="message">What you know, what you are doing, and when you will post again.</p></div>
          </div>
        </div>
        <div class="ix-note warn pv-private" data-pv="private" style="display:none"><span aria-hidden="true">⚠</span><span>Not public: customers do not see this incident and subscribers are not mailed.</span></div>
        <p class="pv-note"><span data-pv="impact">Minor impact</span>. Impact is for your reports and notifications; the page does not show it.</p>
      </div>
    </div>
  </aside>
</form>

<script defer src="{{ asset('assets/pharos-ops.js') }}?v=0.7.0"></script>
@endsection
