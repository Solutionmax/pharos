/*
 * Pharos sign in ("Pulse"). Progressive enhancement only: without this file
 * every form posts the normal way and nothing is lost.
 *
 * The sign in and two factor forms are sent with fetch and the wall plays the
 * REAL outcome, read from where the server's redirect ended:
 *   final URL is the form's own page again  -> refused (errors parsed from that page)
 *   final URL is the two factor page        -> go there (sign in form only)
 *   final URL is the sign in page           -> the pending step expired (two factor form only)
 *   anything else after a redirect          -> signed in: green wave, then go there
 * Nothing typed is logged or stored.
 */
(function () {
  'use strict';

  var root = document.querySelector('[data-pa]');
  if (!root) return;

  var motion = window.matchMedia('(prefers-reduced-motion: reduce)');
  var live = document.querySelector('[data-pa-live]');
  var side = root.querySelector('[data-pa-side]');

  function calm() { return motion.matches; }
  function wait(ms) {
    return new Promise(function (done) { setTimeout(done, calm() ? Math.min(ms, 150) : ms); });
  }
  // How long an outcome stays on screen: not shortened for reduced motion, since
  // then the colour is the only thing that tells you what happened.
  function hold(ms) { return new Promise(function (done) { setTimeout(done, ms); }); }
  function say(text) { if (live) live.textContent = text; }

  var wall = createWall(root.querySelector('[data-pa-wall]'));

  /* ---------- the wall: uptime cells and a heartbeat ---------- */
  function createWall(el) {
    var none = { wave: function () { return Promise.resolve(); }, clear: function () {}, pace: function () {}, flat: function () {} };
    if (!el) return none;
    var grid = el.querySelector('[data-pa-grid]');
    var svg = el.querySelector('[data-pa-ekg]');
    var lines = svg ? svg.querySelectorAll('path') : [];
    var cells = [], cols = 0, rows = 0, phase = 0, speed = 90, isFlat = false, width = 0, height = 0;

    function build() {
      var narrow = window.innerWidth <= 900;
      var nextCols = Math.max(18, Math.round(grid.clientWidth / 16));
      var nextRows = narrow ? 7 : 16;
      if (nextCols === cols && nextRows === rows && cells.length) return measure();
      cols = nextCols; rows = nextRows;
      grid.style.setProperty('--cols', cols);
      var frag = document.createDocumentFragment();
      cells = [];
      for (var i = 0; i < cols * rows; i++) {
        var cell = document.createElement('i');
        var r = Math.random();
        if (r < 0.003) cell.className = 'w';
        else if (r < 0.03) cell.className = 'o';
        frag.appendChild(cell);
        cells.push(cell);
      }
      grid.textContent = '';
      grid.appendChild(frag);
      measure();
    }

    function measure() {
      if (!svg) return;
      width = svg.clientWidth; height = svg.clientHeight;
      if (width && height) svg.setAttribute('viewBox', '0 0 ' + width + ' ' + height);
      draw();
    }

    function draw() {
      if (!width || !lines.length) return;
      var mid = height / 2, amp = height * 0.38, period = 260, d = '';
      for (var x = 0; x <= width; x += 3) {
        var u = ((x + phase) % period) / period, y = mid;
        if (!isFlat) {
          if (u > 0.40 && u < 0.44) y = mid - (u - 0.40) / 0.04 * amp * 0.25;
          else if (u >= 0.44 && u < 0.48) y = mid - amp * 0.25 + (u - 0.44) / 0.04 * amp * 1.25;
          else if (u >= 0.48 && u < 0.52) y = mid + amp - (u - 0.48) / 0.04 * amp * 1.9;
          else if (u >= 0.52 && u < 0.56) y = mid - amp * 0.9 + (u - 0.52) / 0.04 * amp * 0.9;
          else if (u > 0.66 && u < 0.76) y = mid - Math.sin((u - 0.66) / 0.1 * Math.PI) * amp * 0.22;
        }
        d += (x ? 'L' : 'M') + x + ' ' + y.toFixed(1);
      }
      for (var i = 0; i < lines.length; i++) lines[i].setAttribute('d', d);
    }

    function tick() {
      if (!calm() && !document.hidden) { phase += speed / 60; draw(); }
      window.requestAnimationFrame(tick);
    }

    var resizeTimer;
    window.addEventListener('resize', function () {
      clearTimeout(resizeTimer);
      resizeTimer = setTimeout(build, 120);
    });
    build();
    window.requestAnimationFrame(tick);

    // Idle: now and then a cell blips amber and recovers. Decoration, not data.
    setInterval(function () {
      if (calm() || document.hidden || !cells.length) return;
      var cell = cells[Math.floor(Math.random() * cells.length)];
      if (cell.className) return;
      cell.className = 'w';
      setTimeout(function () { if (cell.className === 'w') cell.className = ''; }, 1600);
    }, 900);

    return {
      // A wave rolls out from the left edge, rows spreading from the middle.
      wave: function (cls, step) {
        var last = 0;
        cells.forEach(function (cell, i) {
          var x = i % cols, y = Math.floor(i / cols);
          var delay = calm() ? 0 : Math.hypot(x, (y - rows / 2) * 1.6) * step;
          last = Math.max(last, delay);
          setTimeout(function () { cell.classList.add(cls); }, delay);
        });
        return wait(last + 150);
      },
      clear: function (cls) { cells.forEach(function (cell) { cell.classList.remove(cls); }); },
      pace: function (value) { speed = value; },
      flat: function (on) { isFlat = on; draw(); }
    };
  }

  /* ---------- show and hide password ---------- */
  root.querySelectorAll('[data-pa-eye]').forEach(function (eye) {
    var input = document.getElementById(eye.getAttribute('aria-controls'));
    if (!input) return;
    eye.hidden = false;
    eye.addEventListener('click', function () {
      var show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      eye.textContent = show ? 'Hide' : 'Show';
      eye.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    });
  });

  /* ---------- plain forms: the heartbeat while the page posts ---------- */
  root.querySelectorAll('.pa-form:not([data-pa-login]):not([data-pa-two-factor])').forEach(function (form) {
    form.addEventListener('submit', function () {
      var btn = form.querySelector('[data-pa-submit]');
      if (btn) btn.classList.add('is-busy');
      wall.pace(200);
    });
  });

  /* ---------- the fetch flow ---------- */
  function pathOf(url) {
    try { return new URL(url, window.location.href).pathname.replace(/\/+$/, ''); } catch (e) { return ''; }
  }

  function parse(html) {
    return new DOMParser().parseFromString(html, 'text/html');
  }

  // The messages the server rendered on the page the redirect ended on.
  function messagesIn(doc) {
    var items = Array.prototype.map.call(doc.querySelectorAll('[data-pa-errors] li'), function (li) {
      return li.textContent.trim();
    }).filter(Boolean);
    if (items.length) return items;
    var flash = doc.querySelector('[data-pa-flash]');
    return flash && flash.textContent.trim() ? [flash.textContent.trim()] : [];
  }

  function refreshToken(form, doc) {
    var fresh = doc.querySelector('input[name="_token"]');
    var mine = form.querySelector('input[name="_token"]');
    if (fresh && mine && fresh.value) mine.value = fresh.value;
    var meta = document.querySelector('meta[name="csrf-token"]');
    if (fresh && meta && fresh.value) meta.setAttribute('content', fresh.value);
  }

  function showErrors(box, messages) {
    if (!box) return;
    var list = document.createElement('ul');
    messages.forEach(function (text) {
      var li = document.createElement('li');
      li.textContent = text;
      list.appendChild(li);
    });
    box.hidden = false;
    box.textContent = '';
    box.appendChild(list);
  }

  function hideErrors(box) {
    if (!box) return;
    box.hidden = true;
    box.textContent = '';
  }

  function outcome(res, form) {
    if (res.status === 419) return 'expired';
    if (res.status === 429) return 'throttled';
    if (!res.ok || !res.redirected) return 'unknown';
    var end = pathOf(res.url);
    if (end === pathOf(form.dataset.paFail)) return 'refused';
    if (form.dataset.paStep && end === pathOf(form.dataset.paStep)) return 'step';
    if (form.dataset.paBack && end === pathOf(form.dataset.paBack)) return 'back';
    return 'signed-in';
  }

  function throttleMessage(res, doc) {
    var after = parseInt(res.headers.get('Retry-After') || '', 10);
    if (after > 0) {
      return 'Too many attempts. Try again in ' + (after >= 60 ? Math.ceil(after / 60) + ' minute' + (Math.ceil(after / 60) === 1 ? '' : 's') : after + ' seconds') + '.';
    }
    var title = doc && doc.title ? doc.title.trim() : '';
    return title ? title + '. Wait a moment and try again.' : 'Too many attempts. Wait a moment and try again.';
  }

  /**
   * Takes over one form. `hooks.before()` may veto (return false), `hooks.failed()`
   * puts focus back where the person should type next.
   */
  function enhance(form, hooks) {
    var btn = form.querySelector('[data-pa-submit]');
    var box = root.querySelector('[data-pa-errors]');
    var busy = false;

    function setBusy(on) {
      busy = on;
      btn.classList.toggle('is-busy', on);
      btn.setAttribute('aria-disabled', on ? 'true' : 'false');
      form.setAttribute('aria-busy', on ? 'true' : 'false');
      wall.pace(on ? 200 : 90);
    }

    async function refused(messages) {
      showErrors(box, messages);
      btn.classList.add('is-flat');
      var beat = btn.querySelector('.pa-btn-beat path');
      var shape = beat ? beat.getAttribute('d') : null;
      if (beat) beat.setAttribute('d', 'M0 25h400');
      root.classList.add('is-bad');
      wall.flat(true);
      say(messages.join(' '));
      var rolled = wall.wave('bad', 6);
      if (!calm() && side) side.classList.add('pa-shake');
      await wait(450);
      if (side) side.classList.remove('pa-shake');
      hooks.failed();
      await rolled;
      await hold(calm() ? 1400 : 350);
      btn.classList.remove('is-flat');
      if (beat && shape) beat.setAttribute('d', shape);
      root.classList.remove('is-bad');
      wall.flat(false);
      wall.clear('bad');
      setBusy(false);
    }

    async function signedIn(url) {
      say('Signed in. Opening your dashboard.');
      btn.classList.add('is-good');
      root.classList.add('is-good');
      wall.pace(320);
      await wall.wave('go', 14);
      await hold(calm() ? 600 : 250);
      root.classList.add('is-leaving');
      await wait(350);
      window.location.assign(url);
    }

    async function expired(doc) {
      // Fetch the form page again for a token that belongs to the current session.
      try {
        var res = await fetch(form.dataset.paFail, { credentials: 'same-origin', headers: { Accept: 'text/html' }, cache: 'no-store' });
        doc = parse(await res.text());
        if (pathOf(res.url) !== pathOf(form.dataset.paFail)) {
          window.location.assign(res.url);
          return;
        }
      } catch (e) { /* fall through with whatever we have */ }
      if (doc) refreshToken(form, doc);
      await refused(['Your session had expired, so we refreshed it. Try again.']);
    }

    function native() {
      // Whatever we cannot read, the browser can: post the normal way.
      setBusy(false);
      HTMLFormElement.prototype.submit.call(form);
    }

    form.addEventListener('submit', async function (event) {
      if (!window.fetch || !window.DOMParser) return;
      event.preventDefault();
      if (busy) return;
      if (hooks.before && hooks.before() === false) return;

      hideErrors(box);
      form.querySelectorAll('.is-bad').forEach(function (el) { el.classList.remove('is-bad'); });
      setBusy(true);
      say('Checking.');
      var started = Date.now();
      var res, html;
      try {
        res = await fetch(form.action, {
          method: 'POST',
          body: new FormData(form),
          credentials: 'same-origin',
          headers: { Accept: 'text/html' },
          redirect: 'follow',
          cache: 'no-store'
        });
        html = await res.text();
      } catch (e) {
        native();
        return;
      }
      // Long enough for the heartbeat to be seen; a real answer is never faked.
      await wait(Math.max(0, 700 - (Date.now() - started)));

      var kind = outcome(res, form);
      var doc = parse(html);
      if (kind === 'signed-in') return signedIn(res.url);
      if (kind === 'step') { say('Password accepted.'); window.location.assign(res.url); return; }
      if (kind === 'expired') return expired(doc);
      if (kind === 'throttled') return refused([throttleMessage(res, doc)]);
      if (kind === 'unknown') return native();

      refreshToken(form, doc);
      var messages = messagesIn(doc);
      if (kind === 'back') {
        return refused(messages.length ? messages : ['This sign in step expired. Go back and sign in again.']);
      }
      return refused(messages.length ? messages : ['That did not work. Try again.']);
    });
  }

  /* ---------- sign in ---------- */
  var login = root.querySelector('[data-pa-login]');
  if (login) {
    enhance(login, {
      failed: function () {
        var box = login.querySelector('[data-pa-pwbox]');
        var pw = login.querySelector('input[name="password"]');
        if (box) box.classList.add('is-bad');
        if (pw) { pw.focus(); pw.select(); }
      }
    });
  }

  /* ---------- two factor ---------- */
  var tfa = root.querySelector('[data-pa-two-factor]');
  if (tfa) setUpTwoFactor(tfa);

  function setUpTwoFactor(form) {
    var wrap = form.querySelector('[data-pa-codes]');
    var group = wrap.querySelector('.pa-codes');
    var digits = Array.prototype.slice.call(form.querySelectorAll('[data-pa-digit]'));
    var field = form.querySelector('[data-pa-code-field]');
    var code = form.querySelector('input[name="code"]');
    var label = form.querySelector('[data-pa-code-label]');
    var help = form.querySelector('#code-help');
    var helpText = help ? help.textContent : '';
    var toggle = form.querySelector('[data-pa-mode]');
    var submit = form.querySelector('[data-pa-submit]');
    var recovery = false;

    function joined() { return digits.map(function (d) { return d.value; }).join(''); }

    function send() {
      if (typeof form.requestSubmit === 'function') form.requestSubmit(submit);
      else submit.click();
    }

    function fill(from, text) {
      var chars = text.replace(/\D/g, '').split('');
      for (var k = from; k < digits.length && chars.length; k++) digits[k].value = chars.shift();
      var next = digits.find(function (d) { return !d.value; });
      (next || digits[digits.length - 1]).focus();
      if (joined().length === digits.length) send();
    }

    function setMode(useRecovery) {
      recovery = useRecovery;
      wrap.hidden = useRecovery;
      field.hidden = !useRecovery;
      code.required = useRecovery;
      code.value = '';
      code.setAttribute('inputmode', useRecovery ? 'text' : 'numeric');
      code.setAttribute('autocomplete', useRecovery ? 'off' : 'one-time-code');
      label.textContent = useRecovery ? 'Recovery code' : 'Code';
      if (help) help.textContent = useRecovery ? 'Each recovery code works once.' : helpText;
      toggle.textContent = useRecovery ? 'Use the six digit code' : 'Use a recovery code';
      digits.forEach(function (d) { d.value = ''; });
      (useRecovery ? code : digits[0]).focus();
    }

    digits.forEach(function (input, i) {
      input.addEventListener('input', function () {
        var value = input.value.replace(/\D/g, '');
        input.value = '';
        if (value) fill(i, value);
      });
      input.addEventListener('keydown', function (e) {
        if (e.key === 'Backspace' && !input.value && i > 0) {
          e.preventDefault();
          digits[i - 1].value = '';
          digits[i - 1].focus();
        } else if (e.key === 'ArrowLeft' && i > 0) {
          e.preventDefault(); digits[i - 1].focus();
        } else if (e.key === 'ArrowRight' && i < digits.length - 1) {
          e.preventDefault(); digits[i + 1].focus();
        }
      });
      input.addEventListener('focus', function () { input.select(); });
      input.addEventListener('paste', function (e) {
        var text = (e.clipboardData || window.clipboardData).getData('text') || '';
        if (!/\d/.test(text)) return;
        e.preventDefault();
        fill(text.replace(/\D/g, '').length >= digits.length ? 0 : i, text);
      });
    });

    toggle.hidden = false;
    toggle.addEventListener('click', function () { setMode(!recovery); });
    setMode(false);

    enhance(form, {
      before: function () {
        if (recovery) return true;
        var value = joined();
        if (value.length < digits.length) {
          var empty = digits.find(function (d) { return !d.value; });
          if (empty) empty.focus();
          return false;
        }
        code.value = value;
        return true;
      },
      failed: function () {
        if (recovery) {
          field.classList.add('is-bad');
          code.focus(); code.select();
          return;
        }
        group.classList.add('is-bad');
        digits.forEach(function (d) { d.value = ''; });
        digits[0].focus();
        setTimeout(function () { group.classList.remove('is-bad'); }, 1200);
      }
    });
  }
})();
