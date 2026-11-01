{{-- Button only; the handler lives in layouts/admin once. Which icon shows
     follows html[data-layout] in pharos-calm.css, so no script runs per button. --}}
<button class="layout-toggle" type="button" data-layout-toggle aria-label="{{ __('Switch between sidebar and top bar') }}">
  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
       stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" data-icon="to-topbar">
    <rect x="3" y="4" width="18" height="16" rx="2.5"/><path d="M3 9h18M7 6.5h1.5M10.5 6.5h1.5"/>
  </svg>
  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
       stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" data-icon="to-sidebar">
    <rect x="3" y="4" width="18" height="16" rx="2.5"/><path d="M9 4v16M5.5 8h1.5M5.5 11h1.5"/>
  </svg>
</button>
