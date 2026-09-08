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
    <p class="sub" style="font-size:13.5px;color:var(--ink-2);overflow-wrap:anywhere">
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
  <div class="panel-hd"><h3>Incoming: Uptime Kuma and heartbeats</h3><span class="hint">Webhook or POST</span></div>
  <div class="panel-bd">
    <p class="sub" style="font-size:13.5px;color:var(--ink-2);overflow-wrap:anywhere">
      For Kuma, configure a <b>Webhook notification</b> with its default JSON body, an
      <code>Authorization: Bearer TOKEN</code> header and URL
      <code>{{ url('/api/v1/kuma/components/COMPONENT_ID') }}</code>.
      Choose a manually managed component; Pharos maps Kuma up/down notifications to its status.
      A Kuma Push monitor receives heartbeats; it does not send them to Pharos.
      The separate heartbeat URLs below accept POSTs from your own jobs and mean “healthy”.
    </p>
    @forelse ($heartbeats as $component)
      <div class="field">
        <label>{{ $component->name }}</label>
        <div class="copy"><code>{{ url("/api/v1/heartbeat/{$component->check->target}") }}</code></div>
      </div>
    @empty
      <x-note id="integrations.no-heartbeats">
        No heartbeat components yet. Add a component with source <b>Heartbeat</b> and its
        push URL appears here.
      </x-note>
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
            @include('partials.tip', ['text' => 'Choose the receiving service so Pharos sends the JSON it expects.'])</span>
          <select id="format" name="format">
            @foreach (\App\Models\WebhookEndpoint::FORMATS as $value => $label)
              <option value="{{ $value }}" @selected(old('format') === $value)>{{ $label }}</option>
            @endforeach
          </select>
        </div>
      </div>
      <div class="field wide" id="webhook-address">
        <span class="lblrow"><label for="url">Address</label>
          @include('partials.tip', ['text' => 'Use the service webhook URL, or your Signal bridge /v2/send URL. Webhook URLs can contain credentials; keep them private.'])</span>
        <input id="url" name="url" type="url" value="{{ old('url') }}" required
               placeholder="https://hooks.slack.com/services/…">
        <span class="help">
          Slack: <span class="mono">Incoming Webhooks</span> in the app settings.
          Teams: a Workflow with <span class="mono">When a Teams webhook request is received</span>.
          Discord: copy the webhook URL from channel settings.
          Signal: use your own HTTPS bridge at <code>/v2/send</code>, protected by a bearer-token reverse proxy.
          Anything else: pick Generic JSON.
        </span>
      </div>
      <div id="telegram-fields" hidden>
        <p class="help">Create a bot with @BotFather in Telegram and paste its token below. Start a conversation with the bot, or add it to your group or channel with permission to send messages. Enter the chat ID (including the minus sign for groups) or a public channel @username, then use Send test.</p>
        <div class="field"><label for="telegram_token">Telegram bot token</label><input type="password" id="telegram_token" name="telegram_token" autocomplete="new-password" maxlength="200"></div>
        <div class="field"><label for="telegram_chat_id">Chat ID or channel @username</label><input id="telegram_chat_id" name="telegram_chat_id" value="{{ old('telegram_chat_id') }}" placeholder="-1001234567890" maxlength="100"></div>
      </div>
      <div id="signal-fields" hidden>
        <p class="help">Signal requires a separately managed signal-cli-rest-api bridge. Configure its reverse proxy to check the bearer token. Pharos does not register or host a Signal account.</p>
        <div class="field"><label for="signal_number">Signal sender number</label><input id="signal_number" name="signal_number" placeholder="+31612345678" value="{{ old('signal_number') }}"></div>
        <div class="field"><label for="signal_recipient">Signal recipient or group ID</label><input id="signal_recipient" name="signal_recipient" value="{{ old('signal_recipient') }}"></div>
        <div class="field"><label for="signal_token">Bridge bearer token</label><input type="password" id="signal_token" name="signal_token" autocomplete="new-password"></div>
      </div>
      <script>
        document.addEventListener('DOMContentLoaded', function () {
          const format = document.getElementById('format');
          const fields = document.getElementById('signal-fields');
          function update() {
            fields.hidden = format.value !== 'signal';
            fields.querySelectorAll('input').forEach(input => { input.disabled = fields.hidden; });
            const telegram = document.getElementById('telegram-fields');
            telegram.hidden = format.value !== 'telegram';
            telegram.querySelectorAll('input').forEach(input => { input.disabled = telegram.hidden; input.required = !telegram.hidden; });
            const address = document.getElementById('webhook-address');
            address.hidden = !telegram.hidden;
            document.getElementById('url').disabled = address.hidden;
          }
          format.addEventListener('change', update); update();
        });
      </script>
      <div class="actions">
        <button class="btn" type="submit">Add notification</button>
      </div>
    </form>

    <x-note id="integrations.delivery">Incident notifications are queued and sent by the minute scheduler. Temporary failures retry up to six attempts with backoff; Send test makes one immediate attempt.</x-note>
    @if ($deliveries->isNotEmpty())
    <div class="scroll"><table>
      <thead><tr><th>Destination</th><th>Attempts</th><th>Delivery</th></tr></thead>
      <tbody>@foreach ($deliveries as $delivery)
      <tr><td>{{ $delivery->endpoint?->label ?? 'Removed' }}</td><td>{{ $delivery->attempts }}</td>
      <td>{{ $delivery->sent_at ? 'Delivered' : ($delivery->attempts >= 6 ? 'Stopped — check destination' : 'Queued for retry') }}
      @if ($delivery->error)<span class="help">{{ $delivery->error }}</span>@endif</td></tr>
      @endforeach</tbody>
    </table></div>
    @endif

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
