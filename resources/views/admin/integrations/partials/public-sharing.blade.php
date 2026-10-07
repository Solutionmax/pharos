@if(app(\App\Services\PageContext::class)->page()->is_published)
<section class="op-card" style="padding:24px;margin-top:24px">
<h2>{{ __('Share your status') }}</h2>
<p><a href="{{ \App\Services\PageUrls::route('public.feed') }}">{{ __('RSS feed') }}</a></p>
<label for="status-widget-code">{{ __('Copy this widget into your website') }}</label>
<textarea id="status-widget-code" readonly rows="2" style="width:100%">{{ '<script src="'.\App\Services\PageUrls::route('public.embed').'" defer></script>' }}</textarea>
<p>{{ __('The widget refreshes every minute and links to your public status page.') }}</p>
<h3>{{ __('Status badges') }}</h3>
@foreach(\App\Services\PublicComponents::query()->orderBy('position')->limit(500)->get() as $badgeComponent)
@php $badgeUrl = \App\Services\PageUrls::route('public.badge', ['kind' => 'components', 'id' => $badgeComponent->id]); @endphp
<label for="badge-{{ $badgeComponent->id }}">{{ $badgeComponent->name }}</label>
<input id="badge-{{ $badgeComponent->id }}" readonly value="{{ $badgeUrl }}" style="width:100%">
@endforeach
</section>
@endif
