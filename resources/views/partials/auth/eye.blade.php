{{-- Show or hide a password. Hidden until pharos-auth.js wires it up, so it never shows dead. --}}
<button type="button" class="pa-eye" aria-controls="{{ $for }}" aria-label="{{ __('Show password') }}" aria-pressed="false" data-pa-eye hidden>
  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/><path class="pa-eye-off" d="m4 4 16 16"/></svg>
</button>
