@extends('layouts.admin')
@section('title', 'Status page')
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
.viewport-bar .live .dot{width:7px;height:7px;border-radius:50%;background:var(--green);transition:opacity .2s}
.viewport-bar .live.busy .dot{opacity:.25}
.widths{margin-left:auto;display:flex;border:1px solid var(--line);border-radius:9px;overflow:hidden;background:var(--card)}
.widths button{padding:5px 11px;font-size:11.5px;font-weight:600;color:var(--ink-3);border-right:1px solid var(--line)}
.widths button:last-child{border-right:0}
.widths button[aria-pressed="true"]{background:var(--brand-soft);color:var(--brand)}
.stage{background:var(--bg-tint);padding:14px;display:flex;justify-content:center}
.stage iframe{width:100%;max-width:100%;height:640px;border:0;border-radius:10px;background:var(--bg);
  box-shadow:0 1px 3px #10182818;transition:max-width .25s var(--ease)}
.stage.phone iframe{max-width:390px}
.hint-row{padding:12px 16px;border-top:1px solid var(--line);font-size:12.5px;color:var(--ink-3);line-height:1.6}
</style>

@include('partials.pagehead', [
  'title' => 'Status page',
  'sub' => 'What the status page shows, and how it looks',
])

<div class="split">
  <div>
    <form method="POST" action="{{ route('admin.status-page.update') }}" id="settings-form">
      @csrf @method('PUT')

      <div class="panel">
        <div class="panel-hd"><h3>Sections</h3><span class="hint">The preview follows as you tick</span></div>
        <div class="panel-bd">
          <div class="modules" style="grid-template-columns:1fr">
            @foreach ($modules as $key => $module)
              <label class="switchrow" style="cursor:pointer">
                <span class="t">
                  <strong>{{ $module['label'] }}</strong>
                  <span class="s">{{ $module['help'] }}</span>
                </span>
                <span class="check">
                  <input type="checkbox" name="modules[{{ $key }}]" value="1" @checked($enabled[$key])>
                </span>
              </label>
            @endforeach
          </div>
        </div>
      </div>

      <div class="panel">
        <div class="panel-hd">
          <h3>Services</h3>
          <span class="hint"><a href="{{ route('admin.groups', ['from' => 'status-page']) }}">Rename or add</a></span>
        </div>
        <div class="panel-bd">
          @forelse ($groups as $group)
            <label class="switchrow" style="cursor:pointer">
              <span class="t">
                <strong>{{ $group->name }}</strong>
                <span class="s">{{ $group->components_count }} {{ \Illuminate\Support\Str::plural('component', $group->components_count) }}</span>
              </span>
              <span class="check">
                <input type="checkbox" name="groups[{{ $group->id }}]" value="1"
                       data-group="{{ $group->id }}" @checked($group->visible)>
              </span>
            </label>
          @empty
            <div class="callout">
              No services yet. <a href="{{ route('admin.groups', ['from' => 'status-page']) }}">Add one</a> and it appears here
              as its own switch.
            </div>
          @endforelse
        </div>
      </div>

      <div class="panel">
        <div class="panel-hd"><h3>Appearance and history</h3></div>
        <div class="panel-bd">
          <div class="fields">
            <div class="field">
              <label for="theme">Default theme</label>
              <select id="theme" name="theme">
                <option value="system" @selected($theme === 'system')>Follow the visitor's device</option>
                <option value="light" @selected($theme === 'light')>Always light</option>
                <option value="dark" @selected($theme === 'dark')>Always dark</option>
              </select>
              <span class="help">A visitor can still switch; their choice is remembered in their browser.</span>
            </div>
            <div class="field">
              <label for="incident_days">Days of incident history</label>
              <input id="incident_days" name="incident_days" type="number" min="1" max="30" value="{{ $incidentDays }}">
              <span class="help">How far back the page lists days. Older incidents stay in the archive.</span>
            </div>
          </div>
          <div class="actions">
            <button class="btn" type="submit">Save status page</button>
            <button class="btn ghost" type="reset" id="reset-settings">Undo my changes</button>
          </div>
        </div>
      </div>
    </form>
  </div>

  <div class="preview-col">
    <div class="viewport">
      <div class="viewport-bar">
        <span class="dots" aria-hidden="true"><i></i><i></i><i></i></span>
        <span class="live" id="live"><span class="dot"></span> Live preview</span>
        <span class="widths" role="group" aria-label="Preview width">
          <button type="button" data-width="full" aria-pressed="true">Desktop</button>
          <button type="button" data-width="phone" aria-pressed="false">Phone</button>
        </span>
      </div>
      <div class="stage" id="stage">
        <iframe id="preview" title="Preview of the status page" loading="lazy"></iframe>
      </div>
      <p class="hint-row">
        This is the real page rendered from the values above, not a mock-up.
        Nothing is saved until you press <b>Save status page</b>.
      </p>
    </div>
  </div>
</div>

<script>
(function () {
  var form = document.getElementById('settings-form');
  var frame = document.getElementById('preview');
  var live = document.getElementById('live');
  var stage = document.getElementById('stage');
  var base = @json(route('admin.status-page.preview'), JSON_UNESCAPED_SLASHES);
  var timer = null;

  function url() {
    var params = new URLSearchParams();
    form.querySelectorAll('input[type=checkbox][name^="modules["]').forEach(function (box) {
      var key = box.name.slice('modules['.length, -1);
      params.set('m[' + key + ']', box.checked ? '1' : '0');
    });
    form.querySelectorAll('input[type=checkbox][data-group]').forEach(function (box) {
      params.set('g[' + box.dataset.group + ']', box.checked ? '1' : '0');
    });
    params.set('theme', form.querySelector('#theme').value);
    params.set('days', form.querySelector('#incident_days').value || '5');
    return base + '?' + params.toString();
  }

  function refresh() {
    live.classList.add('busy');
    frame.src = url();
  }

  frame.addEventListener('load', function () { live.classList.remove('busy'); });

  // Debounced: typing a number should not fire a render per keystroke.
  form.addEventListener('input', function () {
    clearTimeout(timer);
    timer = setTimeout(refresh, 250);
  });

  // A reset restores the inputs but fires no input event, so the preview would
  // keep showing the abandoned state.
  form.addEventListener('reset', function () {
    setTimeout(refresh, 0);
  });

  document.querySelectorAll('.widths button').forEach(function (button) {
    button.addEventListener('click', function () {
      document.querySelectorAll('.widths button').forEach(function (other) {
        other.setAttribute('aria-pressed', String(other === button));
      });
      stage.classList.toggle('phone', button.dataset.width === 'phone');
    });
  });

  refresh();
})();
</script>
@endsection
