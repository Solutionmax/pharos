{{-- One incident as the public page renders it; used for the pinned Ongoing block and the day history. --}}
        @php
          $tone = $incident->status === \App\Enums\IncidentStatus::Resolved
              ? 'ok'
              : (($incident->components->max('pivot.status') ?? 0) >= 4 ? 'b' : 'p');
        @endphp
        <article class="inc {{ $tone }}">
          <div class="inc-hd">
            <h4>{{ $incident->name }}</h4>
            <span class="pill {{ $tone }}">{{ $incident->status->label() }}</span>
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
