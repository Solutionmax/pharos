{{--
  A formatting bar over a plain textarea. Deliberately not a WYSIWYG editor:
  the same message is delivered to Slack, Teams and generic receivers, where
  HTML would read as literal tags. Markdown degrades to readable plain text
  everywhere and renders properly on the status page.

  Usage: @include('partials.editor', ['for' => 'message'])
--}}
<div class="mdbar" data-for="{{ $for }}" hidden>
  <button type="button" data-wrap="**" title="Bold"><b>B</b></button>
  <button type="button" data-wrap="_" title="Italic"><i>I</i></button>
  <button type="button" data-wrap="`" title="Code" class="mono">&lt;/&gt;</button>
  <button type="button" data-line="- " title="Bulleted list">&bull; List</button>
  <button type="button" data-link="1" title="Link">Link</button>
  <span class="mdbar-hint">Markdown</span>
</div>

@once
<script>
// Progressive enhancement: the bar ships hidden and only appears once this
// runs, so a browser without JS shows a plain textarea instead of dead buttons.
(function () {
  function surround(ta, before, after) {
    var s = ta.selectionStart, e = ta.selectionEnd, v = ta.value;
    ta.value = v.slice(0, s) + before + v.slice(s, e) + after + v.slice(e);
    ta.focus();
    // Empty selection: put the caret between the markers so typing continues
    // inside them. With a selection, keep it selected so it can be re-styled.
    ta.selectionStart = s + before.length;
    ta.selectionEnd = s + before.length + (e - s);
  }

  function prefixLines(ta, prefix) {
    var s = ta.selectionStart, e = ta.selectionEnd, v = ta.value;
    var from = v.lastIndexOf('\n', s - 1) + 1;
    var to = v.indexOf('\n', e); if (to === -1) to = v.length;
    var block = v.slice(from, to).split('\n').map(function (l) {
      return l.startsWith(prefix) ? l.slice(prefix.length) : prefix + l;
    }).join('\n');
    ta.value = v.slice(0, from) + block + v.slice(to);
    ta.focus();
    ta.selectionStart = from; ta.selectionEnd = from + block.length;
  }

  document.querySelectorAll('.mdbar').forEach(function (bar) {
    var ta = document.getElementById(bar.dataset.for);
    if (!ta) return;
    bar.hidden = false;
    bar.addEventListener('click', function (ev) {
      var b = ev.target.closest('button'); if (!b) return;
      if (b.dataset.wrap) surround(ta, b.dataset.wrap, b.dataset.wrap);
      else if (b.dataset.line) prefixLines(ta, b.dataset.line);
      else if (b.dataset.link) {
        var url = prompt('Link to which address?', 'https://');
        if (url) surround(ta, '[', '](' + url + ')');
      }
    });
  });
})();
</script>
@endonce
