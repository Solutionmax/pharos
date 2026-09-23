/* Pharos admin: the search palette (Ctrl K or Cmd K).
 *
 * Markup: resources/views/partials/search-dialog.blade.php. Opening, closing,
 * the focus trap and Escape come from window.pharosUi (pharos-ui.js), which is
 * loaded first. Everything the server sends is shown with textContent; the
 * only markup built here is our own elements.
 *
 * Before typing: "Recent" (the last results opened in this browser, per
 * account, in localStorage) and "Jump to" (actions the server rendered for
 * this user and page). While typing: results from admin.search, grouped.
 */
(function () {
  'use strict';

  var dialog = document.getElementById('pharos-search');
  if (!dialog || !window.pharosUi) return;

  var MIN_LENGTH = 2;
  var DEBOUNCE_MS = 120;
  var BUSY_DELAY_MS = 90;
  var MAX_RECENT = 5;
  var TAG_COLORS = ['blue', 'teal', 'violet', 'amber', 'rose', 'slate'];
  var TONES = ['ok', 'w', 'p', 'b', 'm', 'off'];

  var ui = window.pharosUi;
  var input = dialog.querySelector('input[role=combobox]');
  var list = dialog.querySelector('[role=listbox]');
  var stateBox = dialog.querySelector('[data-search-state]');
  var live = dialog.querySelector('[data-search-status]');
  var clearButton = dialog.querySelector('[data-search-clear]');
  var endpoint = input.getAttribute('data-endpoint');
  var pageId = dialog.getAttribute('data-page') || '';
  var recentKey = 'pharos.search.recent.' + (dialog.getAttribute('data-user') || '0');

  var mac = /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent);
  document.querySelectorAll('[data-search-key]').forEach(function (k) { k.textContent = mac ? '⌘ K' : 'Ctrl K'; });

  var icons = {};
  var iconTemplate = dialog.querySelector('template[data-search-icons]');
  if (iconTemplate) {
    Array.prototype.forEach.call(iconTemplate.content.querySelectorAll('[data-icon]'), function (holder) {
      icons[holder.getAttribute('data-icon')] = holder.querySelector('svg');
    });
  }

  var startActions = [];
  try {
    var startData = JSON.parse((dialog.querySelector('[data-search-start]') || {}).textContent || '[]');
    if (Array.isArray(startData)) startActions = startData.filter(isRow);
  } catch (e) { startActions = []; }

  var rows = [];          // the data behind each option, by index
  var active = -1;
  var timer = null, busyTimer = null, controller = null;
  var lastTerm = null;
  var mode = 'start';     // start, results, empty, error or loading: what the list shows now
  var cache = {};

  /* ---------- helpers ---------- */

  function sameOrigin(url) {
    try {
      var parsed = new URL(url, window.location.href);
      return parsed.origin === window.location.origin ? parsed.href : null;
    } catch (e) { return null; }
  }

  function isRow(row) {
    return !!row && typeof row === 'object' && typeof row.label === 'string' && typeof row.url === 'string' && sameOrigin(row.url) !== null;
  }

  function el(tag, className, text) {
    var node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined && text !== null) node.textContent = text;
    return node;
  }

  function icon(name) {
    var holder = el('span', 'search-ico search-ico--' + (icons[name] ? name : 'search'));
    holder.setAttribute('aria-hidden', 'true');
    var svg = icons[name] || icons.search;
    if (svg) holder.appendChild(svg.cloneNode(true));
    return holder;
  }

  /* Wraps every case insensitive occurrence of term in <mark>, as text nodes. */
  function highlight(target, text, term) {
    var needle = (term || '').toLocaleLowerCase();
    var hay = text.toLocaleLowerCase();
    if (!needle || hay.length !== text.length) { target.textContent = text; return; }
    var from = 0, at;
    while ((at = hay.indexOf(needle, from)) !== -1) {
      if (at > from) target.appendChild(document.createTextNode(text.slice(from, at)));
      target.appendChild(el('mark', null, text.slice(at, at + needle.length)));
      from = at + needle.length;
    }
    if (from < text.length) target.appendChild(document.createTextNode(text.slice(from)));
  }

  function say(message) {
    live.textContent = '';
    window.setTimeout(function () { live.textContent = message; }, 30);
  }

  /* ---------- recent (this browser, this account) ---------- */

  function readRecent() {
    try {
      var saved = JSON.parse(window.localStorage.getItem(recentKey) || '[]');
      return Array.isArray(saved) ? saved.filter(isRow).slice(0, MAX_RECENT) : [];
    } catch (e) { return []; }
  }

  function remember(row) {
    // Status and age go stale, so only what identifies the row is kept.
    var keep = { type: row.type, icon: row.icon, label: row.label, context: row.context, url: row.url, page: row.page, hint: 'Open' };
    try {
      var saved = readRecent().filter(function (r) { return r.url !== row.url; });
      saved.unshift(keep);
      window.localStorage.setItem(recentKey, JSON.stringify(saved.slice(0, MAX_RECENT)));
    } catch (e) { /* storage blocked: the palette works without it */ }
  }

  function clearRecent() {
    try { window.localStorage.removeItem(recentKey); } catch (e) { /* nothing to clear */ }
    showStart();
    say('Recent items cleared');
    input.focus();
  }

  /* ---------- rendering ---------- */

  function subline(row) {
    var sub = el('span', 'search-sub');
    var page = row.page && typeof row.page === 'object' ? row.page : null;
    if (page && typeof page.tag === 'string') {
      var color = TAG_COLORS.indexOf(page.color) !== -1 ? page.color : 'slate';
      sub.appendChild(el('span', 'page-tag page-tag--' + color, page.tag));
      if (row.type !== 'Status page' && typeof page.name === 'string') sub.appendChild(el('span', 'search-page', page.name));
    }
    if (row.status && typeof row.status.label === 'string') {
      var tone = TONES.indexOf(row.status.tone) !== -1 ? row.status.tone : 'off';
      sub.appendChild(el('span', 'search-pill st-' + tone, row.status.label));
    }
    if (typeof row.meta === 'string' && row.meta) sub.appendChild(el('span', 'search-meta', row.meta));
    var context = typeof row.context === 'string' ? row.context : '';
    if (context && (!page || context !== page.name) && row.type !== 'Status page') sub.appendChild(el('span', 'search-ctx', context));
    return sub;
  }

  function option(row, index, term) {
    var a = el('a', 'search-row');
    a.id = 'pharos-search-opt-' + index;
    a.href = sameOrigin(row.url) || '#';
    a.tabIndex = -1;
    a.setAttribute('role', 'option');
    a.setAttribute('aria-selected', 'false');
    a.setAttribute('data-index', String(index));
    var name = [row.label, row.type, row.page && row.page.name, row.status && row.status.label, row.meta]
      .filter(function (x) { return typeof x === 'string' && x; });
    a.setAttribute('aria-label', name.join(', '));
    a.appendChild(icon(row.icon));
    var main = el('span', 'search-main');
    var title = el('span', 'search-title');
    highlight(title, row.label, term);
    main.append(title, subline(row));
    var hint = el('span', 'search-hint');
    hint.setAttribute('aria-hidden', 'true');
    hint.appendChild(el('span', null, typeof row.hint === 'string' ? row.hint : 'Open'));
    hint.appendChild(icon('enter'));
    a.append(main, hint);
    return a;
  }

  /* sections: [{title, rows}] */
  function render(sections, term) {
    list.textContent = '';
    rows = [];
    active = -1;
    sections.forEach(function (section, s) {
      if (!section.rows.length) return;
      var group = el('div', 'search-group');
      group.setAttribute('role', 'group');
      var head = el('div', 'search-hd');
      head.id = 'pharos-search-hd-' + s;
      head.setAttribute('role', 'presentation');
      head.append(el('span', null, section.title), el('span', 'search-count', String(section.rows.length)));
      group.setAttribute('aria-labelledby', head.id);
      group.appendChild(head);
      section.rows.forEach(function (row) {
        group.appendChild(option(row, rows.length, term));
        rows.push(row);
      });
      list.appendChild(group);
    });
    input.setAttribute('aria-expanded', rows.length ? 'true' : 'false');
    list.hidden = !rows.length;
    if (rows.length) select(0); else input.removeAttribute('aria-activedescendant');
  }

  function groupResults(results) {
    var sections = [], byTitle = {};
    results.forEach(function (row) {
      var title = typeof row.group === 'string' ? row.group : 'Results';
      if (!byTitle[title]) { byTitle[title] = { title: title, rows: [] }; sections.push(byTitle[title]); }
      byTitle[title].rows.push(row);
    });
    return sections;
  }

  function setState(kind, build) {
    mode = kind === 'hint' ? 'start' : (kind || mode);
    stateBox.textContent = '';
    stateBox.removeAttribute('aria-hidden');
    stateBox.className = 'search-state' + (kind ? ' is-' + kind : '');
    stateBox.hidden = !kind;
    if (build) build(stateBox);
  }

  function showStart() {
    var recent = readRecent();
    clearButton.hidden = !recent.length;
    render([{ title: 'Recent', rows: recent }, { title: 'Jump to', rows: startActions }], '');
    mode = 'start';
    if (!rows.length) {
      setState('hint', function (box) {
        box.append(icon('search'), el('b', null, 'Search everything you can open'),
          el('span', null, 'Pages, components, services, incidents, maintenance and screens.'));
      });
    } else {
      setState(null);
    }
  }

  function showResults(results, term) {
    clearButton.hidden = true;
    if (!results.length) {
      setState('empty', function (box) {
        box.append(icon('search'), el('b', null, 'No results for “' + term + '”'),
          el('span', null, 'Check the spelling or try fewer letters.' + (startActions.length ? ' You can also jump straight to one of these.' : '')));
      });
      render([{ title: 'Suggestions', rows: startActions }], '');
      say('No results for ' + term);
      return;
    }
    setState(null);
    mode = 'results';
    var sections = groupResults(results);
    render(sections, term);
    say(results.length + (results.length === 1 ? ' result' : ' results') + ' in ' + sections.length + (sections.length === 1 ? ' group' : ' groups'));
  }

  function showError(message) {
    render([], '');
    setState('error', function (box) {
      var retry = el('button', 'btn ghost search-retry', 'Try again');
      retry.type = 'button';
      retry.addEventListener('click', function () { lastTerm = null; search(); input.focus(); });
      box.append(icon('incidents'), el('b', null, 'Search did not answer'), el('span', null, message), retry);
    });
    say(message);
  }

  function showSkeleton() {
    render([], '');
    setState('loading', function (box) {
      box.setAttribute('aria-hidden', 'true');
      for (var i = 0; i < 4; i++) {
        var line = el('span', 'search-skel');
        line.append(el('i'), el('span'));
        box.appendChild(line);
      }
    });
  }

  function busy(on) {
    window.clearTimeout(busyTimer);
    if (!on) { dialog.classList.remove('is-busy'); return; }
    busyTimer = window.setTimeout(function () {
      dialog.classList.add('is-busy');
      // Keep the rows of the previous term in place; only an empty list gets placeholders.
      if (mode !== 'results') showSkeleton();
    }, BUSY_DELAY_MS);
  }

  /* ---------- selection and opening ---------- */

  function options() { return list.querySelectorAll('[role=option]'); }

  function select(index) {
    var all = options();
    if (!all.length) { active = -1; input.removeAttribute('aria-activedescendant'); return; }
    active = (index + all.length) % all.length;
    Array.prototype.forEach.call(all, function (o, i) { o.setAttribute('aria-selected', i === active ? 'true' : 'false'); });
    input.setAttribute('aria-activedescendant', all[active].id);
    all[active].scrollIntoView({ block: 'nearest' });
  }

  function go(index, newTab) {
    var row = rows[index];
    var url = row && sameOrigin(row.url);
    if (!url) return;
    remember(row);
    if (newTab) { window.open(url, '_blank', 'noopener'); return; }
    ui.close(dialog);
    window.location.href = url;
  }

  /* ---------- searching ---------- */

  function search() {
    var term = input.value.trim();
    if (term === lastTerm) return;
    lastTerm = term;
    if (controller) { controller.abort(); controller = null; }
    if (term.length < MIN_LENGTH) { busy(false); showStart(); return; }
    if (cache[term]) { busy(false); showResults(cache[term], term); return; }

    var mine = window.AbortController ? new AbortController() : null;
    controller = mine;
    busy(true);
    fetch(endpoint + '?q=' + encodeURIComponent(term) + (pageId ? '&page=' + encodeURIComponent(pageId) : ''), {
      credentials: 'same-origin', headers: { Accept: 'application/json' }, signal: mine ? mine.signal : undefined
    }).then(function (response) {
      if (response.status === 429) throw new Error('throttled');
      if (!response.ok) throw new Error('failed');
      return response.json();
    }).then(function (data) {
      var results = (data && Array.isArray(data.results) ? data.results : []).filter(isRow);
      cache[term] = results;
      if (input.value.trim() === term) showResults(results, term);
    }).catch(function (error) {
      if (error && error.name === 'AbortError') return;
      if (input.value.trim() !== term) return;
      lastTerm = null;
      showError(error && error.message === 'throttled'
        ? 'Too many searches in a short time. Wait a moment, then try again.'
        : 'Check your connection and try again.');
    }).then(function () {
      if (controller === mine) { controller = null; busy(false); }
    });
  }

  input.addEventListener('input', function () {
    window.clearTimeout(timer);
    if (input.value.trim().length < MIN_LENGTH) { search(); return; }
    timer = window.setTimeout(search, DEBOUNCE_MS);
  });

  input.addEventListener('keydown', function (event) {
    if (event.key === 'ArrowDown') { event.preventDefault(); select(active + 1); }
    else if (event.key === 'ArrowUp') { event.preventDefault(); select(active - 1); }
    else if (event.key === 'Enter' && !event.isComposing) {
      if (active < 0) return;
      event.preventDefault();
      go(active, event.metaKey || event.ctrlKey);
    }
  });

  list.addEventListener('click', function (event) {
    var opt = event.target.closest('[role=option]');
    if (!opt) return;
    var index = Number(opt.getAttribute('data-index'));
    if (event.metaKey || event.ctrlKey || event.shiftKey || event.button !== 0) { if (rows[index]) remember(rows[index]); return; }
    event.preventDefault();
    go(index, false);
  });
  list.addEventListener('auxclick', function (event) {
    var opt = event.target.closest('[role=option]');
    if (opt && rows[Number(opt.getAttribute('data-index'))]) remember(rows[Number(opt.getAttribute('data-index'))]);
  });
  // Pointer movement only: a list that scrolls under a still mouse must not steal the selection.
  list.addEventListener('mousemove', function (event) {
    var opt = event.target.closest('[role=option]');
    if (opt && Number(opt.getAttribute('data-index')) !== active) select(Number(opt.getAttribute('data-index')));
  });
  clearButton.addEventListener('click', clearRecent);

  /* ---------- opening ---------- */

  document.addEventListener('keydown', function (event) {
    if ((event.ctrlKey || event.metaKey) && !event.altKey && (event.key === 'k' || event.key === 'K')) {
      event.preventDefault();
      if (dialog.hidden) ui.open(dialog, document.activeElement); else ui.close(dialog);
    }
  });
  document.addEventListener('click', function (event) {
    var opener = event.target.closest('[data-search-open]');
    if (!opener) return;
    var toggle = document.getElementById('navtoggle');
    if (toggle) toggle.checked = false;
    ui.open(dialog, opener);
  });
  dialog.addEventListener('pui:open', function () {
    input.value = '';
    lastTerm = null;
    showStart();
    input.focus();
  });
  dialog.addEventListener('pui:close', function () {
    window.clearTimeout(timer);
    if (controller) { controller.abort(); controller = null; }
    busy(false);
  });
})();
