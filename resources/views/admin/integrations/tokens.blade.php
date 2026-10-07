@extends('layouts.admin')
@section('title', __('API tokens'))
@section('content')
@include('partials.pagehead', [
  'title' => __('API tokens'),
  'sub' => __('Keys for n8n, scripts and other tools that talk to this page'),
])
@include('admin.integrations.partials.flow', ['active' => 'core'])

@if ($newToken)
  <div class="ix-reveal" role="status">
    <p>{{ __('Your new token. Copy it now: only a hash is stored, so it cannot be shown again.') }}</p>
    <div class="ix-code"><code id="new-token">{{ $newToken }}</code><button type="button" class="ix-copy" data-copy-target="new-token">{{ __('Copy') }}</button></div>
  </div>
@endif

@if ($canAdministerIntegrations)
<div class="ix-cols">
  <section class="ix-card" id="integration-tokens" aria-labelledby="tokens-title">
    <header><h3 id="tokens-title">{{ __('Tokens for') }} {{ $contextPage->name }}</h3><span class="hint">{{ __('A token only works for this page') }}</span></header>
    @if ($tokens->total() === 0)
      <div class="bd"><p class="op-dim">{{ __('No tokens yet. Create one to let n8n, Uptime Kuma or a script set a status.') }}</p></div>
    @else
      <div class="scroll"><table class="ix-table">
        <thead><tr><th>{{ __('Name') }}</th><th>{{ __('Access') }}</th><th class="hide-sm">{{ __('Owner') }}</th><th class="hide-sm">{{ __('Created') }}</th><th>{{ __('Last used') }}</th><th><span class="sr-only">{{ __('Actions') }}</span></th></tr></thead>
        <tbody>
        @foreach ($tokens as $token)
          <tr>
            <td><b>{{ $token->name }}</b></td>
            <td><span class="ix-state {{ $token->scope === 'write' ? 'w' : 'ok' }}">{{ ucfirst($token->scope) }}</span></td>
            <td class="hide-sm">{{ $token->user?->name ?? __('No owner') }}</td>
            <td class="num hide-sm">{{ $token->created_at->translatedFormat('j M Y') }}</td>
            <td class="num">{{ $token->last_used_at?->diffForHumans() ?? 'never' }}</td>
            <td class="right">
              <form method="POST" action="{{ \App\Services\PageUrls::route('admin.integrations.tokens.destroy', $token) }}"
                    data-confirm-title="Revoke {{ $token->name }}?"
                    data-confirm="{{ __('Anything still holding this token <strong>stops working straight away</strong>: scripts, integrations, monitors. A replacement is always a different token.') }}"
                    data-confirm-action="{{ __('Revoke token') }}">
                @csrf @method('DELETE')
                <button class="btn ghost op-sm" type="submit">{{ __('Revoke') }}</button>
              </form>
            </td>
          </tr>
        @endforeach
        </tbody>
      </table></div>
      @if ($tokens->hasPages())<div class="bd">{{ $tokens->links('vendor.pagination.pharos', ['previousLabel' => __('Previous'), 'nextLabel' => __('Next')]) }}</div>@endif
    @endif
    <div class="bd" style="border-top:1px solid var(--line)"><div class="ix-note"><span aria-hidden="true">ⓘ</span><span>{{ __('Unused for 90 days? Revoke it. A write token stops working at once when its owner loses edit rights on this page.') }}</span></div></div>
  </section>

  <div class="ix-side">
    <section class="ix-card" aria-labelledby="create-token-title">
      <header><h3 id="create-token-title">{{ __('Create a token') }}</h3></header>
      <div class="bd">
        <form method="POST" action="{{ \App\Services\PageUrls::route('admin.integrations.tokens.store') }}">
          @csrf
          <label class="ix-lbl" for="t-name">{{ __('What is it for?') }}</label>
          <input class="ix-input" id="t-name" name="name" type="text" placeholder="{{ __('n8n monitoring') }}" required maxlength="60" value="{{ old('name') }}">
          <span class="ix-lbl" id="scope-label">{{ __('Access') }}</span>
          <div class="ix-scope" role="radiogroup" aria-labelledby="scope-label">
            <label><input type="radio" name="scope" value="read" @checked(old('scope', 'read') === 'read')><span><b>{{ __('Read') }}</b><small>{{ __('See components and incidents, including private ones. Cannot change anything.') }}</small></span></label>
            <label><input type="radio" name="scope" value="write" @checked(old('scope') === 'write')><span><b>{{ __('Write') }}</b><small>{{ __('Also change component status and post incidents. Needed for Bring in.') }}</small></span></label>
          </div>
          <div class="actions" style="margin-top:14px">
            <button class="btn" type="submit">{{ __('Create token') }}</button>
            <button class="btn ghost" type="reset">{{ __('Clear') }}</button>
          </div>
        </form>
      </div>
    </section>
  </div>
</div>
@else
  <section class="ix-card">
    <header><h3>{{ __('Tokens for') }} {{ $contextPage->name }}</h3></header>
    <div class="bd"><div class="ix-note"><span aria-hidden="true">ⓘ</span><span>{{ __('Page administrators create and revoke API tokens. Ask one when a tool needs to read or change this page; the token is then shown to them once.') }}</span></div></div>
  </section>
@endif

<script defer src="{{ asset('assets/pharos-ops.js') }}?v=0.7.0"></script>
@endsection
