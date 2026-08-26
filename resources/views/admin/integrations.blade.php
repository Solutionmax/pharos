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
                    onsubmit="return confirm('Revoke {{ $token->name }}? Anything using it stops working immediately.')">
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
  <div class="panel-hd"><h3>Outgoing webhook</h3><span class="hint">Fires on every incident change</span></div>
  <div class="panel-bd">
    <form method="POST" action="{{ route('admin.integrations.webhook') }}" style="display:flex;flex-direction:column;gap:14px">
      @csrf @method('PUT')
      <div class="field">
        <label for="webhook_url">Where to send it</label>
        <input id="webhook_url" name="webhook_url" type="url" value="{{ $webhookUrl }}"
               placeholder="https://hooks.example.net/webhook/pharos">
        <span class="help">An n8n webhook node, or anything that accepts JSON. Leave empty to switch off.</span>
      </div>
      <div class="actions">
        <button class="btn" type="submit">Save</button>
        <button class="btn ghost" type="reset">Undo my changes</button>
      </div>
    </form>

    @if ($webhookSecret)
      <div class="field">
        <label>Signing secret</label>
        <div class="copy"><code>{{ $webhookSecret }}</code></div>
        <span class="help">
          Every request carries <span class="mono">X-Pharos-Signature</span>, an HMAC-SHA256 of the
          body with this secret. Check it on the receiving end, otherwise anyone who learns your
          webhook URL can forge events.
        </span>
      </div>
      <form method="POST" action="{{ route('admin.integrations.webhook.rotate') }}"
            onsubmit="return confirm('Rotate the secret? The receiving end stops verifying until you update it there too.')">
        @csrf
        <button class="btn ghost" type="submit">Rotate secret</button>
      </form>
    @endif
  </div>
</div>
@endsection
