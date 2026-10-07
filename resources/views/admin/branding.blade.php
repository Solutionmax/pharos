@extends('layouts.admin')
@section('title', __('Branding'))
@push('head-assets')
<link rel="stylesheet" href="{{ asset('assets/pharos-branding.css') }}?v={{ @filemtime(public_path('assets/pharos-branding.css')) }}">
@endpush
@section('content')
@php
  $page = app(\App\Services\PageContext::class)->page();
  $isAdmin = auth()->user()->isAdmin();
  $buyUrl = config('pharos.buy_url');
  $licensed = $plan->brandPack;

  // The limits printed next to each drop zone come from the validator's own table.
  $typeNames = ['png' => 'PNG', 'jpg' => 'JPG', 'jpeg' => 'JPG', 'webp' => 'WebP', 'ico' => 'ICO'];
  $typeMimes = ['png' => ['image/png'], 'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'webp' => ['image/webp'], 'ico' => ['image/x-icon', 'image/vnd.microsoft.icon', '.ico']];
  $hint = function (array $spec) use ($typeNames): string {
      $names = array_values(array_unique(array_map(fn ($ext) => $typeNames[$ext] ?? strtoupper($ext), $spec['mimes'])));
      $last = array_pop($names);
      $types = $names === [] ? $last : implode(', ', $names).' or '.$last;

      return $types.'. Up to '.$spec['max_kb'].' KB, at most '.$spec['max_width'].' × '.$spec['max_height'].' pixels.';
  };
  $accept = fn (array $spec): string => implode(',', array_values(array_unique(array_merge(...array_map(fn ($ext) => $typeMimes[$ext] ?? [], $spec['mimes'])))));

  $multiUsed = $plan->pageLimit
      ? $plan->activePages.' of '.$plan->pageLimit.' '.\App\Services\Localization::plural('page', $plan->pageLimit).' in use'
      : $plan->activePages.' '.\App\Services\Localization::plural('page', $plan->activePages).' in use, no limit';
  $termEnds = $plan->expiresAt?->translatedFormat('j F Y');
  $keepsBrand = $plan->signed(\App\Services\License::FEATURE_BRAND_PACK);
@endphp

@include('partials.pagehead', [
  'crumbs' => ['Appearance', 'Branding'],
  'title' => __('Branding'),
  'sub' => __('How this status page introduces itself: in the browser, on the page and in email'),
])

@include('partials.page-context', [
  'contextTitle' => __('Branding for'),
  'contextHelp' => __('Name, colour and images apply to this page only. Nothing is inherited from the default page: a new page starts with the Pharos defaults.'),
])

<script type="application/json" id="bx-config">{!! json_encode([
  'licensed' => $licensed,
  'defaults' => $defaults,
  'current' => ['logo' => $brand['logo'], 'logo_dark' => $brand['logo_dark'], 'favicon' => $brand['favicon_custom'] ? $brand['favicon'] : null],
  'uploads' => $uploads,
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) !!}</script>

<div class="bx">
  {{-- ============ live preview ============ --}}
  <aside class="bx-aside" aria-labelledby="bx-pv-title">
    <div class="bx-pv">
      <div class="bx-pv-hd">
        <span class="bx-live"><span class="dot" aria-hidden="true"></span> {{ __('Live preview') }}</span>
        <h2 id="bx-pv-title" class="sr-only">{{ __('Live preview of') }} {{ $page->name }}</h2>
        <span class="bx-pv-note" data-pv-dirty>{{ __('Showing what is saved') }}</span>
      </div>

      <figure class="bx-shot">
        <figcaption>{{ __('Browser tab') }}</figcaption>
        <div class="bx-browser" aria-hidden="true">
          <div class="bx-tabs">
            <span class="bx-tab"><img data-pv="favicon" src="{{ $brand['favicon'] }}" alt="" width="16" height="16"><span data-pv="title">{{ $brand['name'] }} {{ __('Status') }}</span><i>×</i></span>
            <span class="bx-tab ghost"></span>
          </div>
          <div class="bx-url"><span class="bx-padlock"></span><span class="mono">{{ preg_replace('~^https?://~', '', $page->publicUrl()) }}</span></div>
        </div>
      </figure>

      @foreach (['light' => 'Light theme', 'dark' => 'Dark theme'] as $tone => $toneLabel)
        <figure class="bx-shot">
          <figcaption>{{ __('Status page,') }} {{ strtolower($toneLabel) }}</figcaption>
          <div class="bx-site {{ $tone }}" aria-hidden="true">
            <div class="bx-site-top">
              <span class="bx-site-logo">
                <img data-pv="logo-{{ $tone }}" src="" alt="">
                <span data-pv="logo-name" hidden>{{ $brand['name'] }}</span>
              </span>
              <span class="bx-site-sub">{{ __('Get notified') }}</span>
            </div>
            <div class="bx-site-banner"><i></i> {{ __('All systems operational') }}</div>
            <div class="bx-site-foot"><span>{{ __('API') }}</span><span data-pv="credit">{{ __('Powered by Pharos') }}</span></div>
          </div>
        </figure>
      @endforeach

      <figure class="bx-shot">
        <figcaption>{{ __('Subscriber email') }}</figcaption>
        <div class="bx-mail" aria-hidden="true">
          <div class="bx-mail-meta"><b data-pv="name">{{ $brand['name'] }}</b><span>[<span data-pv="name">{{ $brand['name'] }}</span>{{ __('] API latency: Investigating') }}</span></div>
          <div class="bx-mail-card">
            <div class="bx-mail-head">
              <img data-pv="email-logo" src="" alt="" hidden>
              <span data-pv="email-name" hidden>{{ $brand['name'] }}</span>
            </div>
            <div class="bx-mail-lines"><i></i><i></i><i class="short"></i></div>
          </div>
        </div>
      </figure>

      <p class="bx-pv-foot">{{ __('Picked files are shown from your own computer. Nothing is uploaded until you press') }} <b>{{ __('Save branding') }}</b>.</p>
    </div>
  </aside>

  <div class="bx-main">
    {{-- ============ plan ============ --}}
    <section class="panel bx-plan" id="plan" aria-labelledby="bx-plan-title">
      <div class="panel-hd">
        <h3 id="bx-plan-title">{{ __('What this installation has') }}</h3>
        <span class="bx-planname">{{ $plan->name() }}</span>
      </div>
      <div class="panel-bd">
        <ul class="bx-tiers">
          <li class="on">
            <span class="bx-tick" aria-hidden="true"></span>
            <div class="t"><strong>{{ __('Free') }}</strong><span>{{ __('Name and accent colour on every page. Pages show Powered by Pharos in the footer.') }}</span></div>
            <span class="bx-state ok">{{ __('Included') }}</span>
          </li>
          <li class="{{ $plan->brandPack ? 'on' : 'off' }}">
            <span class="bx-tick" aria-hidden="true"></span>
            <div class="t">
              <strong>{{ __('Brand pack') }}</strong>
              <span>{{ __('Your logo for light and dark, favicon, email header and wording, no footer credit. Applies to every page.') }}</span>
            </div>
            @if ($plan->brandPack && $plan->expired)
              <span class="bx-state ok">{{ __('Active, kept') }}</span>
            @elseif ($plan->brandPack)
              <span class="bx-state ok">{{ __('Active') }}</span>
            @else
              <span class="bx-state off">{{ __('Not included') }}</span>
            @endif
          </li>
          <li class="{{ $plan->multiPage ? 'on' : ($plan->multiPageEnded() ? 'ended' : 'off') }}">
            <span class="bx-tick" aria-hidden="true"></span>
            <div class="t">
              <strong>{{ __('Multi page') }}</strong>
              <span>
                @if ($plan->multiPage)
                  {{ __('More than one status page.') }} {{ $multiUsed }}.
                @elseif ($plan->multiPageEnded())
                  {{ __('Ended with the term. Existing pages keep running (') }}{{ $plan->activePages }} {{ __('in use); new pages cannot be created and archived pages cannot be reactivated.') }}
                @else
                  @if ($plan->activePages > 1)
                    {{ __('Without it this installation has one status page.') }} {{ $plan->activePages }} {{ __('are active and keep running, but no page can be added or reactivated.') }}
                  @else
                    {{ __('Without it this installation has one status page. 1 of 1 in use.') }}
                  @endif
                @endif
              </span>
              @if ($plan->multiPage && $plan->pageLimit)
                <span class="bx-meter" role="img" aria-label="{{ $multiUsed }}"><i style="width:{{ min(100, round($plan->activePages / max(1, $plan->pageLimit) * 100)) }}%"></i></span>
              @endif
            </div>
            @if ($plan->multiPage)
              <span class="bx-state ok">{{ $plan->pageLimit ? $plan->activePages.' / '.$plan->pageLimit : __('Unlimited') }}</span>
            @elseif ($plan->multiPageEnded())
              <span class="bx-state warn">{{ __('Ended') }}</span>
            @else
              <span class="bx-state off">{{ __('Not included') }}</span>
            @endif
          </li>
        </ul>

        <div class="bx-term {{ $plan->expired ? 'warn' : '' }}">
          @if (! $plan->hasKey)
            <p><b>{{ __('No licence key yet.') }}</b> {{ __('Everything on this screen marked Free works without one.') }}</p>
          @else
            <p>
              <b>{{ __('Licensed') }}{{ $plan->issuedTo ? __(' to ').$plan->issuedTo : '' }}.</b>
              @if ($plan->boundTo) {{ __('Tied to') }} {{ $plan->boundTo }}. @endif
              @if (! $plan->expiresAt)
                {{ __('This key has no end date.') }}
              @elseif ($plan->expired)
                {{ __('The term ended on') }} {{ $termEnds }}.
              @else
                {{ __('The term runs until') }} {{ $termEnds }}.
              @endif
            </p>
            @if ($plan->expiresAt)
              <p>
                {{ $plan->expired ? __('Since then') : __('After that') }}:
                @if ($keepsBrand) {{ __('the Brand pack stays,') }} @endif
                @if ($plan->signed(\App\Services\License::FEATURE_MULTI_PAGES))
                  {{ __('extra pages') }} {{ $plan->expired ? 'keep' : __('will keep') }} {{ __('running but new pages cannot be created and archived pages cannot be reactivated,') }}
                @endif
                {{ __('and support') }} {{ $plan->expired ? __('has ended') : 'ends' }}.
              </p>
            @endif
          @endif
        </div>

        <div class="bx-plans" role="list" aria-label="{{ __('Plans') }}">
          @foreach (\App\Support\LicencePlan::PLANS as $key => $option)
            @php($isCurrent = $plan->current() === $key)
            <div class="bx-opt {{ $isCurrent ? 'current' : '' }}" role="listitem" data-plan="{{ $key }}" @if ($isCurrent) aria-current="true" @endif>
              <div class="bx-opt-hd">
                <strong>{{ $option['name'] }}</strong>
                @if ($isCurrent)<span class="bx-current">{{ __('Current') }}</span>@endif
              </div>
              <span class="bx-opt-term">{{ $option['term'] }}</span>
              <ul>
                @foreach ($option['includes'] as $line)<li>{{ $line }}</li>@endforeach
              </ul>
              @if ($plan->isUpgrade($key) && ($url = \App\Support\LicencePlan::buyUrl($key)))
                <a class="btn {{ $key === 'brand_pack' ? '' : 'ghost' }} bx-opt-buy" href="{{ $url }}" target="_blank" rel="noopener">{{ match ($key) { 'brand_pack' => __('Buy the brand pack'), 'commercial' => __('Request a quote'), default => __('Get ').$option['name'] } }}</a>
              @endif
            </div>
          @endforeach
        </div>

        <div class="bx-plan-act">
          <a class="linkbtn" href="{{ $buyUrl }}" target="_blank" rel="noopener">{{ __('Compare plans') }}</a>
          @if ($isAdmin)
            <a class="btn ghost" href="#licence">{{ $plan->hasKey ? __('Change the licence key') : __('Enter a licence key') }}</a>
          @else
            <span class="bx-fine">{{ __('Only an installation administrator can add or change the licence key.') }}</span>
          @endif
        </div>
      </div>
    </section>

    {{-- ============ the form ============ --}}
    <form method="POST" action="{{ \App\Services\PageUrls::route('admin.branding.update') }}" enctype="multipart/form-data" id="bx-form" novalidate>
      @csrf @method('PUT')

      <section class="panel bx-sec" aria-labelledby="bx-name-title">
        <div class="panel-hd"><h3 id="bx-name-title">{{ __('Name and colour') }}</h3><span class="bx-free">{{ __('Free') }}</span></div>
        <div class="panel-bd">
          <div class="fields">
            <div class="field">
              <label for="name">{{ __('Name on the page') }}</label>
              <input id="name" name="name" type="text" value="{{ old('name', $brand['name']) }}" required maxlength="60" aria-describedby="name-help" autocomplete="organization">
              <span class="help" id="name-help">{{ __('Shown in the browser tab, the page header and every email. Up to 60 characters.') }}</span>
            </div>
            <div class="field">
              <label for="accent">{{ __('Accent colour') }}</label>
              <span class="bx-colour">
                <input id="accent" name="accent" type="color" value="{{ old('accent', $brand['accent']) }}" aria-describedby="accent-help">
                <output for="accent" class="mono" data-pv="accent-hex">{{ old('accent', $brand['accent']) }}</output>
              </span>
              <span class="help" id="accent-help">{{ __('Used for the mark, links and buttons, on both themes.') }}</span>
            </div>
          </div>
        </div>
      </section>

      {{-- Logo, light and dark --}}
      <section class="panel bx-sec {{ $licensed ? '' : 'locked' }}" aria-labelledby="bx-logo-title">
        <div class="panel-hd"><h3 id="bx-logo-title">{{ __('Logo') }}</h3><span class="pro">{{ __('Brand pack') }}</span></div>
        <div class="panel-bd">
          @if ($licensed)
            <div class="bx-drops">
              @foreach (['logo' => ['Logo for the light theme', 'light', 'Replaces the lighthouse and the name.'], 'logo_dark' => ['Logo for the dark theme', 'dark', 'Optional. Without it the light logo is used on both themes.']] as $field => [$label, $tone, $say])
                @include('admin.partials.branding-drop', [
                  'field' => $field, 'label' => $label, 'tone' => $tone, 'say' => $say,
                  'current' => $brand[$field], 'remove' => 'remove_'.$field,
                  'hint' => $hint($uploads[$field]), 'accept' => $accept($uploads[$field]),
                ])
              @endforeach
            </div>
          @else
            @include('admin.partials.branding-lock', [
              'what' => 'Show your own logo instead of the Pharos mark, with a second version for dark mode.',
              'saved' => $saved['logo'] || $saved['logo_dark'] ? 'A logo is saved for this page. It shows again as soon as a key with the Brand pack is active.' : null,
            ])
          @endif
        </div>
      </section>

      {{-- Favicon --}}
      <section class="panel bx-sec {{ $licensed ? '' : 'locked' }}" aria-labelledby="bx-fav-title">
        <div class="panel-hd"><h3 id="bx-fav-title">{{ __('Favicon') }}</h3><span class="pro">{{ __('Brand pack') }}</span></div>
        <div class="panel-bd">
          @if ($licensed)
            @include('admin.partials.branding-drop', [
              'field' => 'favicon', 'label' => __('Icon in the browser tab'), 'tone' => 'icon', 'say' => 'Square works best. Without one the Pharos lighthouse is used.',
              'current' => $brand['favicon_custom'] ? $brand['favicon'] : null, 'remove' => 'remove_favicon', 'fallback' => $defaults['favicon'],
              'hint' => $hint($uploads['favicon']), 'accept' => $accept($uploads['favicon']),
            ])
          @else
            @include('admin.partials.branding-lock', [
              'what' => 'Put your own icon in the browser tab instead of the Pharos lighthouse.',
              'saved' => $saved['favicon'] ? 'A favicon is saved for this page. It shows again as soon as a key with the Brand pack is active.' : null,
            ])
          @endif
        </div>
      </section>

      {{-- Email header --}}
      <section class="panel bx-sec {{ $licensed ? '' : 'locked' }}" aria-labelledby="bx-mail-title">
        <div class="panel-hd"><h3 id="bx-mail-title">{{ __('Email header') }}</h3><span class="pro">{{ __('Brand pack') }}</span></div>
        <div class="panel-bd">
          @if ($licensed)
            <div class="bx-mailnote">
              <p>{{ __('Subscriber emails from this page open with the') }} <b>{{ __('logo for the light theme') }}</b>{{ __(', on a white card. Without a logo the name is set in your accent colour. There is no separate email logo to upload.') }}</p>
              <p class="bx-uses">{{ __('Now used:') }} <b data-pv="email-source">{{ $brand['logo'] ? __('your logo for the light theme') : __('the name in your accent colour') }}</b></p>
              <p><a href="{{ \App\Services\PageUrls::route('admin.mail-templates') }}">{{ __('Change the wording in Email, Templates') }}</a></p>
            </div>
          @else
            @include('admin.partials.branding-lock', [
              'what' => 'Open subscriber emails with your logo instead of the Pharos one, and change their wording.',
              'saved' => null,
            ])
          @endif
        </div>
      </section>

      {{-- Footer credit --}}
      <section class="panel bx-sec {{ $licensed ? '' : 'locked' }}" aria-labelledby="bx-credit-title">
        <div class="panel-hd"><h3 id="bx-credit-title">{{ __('Footer credit') }}</h3><span class="pro">{{ __('Brand pack') }}</span></div>
        <div class="panel-bd">
          @if ($licensed)
            <label class="switchrow" for="credit_hidden">
              <span class="t">
                <strong>{{ __('Hide "Powered by Pharos"') }}</strong>
                <span class="s">{{ __('The small credit in the footer of the public page and the sign in screen.') }}</span>
              </span>
              <span class="check"><input type="checkbox" id="credit_hidden" name="credit_hidden" value="1" @checked($brand['credit_hidden'])></span>
            </label>
          @else
            @include('admin.partials.branding-lock', [
              'what' => 'Every page shows Powered by Pharos in its footer. The Brand pack lets you remove it.',
              'saved' => null,
            ])
          @endif
        </div>
      </section>

      <div class="bx-savebar">
        <span class="bx-dirty" data-pv-state aria-live="polite">{{ __('No changes yet') }}</span>
        <button class="btn ghost" type="reset">{{ __('Undo my changes') }}</button>
        <button class="btn" type="submit">{{ __('Save branding') }}</button>
      </div>
    </form>

    {{-- ============ licence key ============ --}}
    @if ($isAdmin)
      <section class="panel bx-licence" id="licence" aria-labelledby="bx-licence-title">
        <div class="panel-hd">
          <h3 id="bx-licence-title">{{ __('Licence key') }}</h3>
          <span class="hint">{{ $plan->hasKey ? __('Active for the whole installation') : __('Not activated') }}</span>
        </div>
        <div class="panel-bd">
          @if ($plan->hasKey)
            @if ($expiringSoon)
              <x-note id="branding.expiring" warn>
                <b>{{ $daysLeft === 0 ? __('Runs out today.') : __('Runs out in ').$daysLeft.' '.\App\Services\Localization::plural('day', $daysLeft).'.' }}</b>
                {{ __('On') }} {{ $expiresAt->translatedFormat('j F Y') }} {{ __('the term ends.') }}
                @if ($plan->signed(\App\Services\License::FEATURE_BRAND_PACK)) {{ __('The Brand pack is yours to keep.') }} @endif
                @if ($plan->signed(\App\Services\License::FEATURE_MULTI_PAGES)) {{ __('After that no new pages can be created or reactivated.') }} @endif
                {{ __('Renew and paste the new key below.') }}
              </x-note>
            @endif

            <x-note id="branding.activated">
              <b>{{ __('Activated.') }}</b> {{ __('Licensed to') }} {{ $issuedTo ?? __('this installation') }}.
              @if ($expiresAt)
                {{ __('The term') }} {{ $plan->expired ? __('ended on') : __('runs until') }} <b>{{ $expiresAt->translatedFormat('j F Y') }}</b>{{ $keepsBrand ? __('; the Brand pack has no end date') : '' }}.
              @else
                {{ __('This key has no end date.') }}
              @endif
              {{ __('It is checked on this server, so it keeps working whether or not you can reach us.') }}
              @if ($boundTo)
                {{ __('Tied to') }} <b>{{ $boundTo }}</b>.
              @endif
            </x-note>
          @endif

          <form method="POST" action="{{ route('admin.branding.activate') }}" class="bx-keyform">
            @csrf
            <div class="field">
              <label for="key">{{ $plan->hasKey ? __('Paste a new key') : __('Already have a key?') }}</label>
              <textarea id="key" name="key" rows="3" class="mono" placeholder="{{ __('eyJwcm9kdWN0Ijo…') }}" aria-describedby="key-help" @error('key') aria-invalid="true" @enderror>{{ old('key') }}</textarea>
              <span class="help" id="key-help">{{ $plan->hasKey ? __('A renewal or upgrade replaces the current key. ') : '' }}{{ __('Paste the key from your purchase email. It is verified here; nothing is sent anywhere.') }}</span>
            </div>
            <div class="actions">
              <button class="btn {{ $plan->hasKey ? 'ghost' : '' }}" type="submit">{{ __('Activate') }}</button>
            </div>
          </form>

          @if ($plan->hasKey)
            <form method="POST" action="{{ route('admin.branding.deactivate') }}" class="bx-remove-key"
                  onsubmit="return confirm('Remove the key? Your logo, favicon and mail wording stay saved; they show again the moment you paste a key.')">
              @csrf
              <button class="linkbtn" type="submit">{{ __('Remove key') }}</button>
              <span class="help">{{ __('Uploads stay saved and come back when a key is pasted again.') }}</span>
            </form>
          @endif
        </div>
      </section>
    @endif
  </div>
</div>

<script defer src="{{ asset('assets/pharos-branding.js') }}?v={{ @filemtime(public_path('assets/pharos-branding.js')) }}"></script>
@endsection
