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

<div class="integration-overview">
  <a href="#outgoing-integrations"><span class="integration-eyebrow">SEND OUT</span><strong>Notify your team</strong><span>Pharos incidents → chat or workflow</span><small>Slack, Teams, Discord, Telegram, Signal and Generic JSON</small></a>
  <a href="#incoming-integrations"><span class="integration-eyebrow">BRING IN</span><strong>Connect your monitoring</strong><span>Your monitor or job → Pharos</span><small>n8n, Uptime Kuma, scripts and heartbeats</small></a>
</div>
<div class="panel" id="outgoing-integrations">
  <div class="panel-hd"><h3>Notifications</h3><span class="hint">Pharos → your tools</span></div>
  <div class="panel-bd">

    @if ($endpoints->isEmpty())
      <p class="modal-say">No outgoing destinations yet. Add one below to send incident events to a chat or workflow. Subscriber email is configured separately.</p>
    @else
      <div class="scroll" style="border-radius:0">
        <table>
          <thead><tr><th>Where</th><th>Destination</th><th>Last attempt</th><th></th></tr></thead>
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
                  <form method="POST" action="{{ \App\Services\PageUrls::route('admin.integrations.endpoints.test', $endpoint) }}">
                    @csrf
                    <button type="submit">Send test</button>
                  </form>
                  <form method="POST" action="{{ \App\Services\PageUrls::route('admin.integrations.endpoints.destroy', $endpoint) }}"
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

    @include('admin.partials.integration-destination')

    <x-note id="integrations.delivery">Incident notifications are queued and sent by the minute scheduler. Temporary failures retry up to six attempts with backoff; Send test makes one immediate attempt.</x-note>
    @if ($deliveries->isNotEmpty())
    <div class="scroll"><table>
      <thead><tr><th>Destination</th><th>Attempts</th><th>Delivery</th></tr></thead>
      <tbody>@foreach ($deliveries as $delivery)
      <tr><td>{{ $delivery->endpoint?->label ?? 'Removed' }}</td><td>{{ $delivery->attempts }}</td>
      <td>{{ $delivery->sent_at ? 'Delivered' : ($delivery->attempts >= 6 ? 'Stopped ; check destination' : 'Queued for retry') }}
      @if ($delivery->error)<span class="help">{{ $delivery->error }}</span>@endif</td></tr>
      @endforeach</tbody>
    </table></div>
    @endif

    @if ($webhookSecret && auth()->user()->isAdmin())
      <details class="integration-example"><summary>Verify Generic JSON deliveries</summary>
      <div class="field">
        <label>Signing secret</label>
        <div class="copy"><code>{{ $webhookSecret }}</code></div>
        <span class="help">
          Generic JSON deliveries carry <span class="mono">X-Pharos-Signature</span>, an HMAC-SHA256 of the
          body with this secret. Check it on the receiving end, otherwise anyone who learns the URL can
          forge events. Slack and Teams ignore unknown headers, so their deliveries are not signed ;
          their URL is the credential.
        </span>
      </div>
      <form method="POST" action="{{ \App\Services\PageUrls::route('admin.integrations.webhook.rotate') }}"
            data-confirm-title="Rotate the signing secret?"
            data-confirm="Generic deliveries are signed with the new secret from the next event on. <strong>The receiving end rejects them</strong> until you paste the new secret there."
            data-confirm-action="Rotate secret">
        @csrf
        <button class="btn ghost" type="submit">Rotate secret</button>
      </form>
      </details>
    @endif
  </div>
</div>


@include('admin.partials.integration-guides')

@if (auth()->user()->isAdmin())
<div class="panel" id="integration-tokens">
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
              <form method="POST" action="{{ \App\Services\PageUrls::route('admin.integrations.tokens.destroy', $token) }}"
                    data-confirm-title="Revoke {{ $token->name }}?"
                    data-confirm="Anything still holding this token <strong>stops working straight away</strong> ; scripts, integrations, monitors. A replacement is always a different token."
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
    <form method="POST" action="{{ \App\Services\PageUrls::route('admin.integrations.tokens.store') }}" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
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


<script defer src="{{ asset('assets/integrations.js') }}?v=0.6.0"></script>
@endsection
