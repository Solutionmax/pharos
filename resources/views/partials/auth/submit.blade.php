{{-- The dark sign in button; while a request runs, a heartbeat crosses it (pharos-auth.js). --}}
<button class="pa-btn" type="submit" data-pa-submit>
  <span class="pa-btn-lbl">{{ $label }}</span>
  <svg class="pa-btn-beat" viewBox="0 0 400 50" preserveAspectRatio="none" aria-hidden="true" focusable="false"><path d="M0 25h130l10-14 12 28 10-22 8 8h40l8-18 12 30 9-20 6 8h155"/></svg>
</button>
