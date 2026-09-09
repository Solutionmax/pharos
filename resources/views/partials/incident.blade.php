{{-- One incident as the public page renders it; used for the pinned Ongoing block and the day history. --}}
        @php
          $tone = $incident->status === \App\Enums\IncidentStatus::Resolved
              ? 'ok'
              : (($incident->components->max('pivot.status') ?? 0) >= 4 ? 'b' : 'p');
        @endphp
        <article class="inc {{ $tone }}" data-live-key="incident-{{ $incident->id }}" data-live-value="{{ $incident->status->value }}:{{ $incident->updates->max('id') }}" data-live-message="{{ $incident->name }}: {{ $incident->status->label() }}">
          <div class="inc-hd">
            <h4>{{ $incident->name }}</h4>
            <span class="pill {{ $tone }}">{{ $incident->status->label() }}</span>
            @if (($chrome ?? true) && auth()->check())
              <a class="inc-update" href="{{ route('admin.incidents.update-form', $incident) }}" aria-label="Update {{ $incident->name }}">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m16 3 5 5M4 16 16.5 3.5a3.5 3.5 0 0 1 5 5L9 21H4v-5Z"/></svg>
                <span>Update</span>
              </a>
            @endif
          </div>
          @if ($incident->components->isNotEmpty())
            <p class="aff">
              {{ $incident->isOpen() ? 'Affects' : 'Affected' }}
              <b>{{ $incident->components->pluck('name')->join(', ', ' and ') }}</b>
            </p>
          @endif
          <div class="tl">
            @foreach ($incident->updates as $update)
              <div class="tl-i">
                <span class="hd">
                  <strong>{{ $update->status->label() }}</strong>
                  <time>{{ $update->created_at->format('H:i') }}</time>
                  @if ($update->automatic)<span class="auto">automatic</span>@endif
                </span>
                <div class="md">{!! $update->messageHtml() !!}</div>
              </div>
            @endforeach
          </div>
        </article>
