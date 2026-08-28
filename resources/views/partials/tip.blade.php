{{-- A label's (i). The button carries the text for screen readers, so the
     bubble itself is decoration and stays out of the accessibility tree —
     otherwise it is announced twice. Shown on hover and on focus, which is
     also what makes it work on a touch screen. --}}
<button type="button" class="tip" aria-label="{{ $text }}">
  i<span class="tip-bubble" aria-hidden="true">{{ $text }}</span>
</button>
