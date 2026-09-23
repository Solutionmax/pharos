/* Operations screens. Plain script, no build step: each block looks for its own
   markup and returns when the screen does not have it. */
(function () {
  const $ = (selector, root = document) => root.querySelector(selector);
  const $$ = (selector, root = document) => Array.from(root.querySelectorAll(selector));

  /* ---------- copy buttons ---------- */
  $$('[data-copy-target]').forEach((button) => button.addEventListener('click', async () => {
    const target = document.getElementById(button.dataset.copyTarget);
    if (!target) return;
    let copied = false;
    try { if (navigator.clipboard) { await navigator.clipboard.writeText(target.textContent.trim()); copied = true; } } catch (error) { copied = false; }
    if (!copied) {
      const selection = getSelection(), range = document.createRange();
      range.selectNodeContents(target); selection.removeAllRanges(); selection.addRange(range);
      try { copied = document.execCommand('copy'); } catch (error) { copied = false; }
      if (copied) selection.removeAllRanges();
    }
    const label = button.textContent;
    button.textContent = copied ? 'Copied' : 'Select and copy';
    button.classList.toggle('ok', copied);
    setTimeout(() => { button.textContent = label; button.classList.remove('ok'); }, 1400);
  }));

  /* ---------- Send out: channel tiles swap the fields and the help ---------- */
  const destination = $('#destination-form');
  const profilesNode = $('#integration-profiles');
  if (destination && profilesNode) {
    const profiles = JSON.parse(profilesNode.textContent);
    const drafts = {};
    const draftFields = ['label', 'url', 'telegram_token', 'telegram_chat_id', 'signal_number', 'signal_recipient', 'signal_token'];
    const radios = $$('input[name="format"]', destination);
    let current = (radios.find((r) => r.checked) || radios[0]).value;
    const paint = (announce) => {
      const format = (radios.find((r) => r.checked) || radios[0]).value;
      const profile = profiles[format];
      if (!profile) return;
      $('#label').placeholder = profile.name;
      const address = $('#webhook-address'), url = $('#url');
      address.hidden = format === 'telegram'; url.disabled = address.hidden; url.required = !address.hidden; url.placeholder = profile.placeholder;
      $('#destination-address-label').textContent = profile.address;
      $('#destination-address-help').textContent = profile.help;
      ['telegram', 'signal'].forEach((name) => {
        const fields = $('#' + name + '-fields');
        fields.hidden = format !== name; fields.disabled = fields.hidden;
        $$('input', fields).forEach((input) => { input.required = !fields.hidden; });
      });
      const tile = radios.find((r) => r.checked)?.closest('[data-help]');
      if (tile) $('#destination-help').textContent = tile.dataset.help;
      $('#destination-title').textContent = profile.title;
      $('#destination-summary').textContent = profile.summary;
      const steps = $('#destination-steps'); steps.replaceChildren();
      profile.steps.forEach((text) => { const li = document.createElement('li'); li.textContent = text; steps.append(li); });
      $('#destination-result').textContent = profile.result;
      $('#destination-docs').href = profile.docs;
      if (announce) $('#destination-announcement').textContent = profile.title + ' fields and instructions shown.';
    };
    radios.forEach((radio) => radio.addEventListener('change', () => {
      drafts[current] = Object.fromEntries(draftFields.map((id) => [id, document.getElementById(id).value]));
      current = radio.value;
      draftFields.forEach((id) => { document.getElementById(id).value = drafts[current]?.[id] || ''; });
      paint(true);
    }));
    paint(false);
  }

  /* ---------- Bring in: source tiles, component and status examples ---------- */
  const incoming = $('#incoming-integrations');
  if (incoming && $('#ix-src')) {
    const base = incoming.dataset.integrationBase;
    const component = $('#integration-component');
    let status = 1;
    const howto = $('#ix-howto'), texts = $('#ix-howto-texts');
    const show = (key) => {
      $$('#ix-src .ix-tile').forEach((tile) => tile.setAttribute('aria-pressed', String(tile.dataset.src === key)));
      $$('[data-for]', incoming).forEach((block) => block.classList.toggle('ix-hidden', !block.dataset.for.split(' ').includes(key)));
      const text = texts?.content.querySelector('[data-src="' + key + '"]');
      if (text && howto) howto.innerHTML = text.innerHTML;
    };
    const examples = () => {
      const id = component && /^\d+$/.test(component.value) ? component.value : 'COMPONENT_ID';
      $$('[data-component-url]', incoming).forEach((el) => { el.textContent = base + '/' + el.dataset.componentUrl + '/' + id; });
      const body = JSON.stringify({ status });
      $('#n8n-component-body').textContent = body.replace(':', ': ');
      const quoted = "'" + (base + '/components/' + id).replaceAll("'", "'\\''") + "'";
      $('#component-curl').textContent = 'curl -X PUT ' + quoted + " -H 'Authorization: Bearer YOUR_TOKEN' -H 'Content-Type: application/json' -d '" + body + "'";
      const incident = { name: 'Service unavailable', status: 'investigating', message: 'We are investigating a service interruption.', impact: 'major', components: { [id]: 'major_outage' } };
      $('#incident-example').textContent = JSON.stringify(incident, null, 4);
    };
    $$('#ix-src .ix-tile').forEach((tile) => tile.addEventListener('click', () => show(tile.dataset.src)));
    $$('#ix-status .ix-chip').forEach((chip) => chip.addEventListener('click', () => {
      $$('#ix-status .ix-chip').forEach((c) => c.setAttribute('aria-pressed', String(c === chip)));
      status = Number(chip.dataset.status); examples();
    }));
    component?.addEventListener('change', examples);
    show('n8n'); examples();
  }

  /* ---------- Components: a status picked in the row is saved at once ---------- */
  $$('form[data-autosubmit]').forEach((form) => {
    const select = $('select', form), button = $('button', form);
    if (button) button.hidden = true;
    select?.addEventListener('change', () => { form.requestSubmit ? form.requestSubmit() : form.submit(); });
  });

  /* ---------- Report an incident: templates and the live preview ---------- */
  const report = $('#incident-report');
  if (report) {
    const title = $('#name', report), message = $('#message', report), impact = $('#impact', report), visibility = $('#visibility', report);
    const preview = $('#incident-preview');
    const labels = {}; $$('input[name="status"]', report).forEach((r) => { labels[r.value] = r.closest('label').textContent.trim(); });
    const render = () => {
      const status = $('input[name="status"]:checked', report)?.value || '1';
      const affected = $$('select[data-component]', report).filter((s) => s.value !== '').map((s) => ({ name: s.dataset.component, value: Number(s.value) }));
      const worst = affected.reduce((max, c) => Math.max(max, c.value), 0);
      // The public page colours an incident by its outcome and its worst component.
      const tone = status === '4' ? 'green' : (worst >= 4 ? 'red' : 'orange');
      preview.style.setProperty('--pv', 'var(--' + tone + ')');
      const pill = $('[data-pv="status"]', preview);
      pill.style.background = 'var(--' + tone + '-soft)'; pill.style.color = 'var(--' + tone + '-ink)';
      $('[data-pv="title"]', preview).textContent = title.value.trim() || 'Your title appears here';
      $('[data-pv="status"]', preview).textContent = labels[status] || '';
      $('[data-pv="status-line"]', preview).textContent = labels[status] || '';
      $('[data-pv="message"]', preview).textContent = message.value.trim() || 'What you know, what you are doing, and when you will post again.';
      const aff = $('[data-pv="affects"]', preview);
      aff.hidden = affected.length === 0;
      $('b', aff).textContent = affected.map((c) => c.name).join(', ');
      $('[data-pv="impact"]', preview).textContent = impact.options[impact.selectedIndex].text + ' impact';
      const hidden = visibility.value !== 'public';
      $('[data-pv="private"]', preview).style.display = hidden ? 'flex' : 'none';
      $('.frame', preview).style.opacity = hidden ? '.55' : '1';
    };
    report.addEventListener('input', render);
    report.addEventListener('change', render);
    // The editor script mirrors its content into the textarea; poll lightly so the preview follows.
    setInterval(() => { if (message.value !== message.dataset.seen) { message.dataset.seen = message.value; render(); } }, 700);
    $$('[data-template]').forEach((button) => button.addEventListener('click', () => {
      const data = JSON.parse(button.dataset.template);
      if (title.value.trim() && !confirm('Replace the title and message with this template?')) return;
      title.value = data.title; message.value = data.body;
      message.dispatchEvent(new Event('input', { bubbles: true }));
      $$('[data-template]').forEach((b) => b.setAttribute('aria-pressed', String(b === button)));
      render();
    }));
    render();
  }
})();
