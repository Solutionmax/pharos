<section class="ix-card">
  <header><h3>{{ __('Passkeys') }}</h3></header>
  <div class="bd" data-passkey-register data-options-url="{{ route('admin.profile.passkeys.options') }}" data-submit-url="{{ route('admin.profile.passkeys.store') }}" data-unavailable="{{ __('Passkeys are unavailable here. Use a supported browser on the configured HTTPS hostname.') }}" data-error="{{ __('Passkey registration failed. Start again and verify your device.') }}">
    <p class="op-dim passkey-copy">{{ __('Sign in with your device or security key. Passkeys require HTTPS and device verification. Your existing two factor step remains enabled.') }}</p>
    @php $passkeys = \App\Models\Passkey::where('user_id', auth()->id())->orderBy('id')->get(); @endphp
    @if ($passkeys->isNotEmpty())
      <div class="passkey-list">
        @foreach ($passkeys as $key)
          <details class="beta-disclosure">
            <summary>{{ $key->name }}<span class="op-dim">{{ $key->last_used_at?->diffForHumans() ?? __('Never used') }}</span><span class="beta-chevron" aria-hidden="true">⌄</span></summary>
            <form class="beta-form" method="post" action="{{ route('admin.profile.passkeys.destroy', $key->id) }}">
              @csrf @method('DELETE')
              <div class="field"><label for="passkey-password-{{ $key->id }}">{{ __('Current password') }}</label><input id="passkey-password-{{ $key->id }}" name="current_password" type="password" autocomplete="current-password" required></div>
              <div class="op-acts"><button class="btn danger">{{ __('Remove passkey') }}</button></div>
            </form>
          </details>
        @endforeach
      </div>
    @endif
    <form class="beta-form" data-passkey-form method="post" action="{{ route('admin.profile.passkeys.options') }}">
      @csrf
      <div class="fields">
        <div class="field"><label for="passkey-name">{{ __('Passkey name') }}</label><input id="passkey-name" name="name" type="text" maxlength="100" required></div>
        <div class="field"><label for="passkey-password">{{ __('Current password') }}</label><input id="passkey-password" name="current_password" type="password" autocomplete="current-password" required></div>
      </div>
      <div class="op-acts"><button class="btn" type="submit" disabled>{{ __('Add passkey') }}</button></div>
      <p class="op-dim" role="status" data-passkey-status></p>
    </form>
  </div>
</section>
<script defer src="{{ asset('assets/pharos-passkeys.js') }}"></script>
