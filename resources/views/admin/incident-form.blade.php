@extends('layouts.admin')
@section('title', __('Report an incident'))
@section('content')
@include('partials.pagehead', [
  'title' => __('Report an incident'),
  'sub' => __('Three steps: what happened, what it affects, and what you tell customers'),
  'back' => ['url' => \App\Services\PageUrls::route('admin.incidents'), 'label' => __('Incidents')],
])

<form method="POST" action="{{ \App\Services\PageUrls::route('admin.incidents.store') }}" id="incident-report" class="op-cols">
  @csrf
  <div class="op-card">
    <header><h3>{{ __('New incident') }}</h3><span class="hint">{{ __('Published as soon as you press the button') }}</span></header>
    <div class="bd">
      <ol class="ix-steps">
        <li><div>
          <h4>{{ __('What happened?') }}</h4>
          <span class="sub">{{ __('A short title customers recognise, and how far along you are.') }}</span>
          @if ($templates->isNotEmpty())
            <span class="ix-lbl" id="template-label">{{ __('Use a template') }}</span>
            <div class="op-templates" role="group" aria-labelledby="template-label">
              @foreach ($templates as $template)
                <button type="button" aria-pressed="false" data-template="{{ json_encode(['title' => $template->title_template, 'body' => $template->body_template]) }}">{{ $template->name }}</button>
              @endforeach
            </div>
            <p class="help" style="margin:6px 0 4px">{{ __('Fills the title and the message. Replace any placeholder in double braces before publishing.') }}@if ($canUseTemplates) <a class="integration-link" href="{{ \App\Services\PageUrls::route('admin.incidents.templates') }}">{{ __('Manage templates') }}</a>@endif</p>
          @elseif ($canUseTemplates)
            <p class="help" style="margin-bottom:10px">{{ __('Report the same kind of incident often?') }} <a class="integration-link" href="{{ \App\Services\PageUrls::route('admin.incidents.templates.create') }}">{{ __('Save a template') }}</a> {{ __('and start from it next time.') }}</p>
          @endif
          <div class="field" style="margin-top:10px">
            <label for="name">{{ __('Title') }}</label>
            <input id="name" name="name" type="text" value="{{ old('name') }}" required maxlength="255" placeholder="{{ __('Mail delivery delayed') }}">
          </div>
          <div style="margin-top:14px">
            @include('partials.incident-status-choice', ['selectedStatus' => 1, 'legend' => 'Status'])
          </div>
          <div class="fields" style="margin-top:14px">
            <div class="field">
              <label for="impact">{{ __('Impact') }}</label>
              <select id="impact" name="impact">
                @foreach (\App\Enums\Impact::cases() as $case)
                  <option value="{{ $case->value }}" @selected(old('impact', 'minor') === $case->value)>{{ __($case->label() ?? '') }}</option>
                @endforeach
              </select>
            </div>
            <div class="field">
              <label for="visibility">{{ __('Visibility') }}</label>
              <select id="visibility" name="visibility">
                <option value="public" @selected(old('visibility', 'public') === 'public')>{{ __('Public') }}</option>
                <option value="internal" @selected(old('visibility') === 'internal')>{{ __('Internal: team only') }}</option>
              </select>
            </div>
            <div class="field">
              <label for="occurred_at">{{ __('Started at') }}</label>
              <input id="occurred_at" name="occurred_at" type="datetime-local" value="{{ old('occurred_at', \App\Services\Clock::now()->format('Y-m-d\TH:i')) }}">
              <span class="help">{{ __('Backdate an incident you log afterwards.') }}</span>
              @include('partials.zone-hint')
            </div>
          </div>
        </div></li>

        <li><div>
          <h4>{{ __('Which components, and their status?') }}</h4>
          <span class="sub">{{ __('Leave a component unchanged unless customers notice it. More than one is fine.') }}</span>
          @if ($components->isEmpty())
            <p class="op-dim">{{ __('No components on this page yet.') }}</p>
          @else
            @foreach ($components->groupBy(fn ($c) => $c->group?->name ?? 'Ungrouped') as $groupName => $members)
              <span class="op-grp">{{ $groupName }}</span>
              @foreach ($members as $component)
                @php $oldStatus = old("components.{$component->id}"); @endphp
                <div class="op-affect">
                  <span class="op-name"><strong>{{ $component->name }}</strong><span><i class="state-dot {{ $component->status->tone() }}" style="display:inline-block;margin-right:6px;vertical-align:1px"></i>{{ __('Now') }} {{ \Illuminate\Support\Str::lower($component->status->label()) }}</span></span>
                  <label class="sr-only" for="component-{{ $component->id }}">{{ __('New status for') }} {{ $component->name }}</label>
                  <select id="component-{{ $component->id }}" name="components[{{ $component->id }}]" data-component="{{ $component->name }}">
                    <option value="" @selected(! $oldStatus)>{{ __('Leave unchanged') }}</option>
                    @foreach (\App\Enums\ComponentStatus::cases() as $case)
                      <option value="{{ $case->value }}" @selected($oldStatus == $case->value)>{{ __($case->label() ?? '') }}</option>
                    @endforeach
                  </select>
                </div>
              @endforeach
            @endforeach
          @endif
        </div></li>

        <li><div>
          <h4>{{ __('What do you tell customers?') }}</h4>
          <span class="sub">{{ __('Shown on the status page and mailed to subscribers when the incident is public.') }}</span>
          <div class="field">
            <label for="message">{{ __('Message to customers') }}</label>
            @include('partials.editor', ['for' => 'message'])
            <textarea id="message" name="message" rows="5" required placeholder="{{ __('What you know, what you are doing, when you will post again.') }}">{{ old('message') }}</textarea>
          </div>
          <div class="switchrow" style="margin-top:14px">
            <span class="t"><strong>{{ __('Pin to the top of the status page') }}</strong><span class="s">{{ __('For an ongoing incident people should see first.') }}</span></span>
            <label class="check"><input type="checkbox" name="pinned" value="1" @checked(old('pinned'))> {{ __('Pin') }}</label>
          </div>
          <div class="switchrow" style="margin-top:10px">
            <span class="t"><strong>{{ __('Resolve automatically when checks recover') }}</strong><span class="s">{{ __('Closes the incident after three healthy checks and posts the closing update.') }}</span></span>
            <label class="check"><input type="checkbox" name="auto_resolve" value="1" @checked(old('auto_resolve'))> {{ __('Resolve on recovery') }}</label>
          </div>
          <div class="actions" style="margin-top:16px">
            <button class="btn" type="submit">{{ __('Publish incident') }}</button>
            <a class="btn ghost" href="{{ \App\Services\PageUrls::route('admin.incidents') }}">{{ __('Cancel') }}</a>
          </div>
        </div></li>
      </ol>
    </div>
  </div>

  <aside class="op-side op-preview" aria-labelledby="preview-title">
    <div class="op-card">
      <header><h3 id="preview-title">{{ __('Live preview') }}</h3><span class="hint">{{ __('As the status page shows it, in') }} {{ \App\Services\Clock::installationTimezone() }}</span></header>
      <div class="bd" id="incident-preview">
        <div class="frame" aria-hidden="true">
          <div class="pv-inc">
            <div class="pv-hd"><h4 data-pv="title">{{ __('Your title appears here') }}</h4><span class="pill p" data-pv="status" style="font-size:11px;font-weight:700;padding:3px 10px;border-radius:999px;background:var(--orange-soft);color:var(--orange-ink)">{{ __('Investigating') }}</span></div>
            <p class="pv-aff" data-pv="affects" hidden>{{ __('Affects') }} <b></b></p>
            <div class="pv-tl"><strong data-pv="status-line">{{ __('Investigating') }}</strong><time>{{ \App\Services\Clock::withInstallationZone(fn () => \App\Services\Clock::now()->format('H:i')) }}</time><p data-pv="message">{{ __('What you know, what you are doing, and when you will post again.') }}</p></div>
          </div>
        </div>
        <div class="ix-note warn pv-private" data-pv="private" style="display:none"><span aria-hidden="true">⚠</span><span>{{ __('Not public: customers do not see this incident and subscribers are not mailed.') }}</span></div>
        <p class="pv-note"><span data-pv="impact">{{ __('Minor impact') }}</span>{{ __('. Impact is for your reports and notifications; the page does not show it.') }}</p>
      </div>
    </div>
  </aside>
</form>

<script defer src="{{ asset('assets/pharos-ops.js') }}?v=0.7.0"></script>
@endsection
