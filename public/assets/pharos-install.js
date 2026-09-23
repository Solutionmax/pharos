// Pharos setup (steps 6 and 7). Everything here is an extra: the form posts and
// validates the normal way without it.
(function () {
  'use strict';
  var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var $ = function (s, r) { return (r || document).querySelector(s); };

  // Rail: the line runs green from step 1 to the current step.
  var steps = document.querySelectorAll('[data-pi-steps] li');
  var now = $('[data-pi-steps] li.now');
  var fill = $('[data-pi-fill]');
  if (fill && now && steps.length) fill.style.height = (now.offsetTop - steps[0].offsetTop) + 'px';
  // On a phone the rail is a sideways strip: keep the current step in view.
  if (now && now.scrollIntoView && window.innerWidth <= 900) now.scrollIntoView({ block: 'nearest', inline: 'center' });

  // Time zone: preselect the browser's own when nobody picked one yet.
  var tz = $('#timezone');
  if (tz && tz.value === 'UTC' && window.Intl && Intl.DateTimeFormat) {
    var zone = '';
    try { zone = Intl.DateTimeFormat().resolvedOptions().timeZone || ''; } catch (e) {}
    var option = zone && zone !== 'UTC' ? tz.querySelector('option[value="' + zone.replace(/"/g, '') + '"]') : null;
    if (option) {
      option.selected = true;
      option.textContent = zone + ' (detected)';
      var help = $('[data-pi-tz-help]');
      if (help) help.textContent = 'Detected from your browser. Stored in UTC, so you can change it later.';
    }
  }

  // Password: a strength meter that only ever says something useful.
  var pw = $('[data-pi-password]'), meter = $('[data-pi-meter]'), label = $('[data-pi-meter-label]');
  var repeat = $('[data-pi-password-repeat]'), repeatLabel = $('[data-pi-repeat-label]');
  function score(v) {
    var kinds = [/[a-z]/, /[A-Z]/, /[0-9]/, /[^a-zA-Z0-9]/].filter(function (r) { return r.test(v); }).length;
    var s = Math.floor(v.length / 5) + kinds - 1;
    if (v.length < 12) s = Math.min(s, 1);
    if (/^(.)\1+$/.test(v)) s = 0;
    return Math.max(0, Math.min(4, s));
  }
  function paintMeter() {
    if (!pw || !meter || !label) return;
    var v = pw.value, s = score(v);
    meter.style.width = v ? Math.max(8, (s + 1) * 20) + '%' : '0';
    meter.style.background = ['var(--red)', 'var(--red)', 'var(--amber)', 'var(--green)', 'var(--green)'][s];
    label.className = 'pi-help';
    if (!v) { label.textContent = 'At least 12 characters. A sentence or a password manager works best.'; return; }
    if (v.length < 12) { label.textContent = (12 - v.length) + ' more ' + (12 - v.length === 1 ? 'character' : 'characters'); return; }
    label.textContent = s >= 3 ? 'Strong' : 'Fine. Longer is stronger.';
    if (s >= 3) label.className = 'pi-help pi-good';
  }
  function paintRepeat() {
    if (!pw || !repeat || !repeatLabel) return;
    if (!repeat.value) { repeatLabel.textContent = ''; repeatLabel.className = 'pi-help'; return; }
    var same = repeat.value === pw.value;
    repeatLabel.textContent = same ? 'Matches' : (pw.value.indexOf(repeat.value) === 0 ? '' : 'Does not match yet');
    repeatLabel.className = 'pi-help ' + (same ? 'pi-good' : 'pi-bad');
  }
  if (pw) { pw.addEventListener('input', function () { paintMeter(); paintRepeat(); }); paintMeter(); }
  if (repeat) repeat.addEventListener('input', paintRepeat);

  var submit = $('[data-pi-submit]');
  if (submit) {
    submit.form.addEventListener('submit', function () {
      // Let the browser post first, then show that something is happening.
      setTimeout(function () { submit.disabled = true; submit.textContent = 'Creating your account…'; }, 0);
    });
  }

  // Done: the wall turns green column by column.
  var wall = $('[data-pi-wall]');
  if (wall) {
    var cells = wall.children, cols = window.innerWidth <= 900 ? 20 : 40;
    for (var i = 0; i < cells.length; i++) {
      (function (cell, delay) { setTimeout(function () { cell.classList.add('go'); }, delay); })(cells[i], reduce ? 0 : (i % cols) * 22 + Math.floor(i / cols) * 30);
    }
  }
})();
