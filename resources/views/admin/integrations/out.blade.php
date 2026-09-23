@extends('layouts.admin')
@section('title', 'Send out')
@section('content')
@include('partials.pagehead', [
  'title' => 'Send out',
  'sub' => 'Tell your team when an incident starts, changes or is resolved',
])
@include('admin.integrations.partials.flow', ['active' => 'out'])

@php
  $tiles = [
      'slack' => ['Slack', 'Channel via incoming webhook', 'In Slack: Apps, Incoming Webhooks, Add to channel, then copy the URL.'],
      'teams' => ['Microsoft Teams', 'Channel via Workflows', 'In Teams: the channel, Workflows, "Post to a channel when a webhook request is received", then copy the URL.'],
      'discord' => ['Discord', 'Server channel webhook', 'In Discord: channel settings, Integrations, Webhooks, New webhook, Copy URL.'],
      'telegram' => ['Telegram', 'Bot message to a chat', 'Create a bot with @BotFather, add it to the chat, then paste the bot token and the chat ID.'],
      'signal' => ['Signal', 'Through your own bridge', 'Point Pharos at your signal cli REST bridge; Pharos posts the message text.'],
      'generic' => ['Generic JSON', 'n8n, Zapier, your own code', 'Use the Production URL of a POST webhook. In n8n that is the Webhook node, not HTTP Request.'],
  ];
  $destination = $destinationProfiles[$selectedFormat];
  $chosenEvents = old('events', \App\Models\WebhookEndpoint::DEFAULT_EVENTS);
@endphp

<div class="ix-cols">
  <section class="ix-card" id="add-notification" aria-labelledby="add-destination-title">
    <header><h3 id="add-destination-title">Add a destination</h3><span class="hint">Takes about a minute</span></header>
    <div class="bd">
      @if ($canEditIntegrations)
      <form method="POST" action="{{ \App\Services\PageUrls::route('admin.integrations.endpoints.store') }}" id="destination-form">
        @csrf
        <input type="hidden" name="events_set" value="1">
        <ol class="ix-steps">
          <li><div>
            <h4 id="channel-heading">Where should updates go?</h4>
            <span class="sub">Pick the tool your team already watches.</span>
            <div class="ix-tiles" role="radiogroup" aria-labelledby="channel-heading">
              @foreach ($tiles as $value => [$title, $desc, $help])
                <label class="ix-tile" data-help="{{ $help }}">
                  <input type="radio" name="format" value="{{ $value }}" @checked($selectedFormat === $value)>
                  @include('partials.brand-mark', ['mark' => $value])
                  <b>{{ $title }}</b><small>{{ $desc }}</small>
                </label>
              @endforeach
            </div>
            <noscript><p class="help">Without JavaScript, load the fields for your tool: @foreach ($destinationProfiles as $value => $profile)<a href="{{ \App\Services\PageUrls::route('admin.integrations.out', ['destination' => $value]) }}#add-notification">{{ $profile['title'] }}</a> @endforeach</p></noscript>
          </div></li>

          <li><div>
            <h4>Connect it</h4>
            <span class="sub" id="destination-help">{{ $tiles[$selectedFormat][2] }}</span>
            <div class="ix-row">
              <div class="field">
                <label for="label">Name in Pharos</label>
                <input id="label" name="label" type="text" maxlength="60" value="{{ old('label') }}" placeholder="{{ $destination['name'] }}" required>
              </div>
              <div class="field" id="webhook-address" @if ($selectedFormat === 'telegram') hidden @endif>
                <label for="url" id="destination-address-label">{{ $destination['address'] }}</label>
                <input id="url" name="url" type="url" maxlength="255" autocomplete="off" value="" @disabled($selectedFormat === 'telegram') @required($selectedFormat !== 'telegram') placeholder="{{ $destination['placeholder'] }}" aria-describedby="destination-address-help">
              </div>
            </div>
            <p class="help" id="destination-address-help" style="margin-top:8px">{{ $destination['help'] }}</p>
            <fieldset class="integration-extra" id="telegram-fields" @if ($selectedFormat !== 'telegram') hidden disabled @endif>
              <legend>Bot and chat</legend>
              <div class="ix-row">
                <div class="field"><label for="telegram_token">Telegram bot token</label><input type="password" id="telegram_token" name="telegram_token" autocomplete="new-password" maxlength="200" placeholder="123456789:YOUR_BOT_TOKEN" @required($selectedFormat === 'telegram')></div>
                <div class="field"><label for="telegram_chat_id">Chat ID or public channel @username</label><input id="telegram_chat_id" name="telegram_chat_id" value="{{ old('telegram_chat_id') }}" placeholder="-1001234567890 or @your_status_channel" maxlength="100" @required($selectedFormat === 'telegram')></div>
              </div>
            </fieldset>
            <fieldset class="integration-extra" id="signal-fields" @if ($selectedFormat !== 'signal') hidden disabled @endif>
              <legend>Your Signal bridge</legend>
              <div class="ix-row">
                <div class="field"><label for="signal_number">Signal sender number</label><input id="signal_number" name="signal_number" placeholder="+34123456789" value="{{ old('signal_number') }}" @required($selectedFormat === 'signal')></div>
                <div class="field"><label for="signal_recipient">Recipient number or group ID</label><input id="signal_recipient" name="signal_recipient" placeholder="+34987654321 or group.YOUR_GROUP_ID" value="{{ old('signal_recipient') }}" @required($selectedFormat === 'signal')></div>
              </div>
              <div class="field" style="margin-top:12px"><label for="signal_token">Bridge bearer token</label><input type="password" id="signal_token" name="signal_token" autocomplete="new-password" placeholder="Token configured at your reverse proxy" minlength="16" maxlength="512" @required($selectedFormat === 'signal')></div>
            </fieldset>
            @if ($errors->any())<p class="help">Enter any webhook URL or token again before saving. Credentials are not kept after a validation error.</p>@endif
            <details class="ix-more">
              <summary>Step by step: <span id="destination-title">{{ $destination['title'] }}</span></summary>
              <div>
                <p id="destination-summary">{{ $destination['summary'] }}</p>
                <ol id="destination-steps" class="integration-steps">@foreach ($destination['steps'] as $step)<li>{{ $step }}</li>@endforeach</ol>
                <p><strong>What arrives:</strong> <span id="destination-result">{{ $destination['result'] }}</span></p>
                <a id="destination-docs" class="integration-link" href="{{ $destination['docs'] }}" target="_blank" rel="noopener noreferrer">Open the setup documentation ↗</a>
              </div>
            </details>
          </div></li>

          <li><div>
            <h4 id="events-heading">Which moments?</h4>
            <span class="sub">Internal incidents are included too: this goes to your own team, not to subscribers.</span>
            <div class="ix-chips" role="group" aria-labelledby="events-heading">
              @foreach (\App\Models\WebhookEndpoint::EVENTS as $value => $label)
                <label class="ix-chip"><input type="checkbox" name="events[]" value="{{ $value }}" @checked(in_array($value, (array) $chosenEvents, true))>{{ $label }}</label>
              @endforeach
            </div>
            @error('events')<p class="help" style="color:var(--red-ink);margin-top:6px">{{ $message }}</p>@enderror
          </div></li>

          <li><div>
            <h4>Save and test</h4>
            <span class="sub">A test sends one real message, so you see exactly what your team will see.</span>
            <div class="actions">
              <button class="btn" type="submit">Save destination</button>
              <button class="btn ghost" type="submit" name="send_test" value="1">Save and send test</button>
              <button class="btn ghost" type="reset">Clear</button>
            </div>
            <details class="ix-more">
              <summary>How delivery works</summary>
              <div><p>Messages go out within a minute, from the scheduler. If your tool is down, Pharos retries up to six times with growing pauses; every attempt is in the <a href="{{ \App\Services\PageUrls::route('admin.integrations.log') }}">delivery log</a>. Send test makes one immediate attempt.</p></div>
            </details>
            <details class="ix-more" id="generic-signature-help">
              <summary>Generic JSON: payload and signature</summary>
              <div>
                <p>Every Generic JSON request carries <code>X-Pharos-Signature</code>, an HMAC SHA256 of the raw body with this page's signing secret. Check it before acting on an event. Slack, Teams and the chat apps are not signed: their URL is the credential.</p>
                <div class="ix-code"><pre id="payload-example">{"event": "incident.updated", "incident": {"id": 42, "name": "Mail delayed", "status": "Watching", "impact": "minor", "resolved_at": null, "components": ["Outbound queue"]}}</pre><button type="button" class="ix-copy" data-copy-target="payload-example">Copy</button></div>
                <p>Maintenance arrives as <code>maintenance.scheduled</code>, <code>maintenance.started</code>, <code>maintenance.completed</code> or <code>maintenance.cancelled</code> with a <code>maintenance</code> object.</p>
              </div>
            </details>
          </div></li>
        </ol>
      </form>
      <p id="destination-announcement" class="sr-only" aria-live="polite"></p>
      <script type="application/json" id="integration-profiles">@json($destinationProfiles)</script>
      @else
        <div class="ix-note"><span aria-hidden="true">ⓘ</span><span>Editors of this page add destinations. You can see what is connected and whether it works.</span></div>
      @endif
    </div>
  </section>

  <div class="ix-side">
    <section class="ix-card" id="destinations" aria-labelledby="destinations-title">
      <header><h3 id="destinations-title">Your destinations</h3><span class="hint">{{ $endpointCount }} on this page</span></header>
      <div class="bd">
        @if ($endpoints->total() === 0)
          <p class="op-dim">No destinations yet. Add one to send incident events to a chat or workflow.</p>
        @else
          <ul class="ix-list">
            @foreach ($endpoints as $endpoint)
              @php [$tone, $word] = $endpoint->health(); @endphp
              <li>
                @include('partials.brand-mark', ['mark' => $endpoint->format])
                <span>
                  <b>{{ $endpoint->label }}</b>
                  <span class="d">{{ $tiles[$endpoint->format][0] ?? $endpoint->formatLabel() }} · {{ $endpoint->last_attempt_at ? 'last attempt '.$endpoint->last_attempt_at->format('j M H:i') : 'never tried' }}</span>
                  @if ($canEditIntegrations)<span class="d mono">{{ $endpoint->maskedUrl() }}</span>@endif
                </span>
                <span class="ix-state {{ $tone }}">{{ $word }}</span>
                <div class="ix-extra">
                  @if ($canEditIntegrations && ($hint = $endpoint->hint()))
                    <div class="ix-note warn"><span aria-hidden="true">⚠</span><span><b>{{ $endpoint->label }}</b> {{ $hint }} <a href="{{ \App\Services\PageUrls::route('admin.integrations.log', ['delivery_endpoint' => $endpoint->id]) }}">See the log</a></span></div>
                  @endif
                  <span class="d">Sends: {{ collect($endpoint->chosenEvents())->map(fn ($e) => \App\Models\WebhookEndpoint::EVENTS[$e] ?? $e)->join(', ') }}</span>
                  @if ($canEditIntegrations)
                    <details class="ix-more" style="margin-top:0">
                      <summary>Change moments</summary>
                      <div>
                        <form method="POST" action="{{ \App\Services\PageUrls::route('admin.integrations.endpoints.events', $endpoint) }}" class="ix-inline-form">
                          @csrf @method('PUT')
                          <div class="ix-chips">
                            @foreach (\App\Models\WebhookEndpoint::EVENTS as $value => $label)
                              <label class="ix-chip"><input type="checkbox" name="events[]" value="{{ $value }}" @checked($endpoint->wants($value))>{{ $label }}</label>
                            @endforeach
                          </div>
                          <button class="btn ghost op-sm" type="submit" style="align-self:flex-start">Save moments</button>
                        </form>
                      </div>
                    </details>
                    <span class="rowacts" style="justify-content:flex-start">
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
                  @endif
                </div>
              </li>
            @endforeach
          </ul>
          {{ $endpoints->links('vendor.pagination.pharos', ['previousLabel' => 'Previous', 'nextLabel' => 'Next']) }}
        @endif
      </div>
    </section>

    @if ($webhookSecret && $canAdministerIntegrations)
      <section class="ix-card" aria-labelledby="secret-title">
        <header><h3 id="secret-title">Signing secret</h3><span class="hint">Generic JSON only</span></header>
        <div class="bd">
          <div class="ix-code"><code id="signing-secret">{{ $webhookSecret }}</code><button type="button" class="ix-copy" data-copy-target="signing-secret">Copy</button></div>
          <form method="POST" action="{{ \App\Services\PageUrls::route('admin.integrations.webhook.rotate') }}" style="margin-top:12px"
                data-confirm-title="Rotate the signing secret?"
                data-confirm="Generic deliveries are signed with the new secret from the next event on. <strong>The receiving end rejects them</strong> until you paste the new secret there."
                data-confirm-action="Rotate secret">
            @csrf
            <button class="btn ghost op-sm" type="submit">Rotate secret</button>
          </form>
        </div>
      </section>
    @endif

    <section class="ix-card">
      <header><h3>About the logos</h3></header>
      <div class="bd"><p class="op-dim">Discord, Telegram, Signal, n8n and Uptime Kuma use their real marks. Slack and Microsoft Teams stay as letters until their official brand kit files are added.</p></div>
    </section>

    <section class="ix-card">
      <header><h3>Not here</h3></header>
      <div class="bd"><p style="font-size:13px;color:var(--ink-2)">Emails to subscribers are set up under <a class="integration-link" href="{{ \App\Services\PageUrls::route('admin.subscribers') }}">Subscribers</a> and <a class="integration-link" href="{{ \App\Services\PageUrls::route('admin.mail.edit') }}">Page email</a>. This screen is for your own team.</p></div>
    </section>
  </div>
</div>

<script defer src="{{ asset('assets/pharos-ops.js') }}?v=0.7.0"></script>
@endsection
