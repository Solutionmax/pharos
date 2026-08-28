@extends('layouts.admin')
@section('title', 'Mail templates')
@section('content')
<style>
.split{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:16px;align-items:start}
@media(max-width:1180px){.split{grid-template-columns:1fr}}
.preview-col{position:sticky;top:26px}
@media(max-width:1180px){.preview-col{position:static}}
.viewport{border:1px solid var(--line);border-radius:var(--radius);overflow:hidden;background:var(--card);box-shadow:var(--shadow-sm)}
.viewport-bar{display:flex;align-items:center;gap:10px;padding:10px 14px;background:var(--bg-tint);border-bottom:1px solid var(--line);flex-wrap:wrap}
.viewport-bar .dots{display:flex;gap:5px}
.viewport-bar .dots i{width:9px;height:9px;border-radius:50%;background:var(--line);display:block}
.viewport-bar .live{display:flex;align-items:center;gap:7px;font-family:var(--mono);font-size:10px;letter-spacing:.11em;text-transform:uppercase;color:var(--ink-3)}
.viewport-bar .live .dot{width:7px;height:7px;border-radius:50%;background:currentColor;color:var(--green);transition:opacity .2s}
.viewport-bar .live.busy .dot{opacity:.25}
.subject-line{display:flex;gap:10px;align-items:baseline;padding:10px 14px;border-bottom:1px solid var(--line);font-size:13.5px;min-width:0}
.subject-line .k{font-family:var(--mono);font-size:10px;letter-spacing:.11em;text-transform:uppercase;color:var(--ink-3);flex:none}
.subject-line .v{font-weight:600;overflow-wrap:anywhere}
.stage{background:var(--bg-tint);padding:14px;display:flex;justify-content:center}
.stage iframe{width:100%;height:640px;border:0;border-radius:10px;background:#f2f6fb;box-shadow:0 1px 3px #10182818}
.hint-row{padding:12px 16px;border-top:1px solid var(--line);font-size:12.5px;color:var(--ink-3);line-height:1.6}
.chips{display:flex;flex-wrap:wrap;gap:6px;margin:0 0 8px}
.chip{font-family:var(--mono);font-size:11.5px;padding:3px 8px;border:1px solid var(--line);border-radius:6px;background:var(--bg-tint);color:var(--ink-2);cursor:pointer;line-height:1.4}
.chip:hover:not(:disabled){border-color:var(--brand);color:var(--brand)}
.chip:disabled{cursor:default;opacity:.6}
textarea.body{font-family:var(--mono);font-size:13px;line-height:1.55;tab-size:2}
</style>

@include('partials.pagehead', [
  'title' => 'Mail templates',
  'sub' => 'What subscribers receive',
])

<nav class="seg tabs" aria-label="Template">
  @foreach ($labels as $tabKey => $label)
    <a href="{{ route('admin.mail-templates', ['template' => $tabKey]) }}" @if ($tabKey === $key) aria-current="page" @endif>{{ $label }}</a>
  @endforeach
</nav>

<div class="split">
  <div>
    <form method="POST" action="{{ route('admin.mail-templates.update') }}" id="template-form">
      @csrf @method('PUT')
      <input type="hidden" name="template" value="{{ $key }}">

      <div class="panel">
        <div class="panel-hd">
          <h3>{{ $labels[$key] }}</h3>
          @if ($licensed)
            <span class="hint">{{ $isDefault ? 'Default wording' : 'Your wording' }}</span>
          @else
            <span class="pro">Brand pack</span>
          @endif
        </div>
        <div class="panel-bd">
          <div class="fields">
            <div class="field">
              <label for="subject">Subject</label>
              <input id="subject" name="subject" type="text" value="{{ old('subject', $subject) }}" required maxlength="200" @disabled(! $licensed)>
            </div>
            <div class="field">
              <label for="body">Body</label>
              <div class="chips" aria-label="Tags: click to insert">
                @foreach ($tags as $tag)
                  <button type="button" class="chip" data-tag="{{ $tag }}" title="Insert {{ $tag }}" @disabled(! $licensed)>{{ $tag }}</button>
                @endforeach
              </div>
              <textarea id="body" name="body" class="body" rows="14" required maxlength="20000" @disabled(! $licensed)>{{ old('body', $body) }}</textarea>
              <span class="help">Markdown: <code>**bold**</code>, <code>*italic*</code>, <code># heading</code>, <code>- list</code>, <code>[text](url)</code>. A link on a line of its own becomes a button.</span>
            </div>
          </div>

          @if ($licensed)
            <div class="actions">
              <button class="btn" type="submit">Save</button>
              <button class="btn ghost" type="reset">Undo my changes</button>
              <button class="btn ghost" type="submit" form="test-form">Send test to me</button>
              <button class="btn ghost" type="submit" form="reset-form" @disabled($isDefault) title="{{ $isDefault ? 'This template is the default already' : 'Back to the built-in wording' }}">Reset to default</button>
            </div>
          @else
            <div class="locked" style="margin-top:16px">
              <div>
                <strong style="font-size:13.5px">Your own wording</strong>
                <p class="sub" style="margin-top:4px;font-size:13px;color:var(--ink-3)">
                  This is what your subscribers receive today. Rewriting the subject and the body,
                  in your own words and your own language, is part of the brand pack.
                </p>
              </div>
              <a class="btn" href="{{ config('pharos.buy_url') }}" target="_blank" rel="noopener">Buy the brand pack</a>
            </div>
          @endif
        </div>
      </div>
    </form>

    @if ($licensed)
      {{-- Two small forms beside the main one, so Save stays a plain PUT. The
           test form carries the unsaved wording: the script copies it in on submit. --}}
      <form method="POST" action="{{ route('admin.mail-templates.test') }}" id="test-form">
        @csrf
        <input type="hidden" name="template" value="{{ $key }}">
        <input type="hidden" name="subject" value="{{ old('subject', $subject) }}">
        <input type="hidden" name="body" value="{{ old('body', $body) }}">
      </form>
      <form method="POST" action="{{ route('admin.mail-templates.reset') }}" id="reset-form"
            data-confirm-title="Reset {{ strtolower($label ?? 'this template') }} to the default?"
            data-confirm="Your wording for this template is thrown away and the built-in text comes back. The other templates are untouched."
            data-confirm-action="Reset to default">
        @csrf
        <input type="hidden" name="template" value="{{ $key }}">
      </form>
    @endif

    <x-note id="mail-templates.frame">
      <b>You edit the body, not the frame.</b> The logo, the accent colour, the link to the status
      page and — on every incident mail — the unsubscribe link sit in the frame around it and are
      always there, whether or not you use <code>{unsubscribe}</code> in the body.
      Tag values are printed as typed; only <code>{message}</code> is the operator's Markdown.
      A line whose only tag is empty is left out, so <code>Affects {components}</code> disappears
      when no component is affected.
    </x-note>
  </div>

  <div class="preview-col">
    <div class="viewport">
      <div class="viewport-bar">
        <span class="dots" aria-hidden="true"><i></i><i></i><i></i></span>
        <span class="live" id="live"><span class="dot"></span> Live preview</span>
      </div>
      <div class="subject-line"><span class="k">Subject</span><span class="v" id="preview-subject">{{ $previewSubject }}</span></div>
      <div class="stage">
        <iframe id="preview" title="Preview of the mail" src="{{ route('admin.mail-templates.preview', ['template' => $key]) }}"></iframe>
      </div>
      <p class="hint-row">
        Rendered from the wording on the left with a sample incident, in the real frame.
        Nothing is saved until you press <b>Save</b>.
      </p>
    </div>
  </div>
</div>

<script>
(function () {
  var form = document.getElementById('template-form');
  var frame = document.getElementById('preview');
  var live = document.getElementById('live');
  var subjectOut = document.getElementById('preview-subject');
  var url = @json(route('admin.mail-templates.preview'), JSON_UNESCAPED_SLASHES);
  var token = document.querySelector('meta[name=csrf-token]').content;
  var key = @json($key);
  var timer = null;

  function field(name) { return form.elements[name]; }

  // The unsaved wording goes up as a POST; the reply is written into the frame.
  function refresh() {
    live.classList.add('busy');
    var data = new FormData();
    data.set('_token', token);
    data.set('template', key);
    data.set('subject', field('subject').value);
    data.set('body', field('body').value);
    fetch(url, { method: 'POST', body: data, headers: { Accept: 'application/json' }, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (r) { frame.srcdoc = r.html; subjectOut.textContent = r.subject; })
      .catch(function () {})
      .then(function () { live.classList.remove('busy'); });
  }

  // Debounced: a render per keystroke would be a request per keystroke.
  form.addEventListener('input', function () {
    clearTimeout(timer);
    timer = setTimeout(refresh, 400);
  });

  // A reset restores the inputs but fires no input event, so the preview would
  // keep showing the abandoned state.
  form.addEventListener('reset', function () { setTimeout(refresh, 0); });

  document.querySelectorAll('.chip').forEach(function (chip) {
    chip.addEventListener('click', function () {
      var area = field('body');
      area.setRangeText(chip.dataset.tag, area.selectionStart, area.selectionEnd, 'end');
      area.focus();
      area.dispatchEvent(new Event('input', { bubbles: true }));
    });
  });

  var test = document.getElementById('test-form');
  if (test) {
    test.addEventListener('submit', function () {
      test.elements.subject.value = field('subject').value;
      test.elements.body.value = field('body').value;
    });
  }
})();
</script>
@endsection
