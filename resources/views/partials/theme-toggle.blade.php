{{-- Button only. The script lives in the layout, once: included twice it binds
     two click handlers to the same button, which flips the theme and flips it
     straight back. --}}
<button class="theme-toggle" type="button" data-theme-toggle aria-label="Switch between light and dark">
  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
       stroke-linecap="round" aria-hidden="true" data-icon="sun">
    <circle cx="12" cy="12" r="4.2"/>
    <path d="M12 2.5v2M12 19.5v2M2.5 12h2M19.5 12h2M5.2 5.2l1.4 1.4M17.4 17.4l1.4 1.4M18.8 5.2l-1.4 1.4M6.6 17.4l-1.4 1.4"/>
  </svg>
  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
       stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" data-icon="moon" style="display:none">
    <path d="M20 14.5A8.5 8.5 0 1 1 9.5 4a6.7 6.7 0 0 0 10.5 10.5Z"/>
  </svg>
</button>
