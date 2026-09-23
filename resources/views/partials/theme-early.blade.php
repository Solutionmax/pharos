{{-- Runs in the head, before any stylesheet, so the first paint already has
     the right theme. partials/theme-script (end of body) keeps the toggle and
     icons; this only avoids a flash of the system theme when someone chose the
     other one with the quick toggle. --}}
<script data-theme-early>
(function () {
  var fallback = @json($theme ?? app(\App\Services\Branding::class)->theme());
  var choice = null;
  if (@json($rememberTheme ?? true)) {
    try { choice = localStorage.getItem('pharos-theme'); } catch (e) {}
  }
  if (choice !== 'light' && choice !== 'dark') {
    choice = fallback === 'light' || fallback === 'dark' ? fallback
      : (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
  }
  document.documentElement.setAttribute('data-theme', choice);
})();
</script>
