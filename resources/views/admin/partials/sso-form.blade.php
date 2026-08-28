{{-- The OpenID Connect form, on its own so the Settings screen stays readable.
     Expects $sso (App\Services\Sso) and $callbackUrl. Posts to admin.sso.update. --}}
<x-note id="sso.no-provisioning">
  <b>People sign in, they do not appear.</b> Someone who signs in through your provider
  needs an account here already, matched on their verified email address. An address nobody
  here uses is refused rather than turned into a new account. Signing in with a password
  keeps working, so a provider that is down never locks you out.
</x-note>

<div class="field" style="margin-top:16px">
  <label>Redirect URI to register at your provider</label>
  <div class="copy"><code>{{ $callbackUrl }}</code></div>
</div>

<form method="POST" action="{{ route('admin.sso.update') }}" style="display:flex;flex-direction:column;gap:16px">
  @csrf @method('PUT')
  <input type="hidden" name="_tab" value="sso">
  <div class="fields">
    <div class="field">
      <label for="issuer">Issuer URL</label>
      <input id="issuer" name="issuer" type="url" value="{{ old('issuer', $sso->issuer()) }}"
             placeholder="https://id.example.net/application/o/pharos/">
      <span class="help">Where <span class="mono">/.well-known/openid-configuration</span> lives. Must be https.</span>
      @error('issuer')<span class="help" style="color:var(--red-ink)">{{ $message }}</span>@enderror
    </div>
    <div class="field">
      <label for="provider_name">Button text</label>
      <input id="provider_name" name="provider_name" type="text"
             value="{{ old('provider_name', \App\Models\Setting::get('sso.provider_name')) }}" placeholder="Authentik">
      <span class="help">Shown as "Sign in with …" on the login screen.</span>
      @error('provider_name')<span class="help" style="color:var(--red-ink)">{{ $message }}</span>@enderror
    </div>
  </div>
  <div class="fields">
    <div class="field">
      <label for="client_id">Client ID</label>
      <input id="client_id" name="client_id" type="text"
             value="{{ old('client_id', \App\Models\Setting::get('sso.client_id')) }}" autocomplete="off">
      @error('client_id')<span class="help" style="color:var(--red-ink)">{{ $message }}</span>@enderror
    </div>
    <div class="field">
      <label for="client_secret">Client secret</label>
      <input id="client_secret" name="client_secret" type="password" autocomplete="new-password"
             placeholder="{{ $sso->clientSecret() ? 'Stored — leave empty to keep it' : '' }}">
      <span class="help">Stored encrypted and never shown again. Empty means unchanged.</span>
      @error('client_secret')<span class="help" style="color:var(--red-ink)">{{ $message }}</span>@enderror
    </div>
  </div>
  <div class="field wide">
    <label for="internal_hosts">Internal provider hosts</label>
    <input id="internal_hosts" name="internal_hosts" type="text"
           value="{{ old('internal_hosts', \App\Models\Setting::get('sso.internal_hosts')) }}"
           placeholder="id.intern.example.net, 192.168.1.20">
    <span class="help">
      Leave empty unless your provider runs on your own network. By default Pharos refuses to
      fetch anything on a private address: an issuer URL is typed by a person, and without that
      rule this server becomes a way to reach networks you cannot. Naming a host here vouches for
      that one host only — link-local addresses stay blocked whatever you put in this box.
    </span>
    @error('internal_hosts')<span class="help" style="color:var(--red-ink)">{{ $message }}</span>@enderror
  </div>

  <div class="switchrow">
    <span class="t"><strong>Offer single sign-on</strong><span class="s">Checked on save: a provider that cannot be reached will not switch on.</span></span>
    <label class="check"><input type="checkbox" name="enabled" value="1" @checked(old('enabled', $sso->enabled()))> Enabled</label>
  </div>
  <div class="actions">
    <button class="btn" type="submit">Save single sign-on</button>
    <button class="btn ghost" type="reset">Undo my changes</button>
  </div>
</form>
