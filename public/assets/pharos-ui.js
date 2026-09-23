/* Pharos admin: shared behaviour for the sidebar, dialogs, drawers and search.
 *
 * Dialogs and drawers
 *   <button data-dialog="some-id">Open</button>
 *   <div id="some-id" class="pui-modal" data-modal role="dialog" aria-modal="true" aria-labelledby="..." hidden>
 *     <div class="pui-scrim" data-close></div>
 *     <div class="pui-sheet"> ... <button data-close>Close</button></div>   (or class="pui-drawer" for a side panel)
 *   </div>
 * Opening shows it, moves focus inside, traps Tab, closes on Escape or on any
 * [data-close], and gives focus back to whatever opened it. Scripts can use
 * window.pharosUi.open(el, opener) and window.pharosUi.close(el); the element
 * receives "pui:open" and "pui:close" events.
 */
(function () {
  'use strict';

  var FOCUSABLE = 'a[href],button:not([disabled]),input:not([disabled]):not([type=hidden]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])';
  var stack = [];

  function focusables(el) {
    // tabindex="-1" (like the search options) is reachable by script, never by Tab.
    return Array.prototype.filter.call(el.querySelectorAll(FOCUSABLE), function (node) {
      if (node.getAttribute('tabindex') === '-1') return false;
      return node.offsetParent !== null || node === document.activeElement;
    });
  }

  function open(el, opener) {
    if (!el || !el.hidden) return;
    el.hidden = false;
    el._opener = opener || document.activeElement;
    stack.push(el);
    document.documentElement.classList.add('pui-locked');
    if (el._opener && el._opener.setAttribute && el._opener.hasAttribute('aria-expanded')) el._opener.setAttribute('aria-expanded', 'true');
    var first = el.querySelector('[autofocus]') || focusables(el).filter(function (n) { return !n.matches('.pui-scrim'); })[0];
    if (first) first.focus();
    el.dispatchEvent(new CustomEvent('pui:open', { detail: { opener: el._opener } }));
  }

  function close(el) {
    if (!el || el.hidden) return;
    el.hidden = true;
    stack = stack.filter(function (x) { return x !== el; });
    if (!stack.length) document.documentElement.classList.remove('pui-locked');
    var opener = el._opener;
    if (opener && opener.setAttribute && opener.hasAttribute('aria-expanded')) opener.setAttribute('aria-expanded', 'false');
    el.dispatchEvent(new CustomEvent('pui:close'));
    if (opener && document.contains(opener) && opener.focus) opener.focus();
  }

  window.pharosUi = { open: open, close: close };

  // A form that came back with errors reopens in its own dialog or drawer.
  var reopen = document.querySelector('[data-modal][data-autoopen]');
  if (reopen) open(reopen, document.querySelector('[data-dialog="' + reopen.id + '"]'));

  // A link ending in #some-dialog-id opens that dialog on arrival (the search
  // palette's "Invite someone" lands on Users with #user-add). The hash is
  // dropped again so a reload or a second click behaves the same way.
  function openFromHash() {
    var id = window.location.hash.slice(1);
    if (!/^[A-Za-z][\w-]*$/.test(id)) return;
    var el = document.getElementById(id);
    if (!el || !el.hasAttribute('data-modal')) return;
    open(el, document.querySelector('[data-dialog="' + id + '"]'));
    if (window.history.replaceState) window.history.replaceState(null, '', window.location.pathname + window.location.search);
  }
  openFromHash();
  window.addEventListener('hashchange', openFromHash);

  document.addEventListener('click', function (event) {
    var opener = event.target.closest('[data-dialog]');
    if (opener) {
      event.preventDefault();
      open(document.getElementById(opener.getAttribute('data-dialog')), opener);
      return;
    }
    var closer = event.target.closest('[data-close]');
    if (closer && closer.closest('[data-modal]')) {
      event.preventDefault();
      close(closer.closest('[data-modal]'));
    }
  });

  document.addEventListener('keydown', function (event) {
    var top = stack[stack.length - 1];
    if (!top) return;
    if (event.key === 'Escape') {
      event.preventDefault();
      close(top);
      return;
    }
    if (event.key !== 'Tab') return;
    var items = focusables(top);
    if (!items.length) { event.preventDefault(); return; }
    var first = items[0], last = items[items.length - 1];
    if (event.shiftKey && (document.activeElement === first || !top.contains(document.activeElement))) {
      event.preventDefault(); last.focus();
    } else if (!event.shiftKey && (document.activeElement === last || !top.contains(document.activeElement))) {
      event.preventDefault(); first.focus();
    }
  });

  /* ---------- sidebar groups ----------
   * A closed group opens and goes to its first screen, so a click always lands
   * somewhere. An open group folds away. Only one group stays open. */
  document.addEventListener('click', function (event) {
    var button = event.target.closest('.nav-parent');
    if (!button) return;
    var group = button.closest('[data-navgroup]');
    var kids = group.querySelector('.navkids');
    var opening = kids.hidden;
    document.querySelectorAll('[data-navgroup]').forEach(function (other) {
      if (other === group) return;
      other.classList.remove('open');
      other.querySelector('.navkids').hidden = true;
      other.querySelector('.nav-parent').setAttribute('aria-expanded', 'false');
    });
    kids.hidden = !opening;
    group.classList.toggle('open', opening);
    button.setAttribute('aria-expanded', opening ? 'true' : 'false');
    if (opening && !group.querySelector('[aria-current="page"]') && button.dataset.first) {
      window.location.href = button.dataset.first;
    }
  });

  /* ---------- hover tips for charts: [data-tip] inside [data-tips] ---------- */
  var tip = null;
  function showTip(target, x, y) {
    if (!tip) {
      tip = document.createElement('div');
      tip.className = 'pui-tip';
      tip.setAttribute('role', 'tooltip');
      document.body.appendChild(tip);
    }
    var title = target.getAttribute('data-tip-title');
    tip.textContent = '';
    if (title) {
      var b = document.createElement('b');
      b.textContent = title;
      tip.appendChild(b);
    }
    tip.appendChild(document.createTextNode(target.getAttribute('data-tip')));
    tip.classList.add('on');
    var left = Math.min(x + 14, window.innerWidth - tip.offsetWidth - 8);
    var top = y - tip.offsetHeight - 12;
    tip.style.left = Math.max(8, left) + 'px';
    tip.style.top = (top < 8 ? y + 16 : top) + 'px';
  }
  function hideTip() { if (tip) tip.classList.remove('on'); }
  document.addEventListener('mousemove', function (event) {
    var target = event.target.closest && event.target.closest('[data-tips] [data-tip]');
    if (!target) { hideTip(); return; }
    showTip(target, event.clientX, event.clientY);
  });
  document.addEventListener('focusin', function (event) {
    var target = event.target.closest && event.target.closest('[data-tips] [data-tip]');
    if (!target) { hideTip(); return; }
    var box = target.getBoundingClientRect();
    showTip(target, box.left, box.top);
  });
  window.addEventListener('scroll', hideTip, { passive: true });

  /* ---------- global search: see pharos-search.js ---------- */
})();
