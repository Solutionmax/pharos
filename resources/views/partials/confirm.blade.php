{{-- One confirmation for the whole admin, in two shapes:

     · any form carrying data-confirm is intercepted before it submits
     · window.pharosConfirm({...}) returns a promise, for the things that are
       not forms — chips deleted over fetch, mostly

     Cancel holds focus on open: the safe answer should be the one you get by
     pressing Enter without reading. --}}
<dialog class="modal" id="confirm-dialog" aria-labelledby="confirm-title">
  <div class="panel">
    <div class="panel-hd"><h3 id="confirm-title">Are you sure?</h3></div>
    <div class="panel-bd">
      <p class="modal-say" id="confirm-say"></p>
      <div class="modal-act">
        <button type="button" class="btn ghost" id="confirm-no" autofocus>Cancel</button>
        <button type="button" class="btn danger" id="confirm-yes">Delete</button>
      </div>
    </div>
  </div>
</dialog>

<script>
window.pharosConfirm = (function () {
  var dialog = document.getElementById('confirm-dialog');
  var title  = document.getElementById('confirm-title');
  var say    = document.getElementById('confirm-say');
  var yes    = document.getElementById('confirm-yes');
  var no     = document.getElementById('confirm-no');
  var settle = null;

  function open(options) {
    title.textContent = options.title || 'Are you sure?';
    say.innerHTML = options.body || '';
    yes.textContent = options.action || 'Delete';
    yes.className = options.safe ? 'btn' : 'btn danger';
    dialog.showModal();

    return new Promise(function (resolve) { settle = resolve; });
  }

  function answer(agreed) {
    var done = settle;
    settle = null;
    dialog.close();
    if (done) { done(agreed); }
  }

  yes.addEventListener('click', function () { answer(true); });
  no.addEventListener('click', function () { answer(false); });
  // Escape and the backdrop close it too, and both mean no.
  dialog.addEventListener('close', function () { answer(false); });

  document.addEventListener('submit', function (event) {
    var form = event.target.closest('form[data-confirm]');

    // The second pass, after the dialog said yes, must go through untouched.
    if (!form || form.dataset.confirmed === 'yes') { return; }

    event.preventDefault();

    open({
      title: form.dataset.confirmTitle,
      body: form.dataset.confirm,
      action: form.dataset.confirmAction,
      safe: form.dataset.confirmSafe,
    }).then(function (agreed) {
      if (!agreed) { return; }

      form.dataset.confirmed = 'yes';
      // requestSubmit, not submit: it keeps native validation and fires the
      // event the guard above now lets through.
      form.requestSubmit();
    });
  });

  return open;
})();
</script>
