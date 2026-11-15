<li data-msg="{{ $n }}" @class(['done' => $done])>
  <strong>{{ __($update->status->label() ?? '') }}</strong>
  <time>{{ $long ? $update->created_at->translatedFormat('j M H:i') : $update->created_at->format('H:i') }}</time>
  <div class="md">{!! $update->messageHtml() !!}@if ($update->automatic) <span class="auto">{{ __('automatic') }}</span>@endif</div>
</li>
