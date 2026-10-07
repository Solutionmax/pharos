@if(app(\App\Services\PageContext::class)->page()->is_published)
<section class="op-card share-status">
  <header><h3>{{ __('Share your status') }}</h3><a class="btn ghost op-sm" style="margin-left:auto" href="{{ \App\Services\PageUrls::route('public.feed') }}">{{ __('RSS feed') }}</a></header>
  <div class="bd beta-form">
    <div class="field">
      <label for="status-widget-code">{{ __('Copy this widget into your website') }}</label>
      <textarea id="status-widget-code" readonly rows="2">{{ '<script src="'.\App\Services\PageUrls::route('public.embed').'" defer></script>' }}</textarea>
      <span class="help">{{ __('The widget refreshes every minute and links to your public status page.') }}</span>
    </div>
    <details class="beta-disclosure">
      <summary>{{ __('Status badges') }}<span class="beta-chevron" aria-hidden="true">⌄</span></summary>
      <div class="share-badges">
        @foreach(\App\Services\PublicComponents::query()->orderBy('position')->limit(500)->get() as $badgeComponent)
          @php $badgeUrl = \App\Services\PageUrls::route('public.badge', ['kind' => 'components', 'id' => $badgeComponent->id]); @endphp
          <div class="field"><label for="badge-{{ $badgeComponent->id }}">{{ $badgeComponent->name }}</label><input id="badge-{{ $badgeComponent->id }}" type="text" readonly value="{{ $badgeUrl }}"></div>
        @endforeach
      </div>
    </details>
  </div>
</section>
@endif
