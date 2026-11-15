// Public status page: read a day across every service, and jump from a dot on a
// course strip to its message. Listeners sit on the document, so they keep working
// after the 30 second refresh swaps the live region for a fresh copy.
(function () {
  'use strict';
  var cache = new WeakMap();

  // The selected day lives on the chart itself: a chart swapped in by the refresh starts clean.
  function selectedOf(chart) {
    return chart.dataset.day === undefined ? null : Number(chart.dataset.day);
  }

  // The notes are re-read when the refresh rewrites them (an open incident's duration grows).
  function notes(chart) {
    var source = chart.querySelector('script[data-notes]'), text = source ? source.textContent : '', seen = cache.get(chart);
    if (!seen || seen.text !== text) {
      var data = [];
      try { data = JSON.parse(text || '[]'); } catch (e) { data = []; }
      seen = {text: text, data: data};
      cache.set(chart, seen);
    }
    return seen.data;
  }

  function select(chart, i) {
    var out = chart.querySelector('.readout'), list = notes(chart);
    if (!out) return;
    out.textContent = '';
    if (i === null || !list[i]) {
      delete chart.dataset.day;
      chart.style.removeProperty('--i');
      // A finger cannot point: on a touch screen the hint says tap.
      var touch = window.matchMedia && window.matchMedia('(hover: none)').matches;
      out.textContent = (touch && chart.dataset.hintTouch) || chart.dataset.hint || '';
      return;
    }
    chart.dataset.day = i;
    chart.style.setProperty('--i', i);
    var when = document.createElement('b'), what = document.createElement('span');
    when.textContent = list[i].label;
    what.textContent = list[i].text;
    out.append(when, what);
  }

  function dayAt(chart, x) {
    var track = chart.querySelector('.track'), days = notes(chart).length;
    if (!track || !days) return null;
    var box = track.getBoundingClientRect();
    if (!box.width) return null;
    var i = Math.floor((x - box.left) / box.width * days);
    return i < 0 || i >= days ? null : i;
  }

  function flash(node) {
    node.classList.remove('flash');
    void node.offsetWidth;
    node.classList.add('flash');
  }

  function reveal(chart, i) {
    var note = i === null ? null : notes(chart)[i];
    var target = note && document.querySelector('.entry[data-day="' + note.day + '"]');
    if (!target) return;
    target.scrollIntoView({block: 'center'});
    flash(target);
  }

  document.addEventListener('pointermove', function (e) {
    var chart = e.target.closest && e.target.closest('.chart');
    if (!chart) return;
    var i = dayAt(chart, e.clientX);
    if (i !== selectedOf(chart)) select(chart, i);
  });
  document.addEventListener('pointerout', function (e) {
    var chart = e.target.closest && e.target.closest('.chart');
    if (!chart || chart.contains(e.relatedTarget) || e.pointerType !== 'mouse' || document.activeElement === chart) return;
    select(chart, null);
  });
  document.addEventListener('click', function (e) {
    if (!e.target.closest) return;
    var dot = e.target.closest('.strip .dot[data-msg]');
    if (dot) {
      var card = dot.closest('.card'), item = card && card.querySelector('li[data-msg="' + dot.dataset.msg + '"]');
      if (!item) return;
      var fold = item.closest('details.more');
      if (fold) fold.open = true;
      flash(item);
      item.scrollIntoView({block: 'nearest'});
      return;
    }
    var chart = e.target.closest('.chart');
    if (chart && !e.target.closest('summary, a, button')) reveal(chart, dayAt(chart, e.clientX));
  });
  document.addEventListener('keydown', function (e) {
    var chart = e.target.classList && e.target.classList.contains('chart') ? e.target : null;
    if (!chart) return;
    var last = notes(chart).length - 1, step = {ArrowLeft: -1, ArrowRight: 1}[e.key], selected = selectedOf(chart);
    if (step) {
      e.preventDefault();
      select(chart, Math.max(0, Math.min(last, (selected === null ? last + (step > 0 ? -1 : 1) : selected) + step)));
    } else if (e.key === 'Home') { e.preventDefault(); select(chart, 0); }
    else if (e.key === 'End') { e.preventDefault(); select(chart, last); }
    else if (e.key === 'Escape') select(chart, null);
    else if (e.key === 'Enter' && selected !== null) reveal(chart, selected);
  });
  document.addEventListener('focusout', function (e) {
    if (e.target.classList && e.target.classList.contains('chart')) select(e.target, null);
  });

  // "Get notified": the page reaches a new visitor without a session (it may come
  // from a cache), so the form asks for its token when it opens. That request
  // starts the session the sign-up needs. A submit before the answer waits for it.
  var pending = new WeakMap();

  function fetchToken(form) {
    var field = form.querySelector('input[name="_token"]');
    if (!field || !window.fetch) return Promise.resolve();
    if (pending.has(form)) return pending.get(form);
    var request = fetch(form.dataset.tokenUrl, {credentials: 'same-origin', cache: 'no-store', headers: {Accept: 'application/json'}})
      .then(function (response) { return response.ok ? response.json() : null; })
      .then(function (data) { if (data && data.token) field.value = data.token; })
      .catch(function () {})
      .then(function () { pending.delete(form); });
    pending.set(form, request);
    return request;
  }

  document.addEventListener('toggle', function (e) {
    var details = e.target;
    if (!details.matches || !details.matches('details.updates') || !details.open) return;
    var form = details.querySelector('form[data-token-url]');
    if (form) fetchToken(form);
  }, true);
  document.addEventListener('submit', function (e) {
    var form = e.target.closest && e.target.closest('form[data-token-url]'), field = form && form.querySelector('input[name="_token"]');
    if (!field || field.value) return;
    e.preventDefault();
    fetchToken(form).then(function () { form.submit(); });
  });
})();
