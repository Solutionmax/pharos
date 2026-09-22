@php $guideComponent = $manualComponents->first()?->id ?? 'COMPONENT_ID'; @endphp
<section class="panel" id="incoming-integrations" data-integration-base="{{ \App\Services\PageUrls::api() }}">
  <div class="panel-hd"><h3>Bring updates into Pharos</h3><span class="hint">Your tool → Pharos</span></div>
  <div class="panel-bd">
    <p class="integration-intro">Choose the system that knows whether a service is healthy. These connections update Pharos; notification destinations below send incident events out.</p>
    <div class="field integration-component">
      <label for="integration-component">Component to update</label>
      <select id="integration-component" @disabled($manualComponents->isEmpty())>
        @forelse ($manualComponents as $component)
          <option value="{{ $component->id }}">{{ $component->name }} · ID {{ $component->id }}</option>
        @empty
          <option value="COMPONENT_ID">Create an enabled, manually managed component first</option>
        @endforelse
      </select>
      <span class="help">Only enabled components without an active Pharos check are shown, so two monitors do not overwrite each other.</span>
      @if ($manualComponents->isEmpty())<a class="integration-link" href="{{ \App\Services\PageUrls::route('admin.components.create') }}">Add a component</a>@endif
    </div>

    <details class="integration-guide" id="guide-n8n" open>
      <summary><span class="integration-guide-mark">n8n</span><span><strong>n8n</strong><small>Update a component or publish an incident</small></span><span class="guide-toggle" aria-hidden="true">+</span></summary>
      <div class="integration-guide-body">
        <div class="integration-flow"><span>Workflow trigger</span><b aria-hidden="true">→</b><span>HTTP Request</span><b aria-hidden="true">→</b><span>Pharos component</span></div>
        <ol class="integration-steps">
          <li>Create a Pharos API token with write scope below, or ask an administrator for one.</li>
          <li>Add an <b>HTTP Request</b> node to n8n. Set the method to <b>PUT</b> and use this component URL.</li>
          <li>Choose a Header Auth credential: name <code>Authorization</code>, value <code>Bearer YOUR_PHAROS_TOKEN</code>. Send a JSON body with a numeric component status.</li>
        </ol>
        <div class="integration-code-head"><span>Request URL</span><button type="button" class="integration-copy" data-copy-target="n8n-component-url">Copy URL</button></div>
        <pre id="n8n-component-url" data-component-url="components">{{ \App\Services\PageUrls::api('components/'.$guideComponent) }}</pre>
        <div class="field"><label for="integration-status">Example component status</label><select id="integration-status">@foreach (\App\Enums\ComponentStatus::cases() as $status)<option value="{{ $status->value }}">{{ $status->value }} · {{ $status->label() }}</option>@endforeach</select></div>
        <div class="integration-code-head"><span>JSON body</span><button type="button" class="integration-copy" data-copy-target="n8n-component-body">Copy JSON</button></div>
        <pre id="n8n-component-body">{"status": 1}</pre>
        <p class="integration-result">Executing the node changes the selected component. It does not create an incident or send incident notifications.</p>
        <details class="integration-example"><summary>Need an incident and customer updates instead?</summary>
          <p>POST this body to <code>{{ \App\Services\PageUrls::api('incidents') }}</code> with the same authorization header. Store the returned <code>data.id</code>. For later updates, POST a status and message to <code>{{ \App\Services\PageUrls::api('incidents/INCIDENT_ID/updates') }}</code>; use <code>resolved</code> to close it. Each create request opens a new incident, so do not create one on every poll.</p>
          <div class="integration-code-head"><span>Incident example</span><button type="button" class="integration-copy" data-copy-target="incident-example">Copy JSON</button></div>
          <pre id="incident-example">{{ json_encode(['name' => 'Service unavailable', 'status' => 'investigating', 'message' => 'We are investigating a service interruption.', 'impact' => 'major', 'components' => (object) [$guideComponent => 'major_outage']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
          <p class="help">A component status is a number from 1 to 5. An incident status describes progress: investigating, identified, monitoring or resolved.</p>
        </details>
        <p><a class="integration-link" href="https://docs.n8n.io/integrations/builtin/core-nodes/n8n-nodes-base.httprequest/" target="_blank" rel="noopener noreferrer">n8n HTTP Request documentation ↗</a></p>
        <p class="help">For the opposite direction, Pharos → n8n, <a href="#add-notification" data-select-destination="generic">add a Generic JSON notification</a> using the workflow Webhook node's Production URL.</p>
      </div>
    </details>

    <details class="integration-guide" id="guide-kuma">
      <summary><span class="integration-guide-mark">UK</span><span><strong>Uptime Kuma</strong><small>Mirror a monitor's up/down state</small></span><span class="guide-toggle" aria-hidden="true">+</span></summary>
      <div class="integration-guide-body">
        <div class="integration-flow"><span>Kuma monitor</span><b aria-hidden="true">→</b><span>Webhook notification</span><b aria-hidden="true">→</b><span>Pharos component</span></div>
        <ol class="integration-steps">
          <li>Create a Pharos API token with write scope and choose the component above. Use one notification endpoint per component mapping.</li>
          <li>In Kuma, add a <b>Webhook</b> notification with method <b>POST</b> and the default <b>application/json</b> body. Paste this URL.</li>
          <li>Add the authorization header below, replace the token placeholder, save the notification and attach it to the relevant monitor.</li>
        </ol>
        <div class="integration-code-head"><span>Kuma webhook URL</span><button type="button" class="integration-copy" data-copy-target="kuma-url">Copy URL</button></div>
        <pre id="kuma-url" data-component-url="kuma/components">{{ \App\Services\PageUrls::api('kuma/components/'.$guideComponent) }}</pre>
        <div class="integration-code-head"><span>Additional Headers (JSON)</span><button type="button" class="integration-copy" data-copy-target="kuma-headers">Copy JSON</button></div>
        <pre id="kuma-headers">{"Authorization": "Bearer YOUR_PHAROS_TOKEN"}</pre>
        <div class="integration-mapping"><span><i class="state-dot ok"></i>Kuma up (1) → Operational</span><span><i class="state-dot b"></i>Kuma down (0) → Major outage</span></div>
        <p class="integration-result">This adapter changes component status only. It does not create incidents. A Kuma test message without heartbeat.status is rejected with HTTP 422; verify using a real up/down notification from a test monitor. Pending and maintenance states are not mapped.</p>
        <p class="help">A Kuma Push monitor receives heartbeats itself. It does not forward them to Pharos. Use the Webhook notification described here.</p>
        <a class="integration-link" href="https://github.com/louislam/uptime-kuma" target="_blank" rel="noopener noreferrer">Uptime Kuma project and documentation ↗</a>
      </div>
    </details>

    <details class="integration-guide" id="guide-scripts">
      <summary><span class="integration-guide-mark">{ }</span><span><strong>Scripts, Zabbix and other tools</strong><small>Use the API with an explicit status and message</small></span><span class="guide-toggle" aria-hidden="true">+</span></summary>
      <div class="integration-guide-body">
        <p>Use this shell example to publish an incident. Replace <code>YOUR_PHAROS_TOKEN</code> first. Running it publishes a real incident and queues configured notifications.</p>
        <div class="integration-code-head"><span>Incident request</span><button type="button" class="integration-copy" data-copy-target="incident-curl">Copy command</button></div>
        <pre id="incident-curl">curl -X POST '{{ \App\Services\PageUrls::api('incidents') }}' \
  -H 'Authorization: Bearer YOUR_PHAROS_TOKEN' \
  -H 'Content-Type: application/json' \
  -d '{{ json_encode(['name' => 'Service unavailable', 'status' => 'investigating', 'message' => 'We are investigating a service interruption.', 'impact' => 'major', 'components' => (object) [$guideComponent => 'major_outage']], JSON_UNESCAPED_SLASHES) }}'</pre>
        <p class="help">Existing clients may also use the <code>X-Cachet-Token</code> header. Keep the returned incident ID and post follow-up updates to that incident, rather than creating duplicates.</p>
      </div>
    </details>

    <details class="integration-guide" id="guide-heartbeats" @if(request()->has('heartbeats_page')) open @endif>
      <summary><span class="integration-guide-mark">↗</span><span><strong>Heartbeats from your jobs</strong><small>A successful job checks in; silence triggers the check</small></span><span class="guide-toggle" aria-hidden="true">+</span></summary>
      <div class="integration-guide-body">
        <p>Create a component with source <b>Heartbeat</b>. Have the job POST to its URL only after a successful run, within the configured interval. The secret in the URL authorizes the request; no API header is needed. Keep the minute scheduler running to detect a missed heartbeat.</p>
        @forelse ($heartbeats as $component)
          <div class="integration-code-head"><span>{{ $component->name }}</span><button type="button" class="integration-copy" data-copy-target="heartbeat-{{ $component->id }}">Copy URL</button></div>
          <pre id="heartbeat-{{ $component->id }}">{{ \App\Services\PageUrls::api('heartbeat/'.$component->check->target) }}</pre>
          @unless ($component->check->enabled)<p class="help">This heartbeat check is currently disabled. Enable it before relying on missed-heartbeat detection.</p>@endunless
        @empty
          <x-note id="integrations.no-heartbeats">No heartbeat components yet. Add a component with source <b>Heartbeat</b> and its push URL appears here.</x-note>
          <a class="integration-link" href="{{ \App\Services\PageUrls::route('admin.components.create') }}">Add a heartbeat component</a>
        @endforelse
        {{ $heartbeats->links('vendor.pagination.pharos', ['previousLabel' => 'Previous', 'nextLabel' => 'Next']) }}
      </div>
    </details>
  </div>
</section>
