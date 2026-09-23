@extends('layouts.admin')
@section('title', 'Bring in')
@section('content')
@include('partials.pagehead', [
  'title' => 'Bring in',
  'sub' => 'Let your monitoring and jobs keep this page up to date on their own',
])
@include('admin.integrations.partials.flow', ['active' => 'in'])

@php
  $guideComponent = $manualComponents->first()?->id ?? 'COMPONENT_ID';
  $api = \App\Services\PageUrls::api();
  $sources = [
      'n8n' => ['n8n workflow', 'Set status from any workflow', 'In n8n add an <b>HTTP Request</b> node with these settings.'],
      'kuma' => ['Uptime Kuma', 'Mirror a monitor up or down', 'In Uptime Kuma: Settings, Notifications, Setup notification, type <b>Webhook</b>.'],
      'api' => ['Script or Zabbix', 'Call the API from anything', 'Call this from your script, a Zabbix media type or any tool that can send HTTP.'],
      'hb' => ['Scheduled job', 'Check in when a job succeeds', 'Add one line to the end of your backup or cron job.'],
  ];
  $checked = $components->filter(fn ($c) => $c->check?->enabled && $c->check->type !== \App\Enums\CheckType::Heartbeat);
  $heartbeatComponents = $components->filter(fn ($c) => $c->check?->type === \App\Enums\CheckType::Heartbeat);
  $outside = $components->filter(fn ($c) => ! $c->check?->enabled && in_array($c->source, ['kuma', 'webhook', 'upstream'], true));
  $manual = $components->filter(fn ($c) => ! $c->check?->enabled && ! in_array($c->source, ['kuma', 'webhook', 'upstream', 'heartbeat'], true));
@endphp

<div class="ix-cols">
  <section class="ix-card" id="incoming-integrations" data-integration-base="{{ $api }}" aria-labelledby="connect-title">
    <header><h3 id="connect-title">Connect a source</h3><span class="hint">Pharos changes the component; you decide what sends it</span></header>
    <div class="bd">
      @if ($canEditIntegrations)
      <ol class="ix-steps">
        <li><div>
          <h4 id="source-heading">What knows whether it is healthy?</h4>
          <span class="sub">Pharos can also check websites and ports itself: set that on the component.</span>
          <div class="ix-tiles" id="ix-src" role="group" aria-labelledby="source-heading">
            @foreach ($sources as $key => [$title, $desc])
              <button type="button" class="ix-tile" data-src="{{ $key }}" aria-pressed="{{ $loop->first ? 'true' : 'false' }}">
                @include('partials.brand-mark', ['mark' => $key])
                <b>{{ $title }}</b><small>{{ $desc }}</small>
              </button>
            @endforeach
          </div>
        </div></li>

        <li data-for="n8n kuma api"><div>
          <h4><label for="integration-component">Which component does it update?</label></h4>
          <span class="sub">Only components without a built in check are listed, so two sources never fight.</span>
          <select id="integration-component" class="ix-input" style="max-width:440px" @disabled($manualComponents->isEmpty())>
            @forelse ($manualComponents as $component)
              <option value="{{ $component->id }}">{{ $component->name }}{{ $component->group ? ' ('.$component->group->name.')' : '' }} · ID {{ $component->id }}</option>
            @empty
              <option value="COMPONENT_ID">Create an enabled, manually managed component first</option>
            @endforelse
          </select>
          @if ($manualComponents->isEmpty())<p style="margin-top:8px"><a class="integration-link" href="{{ \App\Services\PageUrls::route('admin.components.create') }}">Add a component</a></p>@endif
        </div></li>

        <li data-for="n8n kuma api"><div>
          <h4>Use a token with write access</h4>
          <span class="sub">The token proves the request comes from you. It only works for this page.</span>
          @if ($canAdministerIntegrations)
            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
              @if ($writeTokens->isNotEmpty())
                <label class="sr-only" for="integration-token">Write token</label>
                <select id="integration-token" class="ix-input" style="max-width:280px">
                  @foreach ($writeTokens as $token)<option>{{ $token->name }} (write)</option>@endforeach
                </select>
              @else
                <span class="op-dim">No write token on this page yet.</span>
              @endif
              <a class="btn ghost op-sm" href="{{ \App\Services\PageUrls::route('admin.integrations.tokens') }}">Create a new token</a>
            </div>
            <p class="help" style="margin-top:6px">A token is shown once, when it is created. Paste it where the examples say YOUR_TOKEN.</p>
          @else
            <div class="ix-note"><span aria-hidden="true">ⓘ</span><span>Ask a page administrator for a token with <b>Write</b> access to this page.</span></div>
          @endif
        </div></li>

        <li><div>
          <h4>Copy this into your tool</h4>
          <span class="sub" id="ix-howto">{!! $sources['n8n'][2] !!}</span>
          <template id="ix-howto-texts">@foreach ($sources as $key => $source)<span data-src="{{ $key }}">{!! $source[2] !!}</span>@endforeach</template>

          <div data-for="n8n api" class="ix-block">
            <span class="ix-lbl">Request</span>
            <div class="ix-code"><span class="method">PUT</span><code id="n8n-component-url" data-component-url="components">{{ $api }}/components/{{ $guideComponent }}</code><button type="button" class="ix-copy" data-copy-target="n8n-component-url">Copy</button></div>
            <span class="ix-lbl">Header</span>
            <div class="ix-code"><code id="auth-header">Authorization: Bearer YOUR_TOKEN</code><button type="button" class="ix-copy" data-copy-target="auth-header">Copy</button></div>
            <span class="ix-lbl" id="status-heading">Status to send</span>
            <div class="ix-chips" id="ix-status" role="group" aria-labelledby="status-heading">
              @foreach (\App\Enums\ComponentStatus::cases() as $status)
                <button type="button" class="ix-chip st-{{ $status->tone() }}" data-status="{{ $status->value }}" aria-pressed="{{ $status->value === 1 ? 'true' : 'false' }}"><i style="background:var(--st)"></i>{{ $status->label() }}</button>
              @endforeach
            </div>
            <span class="ix-lbl">Body</span>
            <div class="ix-code"><code id="n8n-component-body">{"status": 1}</code><button type="button" class="ix-copy" data-copy-target="n8n-component-body">Copy</button></div>
            <div data-for="api" class="ix-hidden">
              <span class="ix-lbl">The same as a shell command</span>
              <div class="ix-code"><pre id="component-curl">curl -X PUT '{{ $api }}/components/{{ $guideComponent }}' -H 'Authorization: Bearer YOUR_TOKEN' -H 'Content-Type: application/json' -d '{"status": 1}'</pre><button type="button" class="ix-copy" data-copy-target="component-curl">Copy</button></div>
              <p class="help" style="margin-top:8px">Existing clients may also send the token in an <code>X-Cachet-Token</code> header.</p>
            </div>
            <p class="help" style="margin-top:10px">This changes the component only. It does not open an incident or notify anyone.</p>
          </div>

          <div data-for="kuma" class="ix-block ix-hidden">
            <span class="ix-lbl">Notification type: Webhook, method POST, body application/json. URL:</span>
            <div class="ix-code"><span class="method">POST</span><code id="kuma-url" data-component-url="integrations/kuma">{{ $api }}/integrations/kuma/{{ $guideComponent }}</code><button type="button" class="ix-copy" data-copy-target="kuma-url">Copy</button></div>
            <span class="ix-lbl">Additional headers (JSON)</span>
            <div class="ix-code"><code id="kuma-headers">{"Authorization": "Bearer YOUR_TOKEN"}</code><button type="button" class="ix-copy" data-copy-target="kuma-headers">Copy</button></div>
            <div class="ix-note" style="margin-top:10px"><span aria-hidden="true">ⓘ</span><span>Kuma sends every change on its own. Up sets <b>Operational</b>, down sets <b>Major outage</b>, maintenance sets <b>Under maintenance</b>, pending changes nothing. Kuma's test button sends no state and is refused; test with a real monitor.</span></div>
          </div>

          <div data-for="hb" class="ix-block ix-hidden" id="heartbeats">
            @forelse ($heartbeats as $component)
              <span class="ix-lbl">{{ $component->name }}: call this at the end of the job</span>
              <div class="ix-code"><pre id="heartbeat-{{ $component->id }}">curl -fsS -X POST {{ \App\Services\PageUrls::api('heartbeat/'.$component->check->target) }}</pre><button type="button" class="ix-copy" data-copy-target="heartbeat-{{ $component->id }}">Copy</button></div>
              <p class="help" style="margin-top:6px">Expected every {{ \Carbon\CarbonInterval::seconds($component->check->interval_seconds)->cascade()->forHumans() }}.@unless ($component->check->enabled) This heartbeat check is switched off.@endunless <a class="integration-link" href="{{ \App\Services\PageUrls::route('admin.components.edit', $component) }}">Change</a></p>
            @empty
              <div class="ix-note"><span aria-hidden="true">ⓘ</span><span>No heartbeat components yet. Add a component with source <b>Heartbeat</b> and its check in URL appears here.</span></div>
              <p style="margin-top:8px"><a class="integration-link" href="{{ \App\Services\PageUrls::route('admin.components.create') }}">Add a heartbeat component</a></p>
            @endforelse
            {{ $heartbeats->links('vendor.pagination.pharos', ['previousLabel' => 'Previous', 'nextLabel' => 'Next']) }}
            <div class="ix-note" style="margin-top:10px"><span aria-hidden="true">ⓘ</span><span>No check in on time: the component goes to <b>Major outage</b>. The next check in sets it back. The secret in the URL is the authorization; no token is needed.</span></div>
          </div>

          <details class="ix-more" data-for="n8n kuma api">
            <summary>Need an incident with a message instead of a status change?</summary>
            <div>
              <p>POST this to <code>{{ $api }}/incidents</code> with the same header. It appears on the page and is mailed to subscribers. Keep the returned <code>data.id</code> and post follow ups to <code>{{ $api }}/incidents/INCIDENT_ID/updates</code>; send status <code>resolved</code> to close it.</p>
              <div class="ix-code"><pre id="incident-example">{{ json_encode(['name' => 'Service unavailable', 'status' => 'investigating', 'message' => 'We are investigating a service interruption.', 'impact' => 'major', 'components' => (object) [$guideComponent => 'major_outage']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre><button type="button" class="ix-copy" data-copy-target="incident-example">Copy</button></div>
            </div>
          </details>
        </div></li>
      </ol>
      @else
        <div class="ix-note"><span aria-hidden="true">ⓘ</span><span>Editors of this page connect monitoring and jobs. You can see below which components are fed, and by what.</span></div>
      @endif
    </div>
  </section>

  <div class="ix-side">
    <section class="ix-card" aria-labelledby="feeding-title">
      <header><h3 id="feeding-title">Already feeding this page</h3><span class="hint">{{ $components->count() }} {{ \Illuminate\Support\Str::plural('component', $components->count()) }}</span></header>
      <div class="bd">
        @if ($components->isEmpty())
          <p class="op-dim">No components yet.</p>
        @else
        <ul class="ix-list">
          @if ($checked->isNotEmpty())
            <li>@include('partials.brand-mark', ['mark' => 'check'])
              <span><b>Pharos checks</b><span class="d">{{ $checked->take(6)->map(fn ($c) => $c->name.' ('.strtoupper($c->check->type->value).')')->join(', ') }}{{ $checked->count() > 6 ? ' and '.($checked->count() - 6).' more' : '' }}</span></span>
              <span class="ix-state ok">{{ $checked->count() }} running</span></li>
          @endif
          @foreach ($heartbeatComponents as $component)
            <li>@include('partials.brand-mark', ['mark' => 'hb'])
              <span><b>{{ $component->name }}</b><span class="d">Heartbeat · {{ $component->check->last_run_at ? 'last check in '.$component->check->last_run_at->format('j M H:i') : 'expected every '.\Carbon\CarbonInterval::seconds($component->check->interval_seconds)->cascade()->forHumans() }}</span></span>
              @if (! $component->check->enabled)<span class="ix-state off">Off</span>@elseif ($component->check->last_run_at)<span class="ix-state ok">Live</span>@else<span class="ix-state w">Not seen yet</span>@endif</li>
          @endforeach
          @foreach ($outside as $component)
            <li>@include('partials.brand-mark', ['mark' => $component->source === 'kuma' ? 'kuma' : 'api'])
              <span><b>{{ $component->name }}</b><span class="d">{{ $component->source === 'kuma' ? 'Uptime Kuma' : 'API' }} · last change {{ $component->updated_at?->format('j M H:i') }}</span></span>
              <span class="ix-state ok">Set from outside</span></li>
          @endforeach
          @if ($manual->isNotEmpty())
            <li>@include('partials.brand-mark', ['mark' => 'manual'])
              <span><b>{{ $manual->pluck('name')->take(5)->join(', ') }}{{ $manual->count() > 5 ? ' and '.($manual->count() - 5).' more' : '' }}</b><span class="d">Nothing connected: set by hand or through the API</span></span>
              <span class="ix-state off">Manual</span></li>
          @endif
        </ul>
        @endif
      </div>
    </section>
    <section class="ix-card">
      <header><h3>The other direction</h3></header>
      <div class="bd"><p style="font-size:13px;color:var(--ink-2)">To tell n8n or a chat when an incident opens, add a destination under <a class="integration-link" href="{{ \App\Services\PageUrls::route('admin.integrations.out', ['destination' => 'generic']) }}">Send out</a>.</p></div>
    </section>
  </div>
</div>

<script defer src="{{ asset('assets/pharos-ops.js') }}?v=0.7.0"></script>
@endsection
