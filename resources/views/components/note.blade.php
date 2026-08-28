<div {{ $attributes->class(['callout', 'warn' => $warn]) }} data-note="{{ $id }}">
  @if ($dismissable)
    {{-- A real form, so the button works without JavaScript; the script in the layout intercepts it. --}}
    <form method="POST" action="{{ route('admin.notes.dismiss', $id) }}" class="callout-hide">
      @csrf
      <button type="submit" class="callout-x" aria-label="Got it — hide this note" title="Got it">&times;</button>
    </form>
  @endif
  {{ $slot }}
</div>
