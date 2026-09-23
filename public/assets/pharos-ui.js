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
    return Array.prototype.filter.call(el.querySelectorAll(FOCUSABLE), function (node) {
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

  /* ---------- global search ---------- */
  var dialog = document.getElementById('pharos-search');
  if (!dialog) return;
  var input = dialog.querySelector('input');
  var list = dialog.querySelector('[role=listbox]');
  var status = dialog.querySelector('[data-search-status]');
  var endpoint = input.getAttribute('data-endpoint');
  var mac = /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent);
  document.querySelectorAll('[data-search-key]').forEach(function (k) { k.textContent = mac ? '⌘ K' : 'Ctrl K'; });
  var timer = null, controller = null, active = -1, lastTerm = null;

  function options() { return Array.prototype.slice.call(list.querySelectorAll('[role=option]')); }
  function select(index) {
    var all = options();
    if (!all.length) { active = -1; input.removeAttribute('aria-activedescendant'); return; }
    active = (index + all.length) % all.length;
    all.forEach(function (o, i) { o.setAttribute('aria-selected', i === active ? 'true' : 'false'); });
    input.setAttribute('aria-activedescendant', all[active].id);
    all[active].scrollIntoView({ block: 'nearest' });
  }
  function render(results, term) {
    list.textContent = '';
    active = -1;
    results.forEach(function (r, i) {
      var li = document.createElement('li');
      li.id = 'pharos-search-' + i;
      li.setAttribute('role', 'option');
      li.setAttribute('aria-selected', 'false');
      var a = document.createElement('a');
      a.href = r.url;
      a.tabIndex = -1;
      var kind = document.createElement('span');
      kind.className = 'search-kind';
      kind.textContent = r.type;
      var label = document.createElement('b');
      label.textContent = r.label;
      var sub = document.createElement('span');
      sub.className = 'search-sub';
      sub.textContent = r.context || '';
      a.append(kind, label, sub);
      li.appendChild(a);
      list.appendChild(li);
    });
    input.setAttribute('aria-expanded', results.length ? 'true' : 'false');
    status.textContent = results.length
      ? results.length + (results.length === 1 ? ' result' : ' results')
      : (term.length < 2 ? 'Type at least two letters.' : 'Nothing matches “' + term + '”.');
    if (results.length) select(0);
  }
  function search() {
    var term = input.value.trim();
    if (term === lastTerm) return;
    lastTerm = term;
    if (term.length < 2) { render([], term); return; }
    if (controller) controller.abort();
    controller = window.AbortController ? new AbortController() : null;
    status.textContent = 'Searching…';
    fetch(endpoint + '?q=' + encodeURIComponent(term), {
      credentials: 'same-origin', headers: { Accept: 'application/json' }, signal: controller ? controller.signal : undefined
    }).then(function (response) {
      if (!response.ok) throw new Error('search failed');
      return response.json();
    }).then(function (data) {
      if (input.value.trim() === term) render(data.results || [], term);
    }).catch(function (error) {
      if (error.name !== 'AbortError') status.textContent = 'Search is not available right now.';
    });
  }
  input.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(search, 160); });
  input.addEventListener('keydown', function (event) {
    if (event.key === 'ArrowDown') { event.preventDefault(); select(active + 1); }
    else if (event.key === 'ArrowUp') { event.preventDefault(); select(active - 1); }
    else if (event.key === 'Enter') {
      var all = options();
      if (active >= 0 && all[active]) { event.preventDefault(); window.location.href = all[active].querySelector('a').href; }
    }
  });
  list.addEventListener('mousemove', function (event) {
    var li = event.target.closest('[role=option]');
    if (li) select(options().indexOf(li));
  });
  document.addEventListener('keydown', function (event) {
    if ((event.ctrlKey || event.metaKey) && !event.altKey && (event.key === 'k' || event.key === 'K')) {
      event.preventDefault();
      if (dialog.hidden) open(dialog, document.activeElement); else close(dialog);
    }
  });
  document.addEventListener('click', function (event) {
    var opener = event.target.closest('[data-search-open]');
    if (!opener) return;
    var toggle = document.getElementById('navtoggle');
    if (toggle) toggle.checked = false;
    open(dialog, opener);
  });
  dialog.addEventListener('pui:open', function () { input.select(); });
})();
