<script>
(function () {
  if (window.pharosThemeReady) return;   // belt and braces: never bind twice
  window.pharosThemeReady = true;

  var root = document.documentElement;
  var DEFAULT = @json($theme ?? $branding->theme());

  // Whatever the operator picked is only the starting point; a visitor's own
  // choice wins and survives navigation. Storage can throw in private mode.
  function stored() {
    try { return localStorage.getItem('pharos-theme'); } catch (e) { return null; }
  }
  function resolved() {
    var choice = stored() || (DEFAULT === 'system' ? null : DEFAULT);
    if (choice === 'light' || choice === 'dark') return choice;
    return matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  }
  function paint(theme) {
    root.setAttribute('data-theme', theme);
    document.querySelectorAll('[data-icon="sun"]').forEach(function (el) {
      el.style.display = theme === 'dark' ? 'none' : '';
    });
    document.querySelectorAll('[data-icon="moon"]').forEach(function (el) {
      el.style.display = theme === 'dark' ? '' : 'none';
    });
  }

  paint(resolved());

  document.addEventListener('click', function (event) {
    var button = event.target.closest('[data-theme-toggle]');
    if (!button) return;
    var next = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
    try { localStorage.setItem('pharos-theme', next); } catch (e) {}
    paint(next);
  });
})();
</script>
