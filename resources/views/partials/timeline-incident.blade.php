{{-- One incident in the log: header, course strip (one dot per message), the newest message, older ones folded. --}}
@use('App\Services\StatusTimeline')
@php
  $tone = StatusTimeline::tone($incident);
  $open = $incident->isOpen();
  $strip = StatusTimeline::strip($incident, $now);
  $updates = $incident->updates->sortBy('created_at')->values();
  $count = $updates->count();
  $lastIndex = $count - 1;
  $start = $incident->occurred_at;
  $longRun = $count > 0 && ! $updates->last()->created_at->isSameDay($start);
  $duration = StatusTimeline::duration(StatusTimeline::incidentMinutes($incident, $now));
  $isDone = fn ($update) => $update->status === \App\Enums\IncidentStatus::Resolved;
@endphp
<article id="incident-{{ $incident->id }}" class="entry is-{{ $tone }}{{ $open ? ' live' : '' }}" data-day="{{ $start->format('Y-m-d') }}"
         data-live-key="incident-{{ $incident->id }}" data-live-value="{{ $incident->status->value }}:{{ $incident->updates->max('id') }}" data-live-message="{{ $incident->name }}: {{ __($incident->status->label() ?? '') }}">
  <i class="node"></i>
  <div class="date">
    @if ($open)
      <b>{{ __('Now') }}</b><span class="label">{{ __('Live') }}</span>
    @else
      <b>{{ $start->format('d') }}</b><span class="label">{{ $start->translatedFormat('M') }}<br>{{ $start->translatedFormat('D') }}</span>
    @endif
  </div>
  <div class="card">
    <header>
      <h3><a href="{{ \App\Services\PageUrls::route('public.incident', $incident) }}">{{ $incident->name }}</a></h3>
      <span class="meta">
        @if ($open)
          <b>{{ __('Ongoing') }} · <span class="when">{{ $duration }}</span></b>
        @else
          {{ __(StatusTimeline::toneLabel($tone)) }} · {{ $duration }}
        @endif
      </span>
      @if (($chrome ?? true) && auth()->check())
        <a class="inc-update" href="{{ \App\Services\PageUrls::route('admin.incidents.update-form', $incident) }}" aria-label="Update {{ $incident->name }}">{{ __('Update') }}</a>
      @endif
    </header>
    @if ($incident->components->isNotEmpty())
      <p class="aff">{{ $open ? __('Affects') : __('Affected') }} {{ $incident->components->pluck('name')->join(', ', __(' and ')) }}</p>
    @endif

    @if ($count > 0)
      <div class="strip{{ $count === 1 && ! $open ? ' solo' : '' }}{{ $open ? ' when' : '' }}">
        <span>{{ $strip['from'] }}</span>
        <div class="bar">
          @foreach ($strip['points'] as $n => $p)
            @php $next = $n < $lastIndex ? $strip['points'][$n + 1] : ($open ? 1 : $p); @endphp
            @if ($next > $p)<i class="seg ph{{ min($n, 2) }}" style="left:{{ $p * 100 }}%;width:{{ ($next - $p) * 100 }}%"></i>@endif
          @endforeach
          @if ($open)<i class="open"></i>@endif
          @foreach ($updates as $n => $update)
            <button type="button" class="dot{{ $isDone($update) ? ' done' : '' }}{{ $n === $lastIndex ? ' cur' : '' }}{{ $n === $lastIndex && $open ? ' beat' : '' }}"
                    style="left:{{ $strip['points'][$n] * 100 }}%" data-msg="{{ $n }}"
                    aria-label="{{ __($update->status->label() ?? '') }}, {{ $update->created_at->format('H:i') }}. {{ __('Show this message.') }}"></button>
          @endforeach
          @php $newest = $updates->last(); @endphp
          <span class="at{{ $isDone($newest) ? ' done' : '' }}" style="left:{{ $strip['points'][$lastIndex] * 100 }}%">{{ __($newest->status->label() ?? '') }}</span>
        </div>
        @if ($count === 1 && ! $open)<span></span>@else<span>{{ $strip['to'] }}</span>@endif
      </div>

      <ol class="msgs{{ $longRun ? ' long' : '' }}">
        @include('partials.timeline-message', ['update' => $newest, 'n' => $lastIndex, 'done' => $isDone($newest), 'long' => $longRun])
      </ol>
      @if ($count > 1)
        <details class="more" id="more-incident-{{ $incident->id }}">
          <summary><svg class="ico" viewBox="0 0 24 24" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg>{{ $count === 2 ? __('1 earlier message') : __(':n earlier messages', ['n' => $count - 1]) }}</summary>
          <ol class="msgs{{ $longRun ? ' long' : '' }}">
            @for ($n = $lastIndex - 1; $n >= 0; $n--)
              @include('partials.timeline-message', ['update' => $updates[$n], 'n' => $n, 'done' => $isDone($updates[$n]), 'long' => $longRun])
            @endfor
          </ol>
        </details>
      @endif
    @endif
  </div>
</article>
