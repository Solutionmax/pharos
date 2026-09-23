/* Appearance, Branding: live preview and drop zones.
   Nothing is uploaded here. A picked file is shown through an object URL and
   travels with the form on Save; the server validates it again either way. */
(function () {
  'use strict';

  var form = document.getElementById('bx-form');
  var configEl = document.getElementById('bx-config');
  if (!form || !configEl) return;

  var config;
  try { config = JSON.parse(configEl.textContent); } catch (e) { return; }

  var root = document.querySelector('.bx');
  var nameInput = form.querySelector('#name');
  var accentInput = form.querySelector('#accent');
  var creditBox = form.querySelector('#credit_hidden');
  var state = document.querySelector('[data-pv-state]');
  var pvNote = document.querySelector('[data-pv-dirty]');
  var MIMES = { png: ['image/png'], jpg: ['image/jpeg'], jpeg: ['image/jpeg'], webp: ['image/webp'], ico: ['image/x-icon', 'image/vnd.microsoft.icon'] };

  // What each upload shows right now: a stored URL, a picked object URL, or null.
  var picked = {};
  var removed = {};

  function each(selector, fn) {
    Array.prototype.forEach.call(document.querySelectorAll(selector), fn);
  }

  function effective(field) {
    if (!config.licensed) return null;
    if (picked[field]) return picked[field];
    if (removed[field]) return null;
    return config.current[field] || null;
  }

  function show(el, visible) { el.hidden = !visible; }

  function render() {
    var name = (nameInput.value || '').trim() || 'Pharos';
    var accent = accentInput.value;
    var logo = effective('logo');
    var logoDark = effective('logo_dark');
    var favicon = effective('favicon');
    var creditHidden = config.licensed && !!(creditBox && creditBox.checked);
    var wordmark = name === 'Pharos';
    var custom = !!(logo || logoDark);
    var d = config.defaults;

    root.style.setProperty('--pv-accent', accent);
    each('[data-pv="accent-hex"]', function (el) { el.textContent = accent; });
    each('[data-pv="name"]', function (el) { el.textContent = name; });
    each('[data-pv="title"]', function (el) { el.textContent = name + ' Status'; });
    each('[data-pv="favicon"]', function (el) { el.src = favicon || d.favicon; });

    // Mirrors partials/logo.blade.php: a custom logo wins, then the wordmark
    // for "Pharos", else the lighthouse mark next to the name.
    var light = custom ? (logo || logoDark) : (wordmark ? d.logo : d.mark);
    var dark = custom ? (logoDark || logo) : (wordmark ? d.logo_white : d.mark_white);
    each('[data-pv="logo-light"]', function (el) { el.src = light; });
    each('[data-pv="logo-dark"]', function (el) { el.src = dark; });
    each('[data-pv="logo-name"]', function (el) { el.textContent = name; show(el, !custom && !wordmark); });
    each('[data-pv="credit"]', function (el) { show(el, !creditHidden); });

    // Mirrors MailTemplates::frame(): the light logo only, else the Pharos
    // email logo while the page is plain Pharos, else the name in the accent.
    var emailLogo = logo || (wordmark && !creditHidden ? d.email_logo : null);
    each('[data-pv="email-logo"]', function (el) { if (emailLogo) el.src = emailLogo; show(el, !!emailLogo); });
    each('[data-pv="email-name"]', function (el) { el.textContent = name; show(el, !emailLogo); });
    each('[data-pv="email-source"]', function (el) {
      el.textContent = logo ? 'your logo for the light theme' : (emailLogo ? 'the Pharos email logo' : 'the name in your accent colour');
    });
  }

  function markDirty() {
    var dirty = form.dataset.dirty === '1';
    if (state) {
      state.textContent = dirty ? 'Unsaved changes' : 'No changes yet';
      state.classList.toggle('on', dirty);
    }
    if (pvNote) {
      pvNote.textContent = dirty ? 'Showing unsaved changes' : 'Showing what is saved';
      pvNote.classList.toggle('dirty', dirty);
    }
  }

  function changed() {
    form.dataset.dirty = '1';
    markDirty();
    render();
  }

  // ---------- drop zones ----------

  function extensionOf(file) {
    var match = /\.([a-z0-9]+)$/i.exec(file.name || '');
    return match ? match[1].toLowerCase() : '';
  }

  function typeAllowed(file, spec) {
    var ext = extensionOf(file);
    var mimes = [];
    spec.mimes.forEach(function (m) { mimes = mimes.concat(MIMES[m] || []); });
    // Browsers report ICO under several names or none, so the extension also counts.
    return spec.mimes.indexOf(ext) !== -1 && (!file.type || mimes.indexOf(file.type) !== -1 || ext === 'ico');
  }

  function checkDimensions(url, spec) {
    return new Promise(function (resolve) {
      var img = new Image();
      img.onload = function () { resolve(img.naturalWidth <= spec.max_width && img.naturalHeight <= spec.max_height ? null : img.naturalWidth + ' × ' + img.naturalHeight); };
      img.onerror = function () { resolve(null); }; // let the server decide
      img.src = url;
    });
  }

  function setupDrop(zone) {
    var field = zone.dataset.drop;
    var input = zone.querySelector('.bx-file');
    var img = zone.querySelector('[data-drop-img]');
    var empty = zone.querySelector('.bx-drop-empty');
    var src = zone.querySelector('[data-drop-src]');
    var err = zone.querySelector('[data-drop-err]');
    var undo = zone.querySelector('[data-drop-undo]');
    var remove = zone.querySelector('[data-drop-remove]');
    var spec = config.uploads[field];
    var stored = config.current[field] || null;

    function paint() {
      var url = picked[field] || (removed[field] ? null : stored);
      var shown = url || zone.dataset.fallback || null;
      if (shown) img.src = shown;
      show(img, !!shown);
      show(empty, !shown);
      src.className = 'bx-src';
      if (picked[field]) { src.textContent = 'New, not saved yet'; src.classList.add('new'); }
      else if (removed[field]) { src.textContent = 'Removed on save'; src.classList.add('gone'); }
      else if (stored) { src.textContent = 'Set for this page'; src.classList.add('set'); }
      else { src.textContent = 'Pharos default'; }
      show(undo, !!picked[field]);
    }

    function fail(message) {
      err.textContent = message;
      show(err, true);
      zone.setAttribute('data-invalid', '');
    }

    function clearPick() {
      if (picked[field]) URL.revokeObjectURL(picked[field]);
      delete picked[field];
      err.textContent = '';
      show(err, false);
      zone.removeAttribute('data-invalid');
    }

    function accept(file) {
      clearPick();
      if (!file) { paint(); render(); return; }
      if (!typeAllowed(file, spec)) {
        input.value = '';
        fail('That file type is not accepted here. ' + zone.querySelector('.help').textContent);
      } else if (file.size > spec.max_kb * 1024) {
        input.value = '';
        fail('That file is ' + Math.ceil(file.size / 1024) + ' KB; the limit is ' + spec.max_kb + ' KB.');
      } else {
        var url = URL.createObjectURL(file);
        picked[field] = url;
        if (remove) { remove.checked = false; delete removed[field]; }
        checkDimensions(url, spec).then(function (tooBig) {
          if (!tooBig || picked[field] !== url) return;
          clearPick();
          input.value = '';
          fail('That image is ' + tooBig + ' pixels; the limit is ' + spec.max_width + ' × ' + spec.max_height + '.');
          paint();
          render();
        });
      }
      paint();
      changed();
    }

    input.addEventListener('change', function () { accept(input.files && input.files[0]); });

    if (undo) undo.addEventListener('click', function () {
      input.value = '';
      clearPick();
      paint();
      changed();
      input.focus();
    });

    if (remove) remove.addEventListener('change', function () {
      if (remove.checked) {
        input.value = '';
        clearPick();
        removed[field] = true;
      } else {
        delete removed[field];
      }
      paint();
      changed();
    });

    ['dragenter', 'dragover'].forEach(function (type) {
      zone.addEventListener(type, function (event) {
        event.preventDefault();
        zone.classList.add('over');
      });
    });
    ['dragleave', 'drop'].forEach(function (type) {
      zone.addEventListener(type, function (event) {
        if (type === 'dragleave' && zone.contains(event.relatedTarget)) return;
        zone.classList.remove('over');
      });
    });
    zone.addEventListener('drop', function (event) {
      event.preventDefault();
      var files = event.dataTransfer && event.dataTransfer.files;
      if (!files || !files.length) return;
      try {
        var transfer = new DataTransfer();
        transfer.items.add(files[0]);
        input.files = transfer.files; // so Save sends exactly what is shown
      } catch (e) {
        fail('Dropping is not supported in this browser. Use Choose file instead.');
        return;
      }
      accept(files[0]);
    });

    zone.resetPick = function () { clearPick(); delete removed[field]; paint(); };
    paint();
  }

  var zones = document.querySelectorAll('[data-drop]');
  Array.prototype.forEach.call(zones, setupDrop);

  form.addEventListener('input', changed);
  form.addEventListener('change', function (event) {
    if (!event.target.classList.contains('bx-file')) changed();
  });

  // Undo restores the inputs but fires no input event; the preview must follow.
  form.addEventListener('reset', function () {
    setTimeout(function () {
      Array.prototype.forEach.call(zones, function (zone) { zone.resetPick(); });
      form.dataset.dirty = '0';
      markDirty();
      render();
    }, 0);
  });

  // A picked file would be lost by leaving; say so before it happens.
  var submitting = false;
  form.addEventListener('submit', function () { submitting = true; });
  window.addEventListener('beforeunload', function (event) {
    if (form.dataset.dirty === '1' && !submitting) {
      event.preventDefault();
      event.returnValue = '';
    }
  });

  render();
})();
