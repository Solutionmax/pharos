{{-- A span without incidents says so once, then shows the last one there was, so a
     quiet page still answers "when did it last go wrong, and for how long". --}}
@use('App\Services\StatusTimeline')
<div class="quiet">
  <p class="quiet-line">{{ __('No incidents in the past :n days.', ['n' => $quietDays]) }}</p>
  @if ($last)
    <a class="last-incident is-{{ StatusTimeline::tone($last) }}" href="{{ \App\Services\PageUrls::route('public.incident', $last) }}">
      <span class="label">{{ __('Last incident') }}</span>
      <b>{{ $last->name }}</b>
      <span class="meta"><time datetime="{{ $last->occurred_at->toIso8601String() }}">{{ $last->occurred_at->translatedFormat($last->occurred_at->isCurrentYear() ? 'j M' : 'j M Y') }}</time>
        · {{ __('Resolved after :duration', ['duration' => StatusTimeline::duration(StatusTimeline::incidentMinutes($last, $now))]) }}</span>
      @if ($last->components->isNotEmpty())
        <span class="aff">{{ __('Affected') }} {{ $last->components->pluck('name')->join(', ', __(' and ')) }}</span>
      @endif
    </a>
  @endif
  @if ($chrome && $lastPage)
    <nav class="pager" aria-label="{{ __('Incident history') }}">
      <a class="older" href="{{ \App\Services\PageUrls::route('status', ['page' => $lastPage]) }}">{{ __('All incidents →') }}</a>
    </nav>
  @endif
</div>
