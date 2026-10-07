@extends('layouts.admin')
@section('title', __('Scheduled maintenance'))
@section('content')
@include('partials.pagehead', [
  'title' => __('Scheduled maintenance'),
  'sub' => __('Planned work: announced ahead, shown on the status page, components set and restored for you'),
  'action' => $canEditPage ? ['url' => \App\Services\PageUrls::route('admin.maintenance.create'), 'label' => __('Schedule maintenance')] : null,
])

@include('partials.page-context', [
  'contextTitle' => __('Maintenance for'),
  'contextHelp' => __('Windows scheduled here appear only on this page and are announced only to its subscribers and destinations.'),
])

@error('maintenance')<div class="errors">{{ $message }}</div>@enderror

<section class="op-section" aria-labelledby="maint-open">
  <div class="op-section-hd"><h2 id="maint-open">{{ __('Coming up and under way') }}</h2><span class="hint">{{ $open->count() }} {{ \App\Services\Localization::plural('window', $open->count()) }}</span></div>
  @forelse ($open as $maintenance)
    @php $state = $maintenance->state(); @endphp
    <article class="op-inc op-maint {{ $state === 'in_progress' ? 'is-live' : '' }}">
      <header class="op-inc-hd">
        <div class="op-inc-title">
          <div class="op-pills">
            <span class="op-pill st-m">{{ $maintenance->stateLabel() }}</span>
            <span class="op-pill">{{ $state === 'in_progress' ? __('Ends ').$maintenance->ends_at->diffForHumans() : __('Starts ').$maintenance->starts_at->diffForHumans() }}</span>
          </div>
          <h3>{{ $maintenance->title }}</h3>
          <p class="op-when">
            <time datetime="{{ $maintenance->starts_at->toIso8601String() }}">{{ $maintenance->starts_at->translatedFormat('D j M, H:i') }}</time>
            <span aria-hidden="true">→</span>
            <time datetime="{{ $maintenance->ends_at->toIso8601String() }}">{{ $maintenance->ends_at->isSameDay($maintenance->starts_at) ? $maintenance->ends_at->format('H:i') : $maintenance->ends_at->translatedFormat('D j M, H:i') }}</time>
            <span class="op-dim">{{ \App\Services\Clock::offsetLabel() }}</span>
          </p>
        </div>
        @if ($canEditPage)
          <div class="op-acts">
            <a class="btn ghost op-sm" href="{{ \App\Services\PageUrls::route('admin.maintenance.edit', $maintenance) }}">{{ __('Edit') }}</a>
            <form method="POST" action="{{ \App\Services\PageUrls::route('admin.maintenance.cancel', $maintenance) }}"
                  data-confirm-title="Cancel {{ $maintenance->title }}?"
                  data-confirm="{{ $state === 'in_progress' ? __('The window stops now and the affected components go back to how they were.') : __('Nothing will be started or announced for this window.') }} Destinations that were told about it hear that it is cancelled."
                  data-confirm-action="{{ __('Cancel maintenance') }}">
              @csrf
              <button class="btn ghost op-sm" type="submit">{{ __('Cancel') }}</button>
            </form>
          </div>
        @endif
      </header>
      @if ($maintenance->message)<div class="op-inc-msg md">{!! \Illuminate\Support\Str::markdown($maintenance->message, \App\Services\MailTemplates::MARKDOWN) !!}</div>@endif
      <footer class="op-inc-ft">
        <span class="op-chips">
          @forelse ($maintenance->components as $component)
            <span class="op-chip">{{ $component->name }}</span>
          @empty
            <span class="op-dim">{{ __('No components are changed') }}</span>
          @endforelse
        </span>
        <span class="op-dim">
          @if ($maintenance->announced_at)
            {{ __('Announced') }} {{ $maintenance->announced_at->translatedFormat('j M H:i') }}
          @elseif ($maintenance->announce_minutes > 0)
            {{ __('Announces') }} {{ \Illuminate\Support\Str::lower(\App\Models\Maintenance::LEAD_TIMES[$maintenance->announce_minutes] ?? '') }}
          @else
            {{ __('Not announced') }}
          @endif
        </span>
      </footer>
    </article>
  @empty
    <div class="op-empty">
      @include('partials.icon', ['name' => 'empty', 'size' => 28])
      <b>{{ __('Nothing planned.') }}</b>
      <span>{{ __('Schedule a window and Pharos announces it, marks the components as under maintenance while it runs, and puts them back afterwards.') }}</span>
    </div>
  @endforelse
</section>

<section class="op-card" aria-labelledby="maint-past">
  <header><h3 id="maint-past">{{ __('History') }}</h3><span class="hint">{{ __('Completed and cancelled') }}</span></header>
  @if ($past->isEmpty())
    <div class="bd"><p class="op-dim">{{ __('No past maintenance yet.') }}</p></div>
  @else
    <div class="scroll"><table class="op-table">
      <thead><tr><th>{{ __('Maintenance') }}</th><th>{{ __('When') }}</th><th class="hide-sm">{{ __('Components') }}</th><th>{{ __('Outcome') }}</th></tr></thead>
      <tbody>
      @foreach ($past as $maintenance)
        <tr>
          <td><b>{{ $maintenance->title }}</b></td>
          <td class="num">{{ $maintenance->starts_at->translatedFormat('j M Y H:i') }}</td>
          <td class="hide-sm">{{ $maintenance->components->pluck('name')->join(', ') ?: __('None') }}</td>
          <td><span class="ix-state {{ $maintenance->cancelled_at ? 'off' : 'ok' }}">{{ $maintenance->stateLabel() }}</span></td>
        </tr>
      @endforeach
      </tbody>
    </table></div>
    @if ($past->hasPages())<div class="bd">{{ $past->links('vendor.pagination.pharos', ['previousLabel' => __('Newer'), 'nextLabel' => __('Older')]) }}</div>@endif
  @endif
</section>
@endsection
