// Progressive enhancement: the validated native month field works without JavaScript.
document.querySelectorAll('[data-month-field]').forEach(function (field) {
  const input = field.querySelector('input[type=month]');
  const picker = field.querySelector('[data-month-picker]');
  const summary = picker.querySelector('summary');
  const year = picker.querySelector('[data-month-year]');
  const months = [...picker.querySelectorAll('[data-month]')];
  const firstYear = Number(input.min.slice(0, 4));
  const lastYear = Number(input.max.slice(0, 4));
  function paint() {
    months.forEach(function (button) {
      const value = year.value + '-' + button.dataset.month;
      button.disabled = value < input.min || value > input.max;
      button.setAttribute('aria-pressed', String(value === input.value));
      button.setAttribute('aria-label', button.dataset.monthName + ' ' + year.value);
    });
    picker.querySelector('[data-year-step="-1"]').disabled = Number(year.value) <= firstYear;
    picker.querySelector('[data-year-step="1"]').disabled = Number(year.value) >= lastYear;
  }
  function close() { picker.open = false; }
  year.addEventListener('change', paint);
  picker.querySelectorAll('[data-year-step]').forEach(function (button) {
    button.addEventListener('click', function () {
      year.value = String(Math.max(firstYear, Math.min(lastYear, Number(year.value) + Number(button.dataset.yearStep))));
      paint();
    });
  });
  months.forEach(function (button) {
    button.addEventListener('click', function () {
      input.value = year.value + '-' + button.dataset.month;
      picker.querySelector('[data-month-label]').textContent = button.dataset.monthName + ' ' + year.value;
      input.dispatchEvent(new Event('change', {bubbles: true}));
      paint();
      close();
      summary.focus();
    });
  });
  picker.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') { event.preventDefault(); close(); summary.focus(); }
    const index = months.indexOf(event.target);
    const shift = {ArrowLeft: -1, ArrowRight: 1, ArrowUp: -3, ArrowDown: 3}[event.key];
    if (index >= 0 && shift) {
      event.preventDefault();
      const next = months[index + shift];
      if (next && !next.disabled) next.focus();
    }
  });
  document.addEventListener('click', function (event) { if (!field.contains(event.target)) close(); });
  field.addEventListener('focusout', function (event) { if (!field.contains(event.relatedTarget)) close(); });
  field.querySelector('label').htmlFor = '';
  field.querySelector('label').addEventListener('click', function () { summary.focus(); picker.open = true; });
  paint();
  input.hidden = true;
  picker.hidden = false;
});
