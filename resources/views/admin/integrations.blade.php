@extends('layouts.admin')
@section('title', 'Integrations')
@section('content')
@include('partials.pagehead', [
  'title' => 'Integrations',
  'sub' => 'How other systems tell this page what is going on, and how it tells them back',
])

@if ($newToken)
  <div class="panel" style="border-color:var(--brand)">
    <div class="panel-hd"><h3>Your new token</h3><span class="hint">Shown once</span></div>
    <div class="panel-bd">
      <div class="copy"><code>{{ $newToken }}</code></div>
      <p class="sub" style="font-size:12.5px;color:var(--ink-3)">
        Copy it now. Only a SHA-256 hash is stored, so this cannot be shown again.
        Lose it and you issue a new one.
      </p>
    </div>
  </div>
@endif

@if (auth()->user()->isAdmin())
<div class="panel">
  <div class="panel-hd"><h3>API tokens</h3><span class="hint">For n8n, scripts, anything that posts</span></div>
  @if ($tokens->isEmpty())
    <div class="empty">No tokens yet. Create one to let something else set a status.</div>
  @else
  <div class="scroll">
    <table>
      <thead><tr><th>Name</th><th>Created</th><th>Last used</th><th></th></tr></thead>
      <tbody>
      @foreach ($tokens as $token)
        <tr>
          <td>{{ $token->name }}</td>
          <td class="num">{{ $token->created_at->format('d M Y') }}</td>
          <td class="num">{{ $token->last_used_at?->diffForHumans() ?? 'never' }}</td>
          <td>
            <span class="rowacts">
              <form method="POST" action="{{ route('admin.integrations.tokens.destroy', $token) }}"
                    data-confirm-title="Revoke {{ $token->name }}?"
                    data-confirm="Anything still holding this token <strong>stops working straight away</strong> — scripts, integrations, monitors. A replacement is always a different token."
                    data-confirm-action="Revoke token">
                @csrf @method('DELETE')
                <button type="submit">Revoke</button>
              </form>
            </span>
          </td>
        </tr>
      @endforeach
      </tbody>
    </table>
  </div>
  @endif
  <div class="panel-bd" style="border-top:1px solid var(--line)">
    <form method="POST" action="{{ route('admin.integrations.tokens.store') }}" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
      @csrf
      <div class="field" style="flex:1;min-width:220px">
        <label for="t-name">What is this token for?</label>
        <input id="t-name" name="name" type="text" placeholder="n8n" required maxlength="60">
      </div>
      <button class="btn" type="submit">Create token</button>
      <button class="btn ghost" type="reset">Clear</button>
    </form>
  </div>
</div>
@endif

<div class="panel">
  <div class="panel-hd"><h3>Incoming: n8n, Zabbix, scripts</h3><span class="hint">Anything that can POST</span></div>
  <div class="panel-bd">
    <p class="sub" style="font-size:13.5px;color:var(--ink-2)">
      Set a component's status, or open an incident that touches several at once.
      The old Cachet <span class="mono">X-Cachet-Token</span> header works too, so existing
      workflows do not have to change.
    </p>
<pre>curl -X POST {{ url('/api/v1/incidents') }} \
  -H <span class="k">"Authorization: Bearer $TOKEN"</span> \
  -H "Content-Type: application/json" \
  -d '{"name":"Mail queue backed up","status":"investigating",
       "impact":"major","components":{"1":"partial_outage"}}'</pre>
  </div>
</div>

<div class="panel">
  <div class="panel-hd"><h3>Incoming: Uptime Kuma</h3><span class="hint">Push monitor</span></div>
  <div class="panel-bd">
    <p class="sub" style="font-size:13.5px;color:var(--ink-2)">
      In Kuma, add a <b>Push</b> monitor per component and point it at the URL below.
      Kuma calls in on every successful check; when it stops, the component goes down by itself.
    </p>
    @forelse ($heartbeats as $component)
      <div class="field">
        <label>{{ $component->name }}</label>
        <div class="copy"><code>{{ url("/api/v1/heartbeat/{$component->check->target}") }}</code></div>
      </div>
    @empty
      <div class="callout">
        No heartbeat components yet. Add a component with source <b>Heartbeat</b> and its
        push URL appears here.
      </div>
    @endforelse
  </div>
</div>

<div class="panel">
  <div class="panel-hd"><h3>Notifications</h3><span class="hint">Sent on every incident, and again when it closes</span></div>
  <div class="panel-bd">

    @if ($endpoints->isEmpty())
      <p class="modal-say">Nothing is notified yet. When a check fails, it shows on the status page and nowhere else.</p>
    @else
      <div class="scroll" style="border-radius:0">
        <table>
          <thead><tr><th>Where</th><th>Shape</th><th>Last attempt</th><th></th></tr></thead>
          <tbody>
          @foreach ($endpoints as $endpoint)
            <tr>
              <td>
                <span style="font-weight:600">{{ $endpoint->label }}</span>
                <div class="sub mono">{{ $endpoint->maskedUrl() }}</div>
              </td>
              <td class="sub">{{ $endpoint->formatLabel() }}</td>
              <td class="sub">
                @if (! $endpoint->last_attempt_at)
                  never tried
                @elseif ($endpoint->last_error)
                  <span class="pill b" style="font-size:10px;padding:1px 8px">failed</span>
                  <div class="sub">{{ $endpoint->last_error }}</div>
                @else
                  <span class="pill" style="font-size:10px;padding:1px 8px">HTTP {{ $endpoint->last_status }}</span>
                  <div class="sub">{{ $endpoint->last_attempt_at->diffForHumans() }}</div>
                @endif
              </td>
              <td>
                <span class="rowacts">
                  <form method="POST" action="{{ route('admin.integrations.endpoints.test', $endpoint) }}">
                    @csrf
                    <button type="submit">Send test</button>
                  </form>
                  <form method="POST" action="{{ route('admin.integrations.endpoints.destroy', $endpoint) }}"
                        data-confirm-title="Stop notifying {{ $endpoint->label }}?"
                        data-confirm="Incidents keep appearing on the status page. <strong>Nobody is told about them</strong> through this channel any more."
                        data-confirm-action="Remove">
                    @csrf @method('DELETE')
                    <button type="submit">Remove</button>
                  </form>
                </span>
              </td>
            </tr>
          @endforeach
          </tbody>
        </table>
      </div>
    @endif

    <form method="POST" action="{{ route('admin.integrations.endpoints.store') }}" style="display:flex;flex-direction:column;gap:14px">
      @csrf
      <div class="fields">
        <div class="field">
          <span class="lblrow"><label for="label">Name</label>
            @include('partials.tip', ['text' => 'Only for you, so this list stays readable once there is more than one.'])</span>
          <input id="label" name="label" type="text" maxlength="60" value="{{ old('label') }}" placeholder="#ops in Slack" required>
        </div>
        <div class="field">
          <span class="lblrow"><label for="format">Shape</label>
            @include('partials.tip', ['text' => 'Slack and Teams each demand their own JSON. Pick the wrong one and they answer 400 and show nothing.'])</span>
          <select id="format" name="format">
            @foreach (\App\Models\WebhookEndpoint::FORMATS as $value => $label)
              <option value="{{ $value }}" @selected(old('format') === $value)>{{ $label }}</option>
            @endforeach
          </select>
        </div>
      </div>
      <div class="field wide">
        <span class="lblrow"><label for="url">Address</label>
          @include('partials.tip', ['text' => 'For Slack an Incoming Webhook URL; for Teams a Workflow URL. Treat both as a password — anyone holding one can post in that channel.'])</span>
        <input id="url" name="url" type="url" value="{{ old('url') }}" required
               placeholder="https://hooks.slack.com/services/…">
        <span class="help">
          Slack: <span class="mono">Incoming Webhooks</span> in the app settings.
          Teams: a Workflow with <span class="mono">When a Teams webhook request is received</span>.
          Anything else: pick Generic JSON.
        </span>
      </div>
      <div class="actions">
        <button class="btn" type="submit">Add notification</button>
      </div>
    </form>

    <div class="callout">
      <b>One attempt, no retry.</b> A notification is sent once with a five second limit, because a slow
      receiver must not hold up publishing an outage. If it fails you see it in the table above, and the
      incident is still on the status page — but nobody was told. Where that matters, send to something
      that retries for you, such as n8n, and let it fan out from there.
    </div>

    @if ($webhookSecret && auth()->user()->isAdmin())
      <div class="field">
        <label>Signing secret</label>
        <div class="copy"><code>{{ $webhookSecret }}</code></div>
        <span class="help">
          Generic JSON deliveries carry <span class="mono">X-Pharos-Signature</span>, an HMAC-SHA256 of the
          body with this secret. Check it on the receiving end, otherwise anyone who learns the URL can
          forge events. Slack and Teams ignore unknown headers, so their deliveries are not signed —
          their URL is the credential.
        </span>
      </div>
      <form method="POST" action="{{ route('admin.integrations.webhook.rotate') }}"
            data-confirm-title="Rotate the signing secret?"
            data-confirm="Generic deliveries are signed with the new secret from the next event on. <strong>The receiving end rejects them</strong> until you paste the new secret there."
            data-confirm-action="Rotate secret">
        @csrf
        <button class="btn ghost" type="submit">Rotate secret</button>
      </form>
    @endif
  </div>
</div>
@endsection
