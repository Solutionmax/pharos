@php $destination = $destinationProfiles[$selectedFormat]; @endphp
<div class="integration-setup" id="add-notification">
  <form method="POST" action="{{ \App\Services\PageUrls::route('admin.integrations.endpoints.store') }}" id="destination-form">
    @csrf
    <div class="field">
      <label for="format">Destination</label>
      <select id="format" name="format" aria-describedby="destination-summary">
        @foreach (\App\Models\WebhookEndpoint::FORMATS as $value => $formatLabel)
          <option value="{{ $value }}" @selected($selectedFormat === $value)>{{ $formatLabel }}</option>
        @endforeach
      </select>
      <noscript><p class="help">Load the form for your destination: @foreach ($destinationProfiles as $value => $profile)<a href="{{ \App\Services\PageUrls::route('admin.integrations', ['destination' => $value]) }}#add-notification">{{ $profile['title'] }}</a> @endforeach</p></noscript>
    </div>
    <div class="field">
      <label for="label">Name in Pharos</label>
      <input id="label" name="label" type="text" maxlength="60" value="{{ old('label') }}" placeholder="{{ $destination['name'] }}" required>
      <span class="help">A label so your team can recognize this destination.</span>
    </div>
    <div class="field" id="webhook-address" @if ($selectedFormat === 'telegram') hidden @endif>
      <label for="url" id="destination-address-label">{{ $destination['address'] }}</label>
      <input id="url" name="url" type="url" maxlength="255" autocomplete="off" value="" @disabled($selectedFormat === 'telegram') @required($selectedFormat !== 'telegram') placeholder="{{ $destination['placeholder'] }}" aria-describedby="destination-address-help">
      <span class="help" id="destination-address-help">{{ $destination['help'] }}</span>
    </div>
    <fieldset class="integration-extra" id="telegram-fields" @if ($selectedFormat !== 'telegram') hidden disabled @endif>
      <legend>Bot and destination</legend>
      <div class="field"><label for="telegram_token">Telegram bot token</label><input type="password" id="telegram_token" name="telegram_token" autocomplete="new-password" maxlength="200" placeholder="123456789:YOUR_BOT_TOKEN" @required($selectedFormat === 'telegram')></div>
      <div class="field"><label for="telegram_chat_id">Chat ID or public channel @username</label><input id="telegram_chat_id" name="telegram_chat_id" value="{{ old('telegram_chat_id') }}" placeholder="-1001234567890 or @your_status_channel" maxlength="100" @required($selectedFormat === 'telegram')></div>
      <p class="help">{{ $destinationProfiles['telegram']['help'] }}</p>
    </fieldset>
    <fieldset class="integration-extra" id="signal-fields" @if ($selectedFormat !== 'signal') hidden disabled @endif>
      <legend>Your Signal bridge</legend>
      <div class="field"><label for="signal_number">Signal sender number</label><input id="signal_number" name="signal_number" placeholder="+34123456789" value="{{ old('signal_number') }}" @required($selectedFormat === 'signal')></div>
      <div class="field"><label for="signal_recipient">Recipient number or group ID</label><input id="signal_recipient" name="signal_recipient" placeholder="+34987654321 or group.YOUR_GROUP_ID" value="{{ old('signal_recipient') }}" @required($selectedFormat === 'signal')></div>
      <div class="field"><label for="signal_token">Bridge bearer token</label><input type="password" id="signal_token" name="signal_token" autocomplete="new-password" placeholder="Token configured at your reverse proxy" minlength="16" maxlength="512" @required($selectedFormat === 'signal')></div>
    </fieldset>
    @if ($errors->any())<p class="help">Re-enter any webhook URL or token before saving. Credentials are not retained after validation errors.</p>@endif
    <div class="actions"><button class="btn" type="submit">Add notification</button></div>
    <p class="help">Adding saves the destination. Send test makes a real delivery when you choose it from the saved list.</p>
  </form>
  <aside class="destination-guide" aria-labelledby="destination-title">
    <span class="integration-eyebrow">SETUP GUIDE</span>
    <h3 id="destination-title">{{ $destination['title'] }}</h3>
    <p id="destination-summary">{{ $destination['summary'] }}</p>
    <div class="integration-flow"><span>Pharos incident</span><b aria-hidden="true">→</b><span id="destination-flow-name">{{ $destination['title'] }}</span></div>
    <ol id="destination-steps" class="integration-steps">@foreach ($destination['steps'] as $step)<li>{{ $step }}</li>@endforeach</ol>
    <div class="destination-result"><strong>What arrives</strong><p id="destination-result">{{ $destination['result'] }}</p></div>
    <p id="generic-signature-help" @if ($selectedFormat !== 'generic') hidden @endif class="help">Generic JSON requests include X-Pharos-Signature. Verify the raw request body with the signing secret before acting on an event. Receiver authentication must match what Pharos sends; it does not add custom Basic or Header Auth credentials for Generic JSON.</p>
    <a id="destination-docs" class="integration-link" href="{{ $destination['docs'] }}" target="_blank" rel="noopener noreferrer">Open setup documentation ↗</a>
    <p id="destination-announcement" class="sr-only" aria-live="polite"></p>
  </aside>
</div>
<script type="application/json" id="integration-profiles">@json($destinationProfiles)</script>
