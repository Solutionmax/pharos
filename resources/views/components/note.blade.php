<div {{ $attributes->class(['callout', 'warn' => $warn]) }} data-note="{{ $id }}">
  @if ($dismissable)
    {{-- A real form, so the button works without JavaScript; the script in the
         layout intercepts it. It is pushed to the layout's footer rather than
         printed here because a note can sit inside another form (Status page,
         a component's heartbeat note, ...) and a <form> inside a <form> is
         invalid HTML — the browser silently closes the outer one. The button
         still reaches it from anywhere via the HTML5 form="" attribute. --}}
    @push('deferred-forms')
      <form id="note-dismiss-{{ $id }}" method="POST" action="{{ route('admin.notes.dismiss', $id) }}" hidden>@csrf</form>
    @endpush
    <button type="submit" form="note-dismiss-{{ $id }}" class="callout-x" aria-label="Got it, hide this note" title="Got it">&times;</button>
  @endif
  {{ $slot }}
</div>
