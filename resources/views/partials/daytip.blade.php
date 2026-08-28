{{-- One floating tooltip for every uptime sliver, on the public page and in the
     admin. A sliver carries its text in data-tip ("28 Aug · 99.98%"); the
     script moves this single element over whichever one is hovered. Native
     title= took a second to appear and looked like the browser's, not ours. --}}
<style>
/* Ink on the page becomes the ground here, so the tip is dark on light and light on dark. */
.daytip{position:fixed;left:0;top:0;z-index:60;pointer-events:none;
  background:var(--ink);color:var(--card);font-family:var(--sans);font-size:12px;line-height:1.3;
  padding:6px 9px;border-radius:8px;box-shadow:var(--shadow-md);white-space:nowrap;
  opacity:0;visibility:hidden;transform:translateY(3px);
  transition:opacity .08s var(--ease),transform .08s var(--ease),visibility .08s}
.daytip.on{opacity:1;visibility:visible;transform:none}
/* The caret follows the sliver, not the box: near the edge the box is clamped but the caret is not. */
.daytip::after{content:"";position:absolute;top:100%;left:var(--caret,50%);margin-left:-5px;
  border:5px solid transparent;border-top-color:var(--ink)}
.daytip .d{font-family:var(--mono);font-size:11.5px;color:var(--card);opacity:.78}
.daytip .v{font-weight:600;margin-left:6px}
.daytip .v.mute{font-weight:400;opacity:.62}
@media (prefers-reduced-motion:reduce){.daytip{transition:none}}
</style>
<div class="daytip" role="tooltip" id="daytip" aria-hidden="true"></div>
<script>
(function () {
  var tip = document.getElementById('daytip');
  if (!tip || window.pharosDaytipReady) return;
  window.pharosDaytipReady = true;

  var GAP = 8, EDGE = 8, TOUCH_MS = 1500, hideTimer = null, lastTouch = 0;

  // "28 Aug · 99.98%" → date in mono, the value beside it; "no data" fades back.
  function fill(text) {
    var parts = text.split(' · ');
    tip.textContent = '';
    if (parts[0] !== '') {
      var d = document.createElement('span'); d.className = 'd'; d.textContent = parts[0]; tip.appendChild(d);
    }
    if (parts.length > 1) {
      var v = document.createElement('span'); v.className = 'v' + (parts[1] === 'no data' ? ' mute' : ''); v.textContent = parts[1];
      if (parts[0] === '') v.style.marginLeft = '0';
      tip.appendChild(v);
    }
  }

  function show(el) {
    clearTimeout(hideTimer);
    fill(el.getAttribute('data-tip') || '');
    tip.classList.add('on');
    var r = el.getBoundingClientRect(), w = tip.offsetWidth, h = tip.offsetHeight;
    var centre = r.left + r.width / 2;
    // Clamp inside the viewport; the caret keeps pointing at the sliver.
    var left = Math.max(EDGE, Math.min(centre - w / 2, window.innerWidth - EDGE - w));
    tip.style.left = left + 'px';
    tip.style.top = (r.top - h - GAP) + 'px';
    tip.style.setProperty('--caret', (centre - left) + 'px');
  }
  function hide() { tip.classList.remove('on'); }

  document.addEventListener('mouseover', function (e) {
    var el = e.target.closest('[data-tip]');
    if (!el || Date.now() - lastTouch < 800) return;   // a tap already handled this one
    show(el);
  });
  document.addEventListener('mouseout', function (e) {
    var el = e.target.closest('[data-tip]');
    if (el && !(e.relatedTarget && el.contains(e.relatedTarget))) hide();
  });
  document.addEventListener('focusin', function (e) {
    var el = e.target.closest('[data-tip]');
    if (el) show(el);
  });
  document.addEventListener('focusout', function (e) {
    if (e.target.closest('[data-tip]')) hide();
  });
  // A finger has no hover: a tap shows the tip for a moment and lets go by itself.
  document.addEventListener('touchstart', function (e) {
    var el = e.target.closest('[data-tip]');
    if (!el) return;
    lastTouch = Date.now();
    show(el);
    hideTimer = setTimeout(hide, TOUCH_MS);
  }, {passive: true});
  // Fixed to the viewport, so a scroll would leave it hanging in the wrong place.
  window.addEventListener('scroll', hide, {passive: true});
  window.addEventListener('resize', hide);
})();
</script>
