@extends('layouts.admin')
@section('title', 'Branding')
@section('content')
@include('partials.pagehead', [
  'title' => 'Branding',
  'sub' => 'How your status page introduces itself',
])

<form method="POST" action="{{ route('admin.branding.update') }}" enctype="multipart/form-data">
  @csrf @method('PUT')

  <div class="panel">
    <div class="panel-hd"><h3>Name and colour</h3><span class="hint">Free, no licence needed</span></div>
    <div class="panel-bd">
      <div class="fields">
        <div class="field">
          <label for="name">Name on the page</label>
          <input id="name" name="name" type="text" value="{{ old('name', $brand['name']) }}" required maxlength="60">
        </div>
        <div class="field">
          <label for="accent">Accent colour</label>
          <input id="accent" name="accent" type="color" value="{{ old('accent', $brand['accent']) }}">
          <span class="help">Used for the mark, links and buttons, on both themes.</span>
        </div>
      </div>
      @unless ($licensed)
        <div class="actions">
          <button class="btn" type="submit">Save</button>
          <button class="btn ghost" type="reset">Undo my changes</button>
        </div>
      @endunless
    </div>
  </div>

  <div class="panel">
    <div class="panel-hd">
      <h3>Your own images</h3>
      @if ($licensed)<span class="hint">Included in your brand pack</span>@else<span class="pro">Brand pack</span>@endif
    </div>
    <div class="panel-bd">
      @if ($licensed)
        <div class="fields">
          <div class="field">
            <label for="logo">Logo</label>
            @if ($brand['logo'])
              <span class="brand-preview"><img src="{{ $brand['logo'] }}" alt="Current logo"></span>
            @endif
            <input id="logo" name="logo" type="file" accept="image/png,image/jpeg,image/webp">
            <span class="help">PNG, JPG or WebP, up to 512&nbsp;KB and 1200×400. Replaces the lighthouse and the name.</span>
            @if ($brand['logo'])
              <label class="check"><input type="checkbox" name="remove_logo" value="1"> Remove the logo and go back to the name</label>
            @endif
          </div>
          <div class="field">
            <label for="logo_dark">Logo for dark mode (optional)</label>
            @if ($brand['logo_dark'])
              <span class="brand-preview dark"><img src="{{ $brand['logo_dark'] }}" alt="Current dark-mode logo"></span>
            @endif
            <input id="logo_dark" name="logo_dark" type="file" accept="image/png,image/jpeg,image/webp">
            <span class="help">Shown instead of the logo above when the visitor's theme is dark. Leave empty if your logo already works on both.</span>
            @if ($brand['logo_dark'])
              <label class="check"><input type="checkbox" name="remove_logo_dark" value="1"> Remove the dark-mode logo</label>
            @endif
          </div>
          <div class="field">
            <label for="favicon">Favicon</label>
            <span class="brand-preview icon"><img src="{{ $brand['favicon'] }}" alt="Current favicon"></span>
            <input id="favicon" name="favicon" type="file" accept="image/png,image/webp,image/x-icon">
            <span class="help">PNG, WebP or ICO, up to 128&nbsp;KB and 512×512.</span>
          </div>
        </div>

        <div class="switchrow">
          <span class="t">
            <strong>Hide "Powered by Pharos"</strong>
            <span class="s">The footer credit on the public page.</span>
          </span>
          <span class="check"><input type="checkbox" name="credit_hidden" value="1" @checked($brand['credit_hidden'])></span>
        </div>

        <div class="actions">
          <button class="btn" type="submit">Save branding</button>
          <button class="btn ghost" type="reset">Undo my changes</button>
        </div>
      @else
        <div class="locked">
          <div>
            <strong style="font-size:13.5px">One-time purchase</strong>
            <p class="sub" style="margin-top:4px;font-size:13px;color:var(--ink-3)">
              Your own logo and favicon, editable email templates, and the
              footer credit gone. Everything else in Pharos is free and stays free.
            </p>
          </div>
          <a class="btn" href="{{ config('pharos.buy_url') }}" target="_blank" rel="noopener">Buy the brand pack</a>
        </div>
      @endif
    </div>
  </div>
</form>

<div class="panel">
  <div class="panel-hd">
    <h3>Licence</h3>
    @if ($licensed)
      <span class="hint">Active · {{ $issuedTo }}@if ($expiresAt) · support until {{ $expiresAt->format('d M Y') }}@endif</span>
    @else
      <span class="hint">Not activated</span>
    @endif
  </div>
  <div class="panel-bd">
    @if ($licensed)
      @if ($expiringSoon)
        <x-note id="branding.expiring" warn>
          <b>{{ $daysLeft === 0 ? 'Runs out today.' : 'Runs out in '.$daysLeft.' '.\Illuminate\Support\Str::plural('day', $daysLeft).'.' }}</b>
          On {{ $expiresAt->format('d F Y') }} your support term ends. The Brand pack is yours to keep —
          nothing on this page changes. Renew and paste the new key here to keep support going.
        </x-note>
      @endif

      <x-note id="branding.activated">
        <b>Activated.</b> Licensed to {{ $issuedTo }}.
        @if ($expiresAt)
          Support runs until <b>{{ $expiresAt->format('d F Y') }}</b>; the Brand pack has no end date.
        @else
          This key has no end date.
        @endif
        It is checked on this server, so it keeps working whether or not you can reach us.
      </x-note>
    @else
      <form method="POST" action="{{ route('admin.branding.activate') }}" style="display:flex;flex-direction:column;gap:12px">
        @csrf
        <div class="field">
          <label for="key">Already have a key?</label>
          <textarea id="key" name="key" rows="3" class="mono" placeholder="eyJwcm9kdWN0Ijo…"></textarea>
          <span class="help">Paste the key from your purchase email. It is verified here; nothing is sent anywhere.</span>
        </div>
        <div class="actions">
          <button class="btn ghost" type="submit">Activate</button>
          <button class="btn ghost" type="reset">Clear</button>
        </div>
      </form>
    @endif
  </div>
</div>
@endsection
