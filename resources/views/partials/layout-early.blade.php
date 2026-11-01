{{-- Runs in the head, like theme-early: the quick switch remembers a layout in
     this browser, and the first paint must already show that one. The saved
     preference (Profile) is the fallback. --}}
<script data-layout-early>
(function () {
  var choice = null;
  try { choice = localStorage.getItem('pharos-layout'); } catch (e) {}
  if (choice !== 'sidebar' && choice !== 'topbar') choice = @json($layout);
  document.documentElement.setAttribute('data-layout', choice);
})();
</script>
