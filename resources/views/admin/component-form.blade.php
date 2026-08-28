@extends('layouts.admin')
@section('title', $component->exists ? 'Edit component' : 'Add a component')
@section('content')
@php $check = $component->check; @endphp
@include('partials.pagehead', [
  'title' => $component->exists ? 'Edit '.$component->name : 'Add a component',
  'back' => ['url' => route('admin.components'), 'label' => 'Components'],
])

<form method="POST" action="{{ $component->exists ? route('admin.components.update', $component) : route('admin.components.store') }}">
  @csrf
  @if ($component->exists) @method('PUT') @endif

  <div class="panel">
    <div class="panel-hd"><h3>What it is</h3></div>
    <div class="panel-bd">
      <div class="fields">
        <div class="field">
          <span class="lblrow"><label for="name">Name</label>
            @include('partials.tip', ['text' => 'The name shown on the public page and in this list. Use whatever your customers will recognise — a service name or a server name, whichever they know.'])</span>
          <input id="name" name="name" type="text" value="{{ old('name', $component->name) }}" required placeholder="Website">
        </div>
        <div class="field">
          <span class="lblrow"><label for="status">Status</label>
            @include('partials.tip', ['text' => 'The status shown right now. A built-in check overwrites this on its next run — except Under maintenance, which stays until you clear it. With Manual only it stays exactly as you set it.'])</span>
          <select id="status" name="status">
            @foreach (\App\Enums\ComponentStatus::cases() as $case)
              <option value="{{ $case->value }}" @selected(old('status', $component->status?->value ?? 1) == $case->value)>{{ $case->label() }}</option>
            @endforeach
          </select>
        </div>
        <div class="field">
          <span class="lblrow"><label for="component_group_id">Group</label>
            @include('partials.tip', ['text' => 'The service this appears under on the public page. Ungrouped is published too, as a row of its own without a heading.'])</span>

          {{-- A select is the right control once there are a lot of services; up
               to a handful, chips show every option at once and take one click. --}}
          @if ($groups->count() > 10)
            <select id="component_group_id" name="component_group_id">
              <option value="">Ungrouped</option>
              @foreach ($groups as $group)
                <option value="{{ $group->id }}" @selected(old('component_group_id', $component->component_group_id) == $group->id)>{{ $group->name }}</option>
              @endforeach
            </select>
            <button type="button" class="linkbtn" data-new-service>+ New service</button>
          @else
            <input type="hidden" id="component_group_id" name="component_group_id"
                   value="{{ old('component_group_id', $component->component_group_id) }}">
            <span class="chips" data-group-picker>
              <span class="chip" data-value="" data-on="false">
                <button type="button" class="pick">Ungrouped</button>
              </span>
              @foreach ($groups as $group)
                <span class="chip" data-value="{{ $group->id }}" data-on="false">
                  <button type="button" class="pick">{{ $group->name }}</button>
                  <button type="button" class="drop" data-drop-service="{{ $group->id }}"
                          data-count="{{ $group->components()->count() }}"
                          aria-label="Delete the service {{ $group->name }}">&times;</button>
                </span>
              @endforeach
              <button type="button" class="add" data-new-service>+ New service</button>
            </span>
            <span class="help">Click a service to put this component in it. The &times; deletes the service itself.</span>
          @endif
        </div>
      </div>

      <div class="field wide">
        <span class="lblrow"><label for="description">Description</label>
          @include('partials.tip', ['text' => 'One sentence under the name on the public page. Write it for the customer reading it during an outage.'])</span>
        <input id="description" name="description" type="text" value="{{ old('description', $component->description) }}"
               placeholder="Availability of your website">
        <span class="help">Shown under the name on the status page. Keep it in customer language.</span>
      </div>

      <div class="fields">
        <div class="field">
          <span class="lblrow"><label for="link">Link</label>
            @include('partials.tip', ['text' => 'Optional. Turns the name on the public page into a link that opens in a new tab. http and https only.'])</span>
          <input id="link" name="link" type="url" value="{{ old('link', $component->link) }}" placeholder="https://example.net">
        </div>
        <div class="field">
          <span class="lblrow"><label for="tags">Tags</label>
            @include('partials.tip', ['text' => 'Only returned by the API, for scripts written against Cachet. Nothing inside Pharos reads them.'])</span>
          <input id="tags" name="tags" type="text" value="{{ old('tags', $component->tags) }}" placeholder="shared, nl-1, cpanel">
          <span class="help">Comma separated.</span>
          @if ($knownTags !== [])
            <span class="chips" data-tag-picker>
              @foreach ($knownTags as $tag)
                <span class="chip" data-value="{{ $tag }}" data-on="false">
                  <button type="button" class="pick">{{ $tag }}</button>
                  <button type="button" class="drop" data-drop-tag="{{ $tag }}"
                          aria-label="Remove the tag {{ $tag }} from every component">&times;</button>
                </span>
              @endforeach
            </span>
            <span class="help">Click a tag to put it on this component. The &times; removes it from every component.</span>
          @endif
        </div>
      </div>
    </div>
  </div>

  <div class="panel">
    <div class="panel-hd"><h3>How it is checked</h3><span class="hint">Leave on Manual only to set the status by hand</span></div>
    <div class="panel-bd">
      <div class="fields">
        <div class="field">
          <span class="lblrow"><label for="source">Source</label>
            @include('partials.tip', ['text' => 'Who sets this status. Built-in check and Heartbeat make Pharos do it; every other option waits for something outside to write it through the API.'])</span>
          <select id="source" name="source">
            @foreach (['manual' => 'Manual only', 'check' => 'Built-in check', 'kuma' => 'Uptime Kuma', 'webhook' => 'Webhook / API', 'heartbeat' => 'Heartbeat', 'upstream' => 'Upstream provider'] as $value => $label)
              <option value="{{ $value }}" @selected(old('source', $component->source) === $value)>{{ $label }}</option>
            @endforeach
          </select>
        </div>
        <div class="field">
          <span class="lblrow"><label for="check_type">Check</label>
            @include('partials.tip', ['text' => 'HTTP GET fetches the URL and counts 200 through 399 as up. TCP port only opens a socket — for mail, databases, anything without a web page.'])</span>
          <select id="check_type" name="check_type">
            <option value="http" @selected(old('check_type', $check?->type->value) === 'http')>HTTP GET</option>
            <option value="tcp" @selected(old('check_type', $check?->type->value) === 'tcp')>TCP port</option>
          </select>
        </div>
        <div class="field">
          <span class="lblrow"><label for="check_interval">Interval (seconds)</label>
            @include('partials.tip', ['text' => 'How often to look, minimum 30 seconds. Two failures in a row turn it red and open an incident; the first success turns it green, and three in a row close the incident.'])</span>
          <input id="check_interval" name="check_interval" type="number" min="30" max="86400"
                 value="{{ old('check_interval', $check?->interval_seconds ?? 60) }}">
        </div>
      </div>

      <div class="field wide">
        <span class="lblrow"><label for="check_target">Target</label>
          @include('partials.tip', ['text' => 'What to contact: a full URL for HTTP, host:port for TCP. Only used by Built-in check — Heartbeat generates its own address, and the other sources ignore this.'])</span>
        <input id="check_target" name="check_target" type="text" value="{{ old('check_target', $check?->type?->value === 'heartbeat' ? '' : $check?->target) }}"
               placeholder="https://example.net/ or mail.example.net:993">
        <span class="help">A URL for HTTP, host:port for TCP. Leave empty for the other sources.</span>
      </div>

      @if ($check && $check->type === \App\Enums\CheckType::Heartbeat)
        <x-note id="component.heartbeat-url">
          <b>Heartbeat URL.</b> Have the job call this when it finishes. Silence for two intervals is the alarm.
          <div class="mono" style="margin-top:8px;word-break:break-all">{{ url("/api/v1/heartbeat/{$check->target}") }}</div>
        </x-note>
      @endif

      <div class="switchrow">
        <span class="t"><strong>Component enabled</strong><span class="s">Disabled components keep their history but are not checked and not shown.</span></span>
        <label class="check"><input type="checkbox" name="enabled" value="1" @checked(old('enabled', $component->exists ? $component->enabled : true))> Enabled</label>
      </div>
      <div class="switchrow">
        <span class="t"><strong>Show the uptime bar</strong><span class="s">Turn this off where a percentage would confuse more than help.</span></span>
        <label class="check"><input type="checkbox" name="show_uptime" value="1" @checked(old('show_uptime', $component->exists ? $component->show_uptime : true))> Show</label>
      </div>

      <div class="actions">
        <button class="btn" type="submit">{{ $component->exists ? 'Save component' : 'Add component' }}</button>
        <a class="btn ghost" href="{{ route('admin.components') }}">Cancel</a>
      </div>
    </div>
  </div>
</form>

{{-- Outside the component form on purpose: a <form> inside a <form> is invalid
     HTML, and the browser drops one of them. --}}
<dialog class="modal" id="service-dialog" aria-labelledby="service-dialog-title">
  <div class="panel">
    <div class="panel-hd"><h3 id="service-dialog-title">New service</h3></div>
    <div class="panel-bd">
      <div class="field">
        <label for="service-name">Name</label>
        <input id="service-name" type="text" maxlength="80" placeholder="Shared hosting">
        <span class="help">What a customer would call it. You can rename it later under Services.</span>
      </div>
      <label class="check"><input id="service-visible" type="checkbox" checked> Show on the status page</label>
      <span class="modal-err" id="service-error" role="alert"></span>
      <div class="modal-act">
        <button type="button" class="btn" id="service-save">Add service</button>
        <button type="button" class="btn ghost" id="service-cancel">Cancel</button>
      </div>
    </div>
  </div>
</dialog>

<script>
(function () {
  var CSRF = document.querySelector('meta[name=csrf-token]').content;

  function send(url, method) {
    return fetch(url, {
      method: method,
      headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF },
    });
  }

  // ---- group -----------------------------------------------------------
  var groupPicker = document.querySelector('[data-group-picker]');
  var groupField  = document.getElementById('component_group_id');

  function paintGroups() {
    groupPicker.querySelectorAll('.chip').forEach(function (chip) {
      chip.dataset.on = chip.dataset.value === (groupField.value || '') ? 'true' : 'false';
    });
  }

  if (groupPicker) {
    paintGroups();

    groupPicker.addEventListener('click', function (event) {
      var pick = event.target.closest('.pick');
      if (pick) {
        groupField.value = pick.closest('.chip').dataset.value;
        return paintGroups();
      }

      var drop = event.target.closest('[data-drop-service]');
      if (!drop) { return; }

      var chip = drop.closest('.chip');
      var name = chip.querySelector('.pick').textContent.trim();
      var count = Number(drop.dataset.count || 0);

      window.pharosConfirm({
        title: 'Delete the service ' + name + '?',
        body: count
          ? 'Its ' + count + ' component' + (count === 1 ? '' : 's') + ' and their uptime history are <strong>kept</strong> — they move to the page without a heading. Only the grouping is lost.'
          : 'It has no components. Nothing else changes.',
        action: 'Delete service',
      }).then(function (agreed) {
        if (!agreed) { return; }

        send(@json(url('/admin/services')) + '/' + chip.dataset.value, 'DELETE').then(function (response) {
          if (!response.ok) { return; }

          if (groupField.value === chip.dataset.value) { groupField.value = ''; }
          chip.remove();
          paintGroups();
        });
      });
    });
  }

  // ---- new service dialog ----------------------------------------------
  var dialog = document.getElementById('service-dialog');
  var name   = document.getElementById('service-name');
  var error  = document.getElementById('service-error');
  var save   = document.getElementById('service-save');
  var select = groupPicker ? null : groupField;

  function fail(message) { error.textContent = message; error.style.display = 'block'; }

  document.querySelector('[data-new-service]').addEventListener('click', function () {
    error.style.display = 'none';
    name.value = '';
    dialog.showModal();
    name.focus();
  });

  document.getElementById('service-cancel').addEventListener('click', function () { dialog.close(); });

  name.addEventListener('keydown', function (event) {
    if (event.key === 'Enter') { event.preventDefault(); save.click(); }
  });

  save.addEventListener('click', function () {
    if (!name.value.trim()) { return fail('Give the service a name.'); }

    save.disabled = true;
    error.style.display = 'none';

    fetch(@json(route('admin.groups.store')), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF },
      body: JSON.stringify({
        name: name.value.trim(),
        visible: document.getElementById('service-visible').checked ? 1 : 0,
        collapsed: 1,
      }),
    })
      .then(function (response) {
        return response.json().then(function (body) { return { ok: response.ok, body: body }; });
      })
      .then(function (result) {
        if (!result.ok) {
          // Laravel's 422 shape; anything else is unexpected and says so.
          var messages = result.body && result.body.errors && result.body.errors.name;
          return fail(messages ? messages[0] : 'Could not add the service.');
        }

        if (select) {
          select.add(new Option(result.body.name, result.body.id, true, true));
        } else {
          var chip = document.createElement('span');
          chip.className = 'chip';
          chip.dataset.value = String(result.body.id);
          chip.dataset.on = 'false';

          var pick = document.createElement('button');
          pick.type = 'button';
          pick.className = 'pick';
          pick.textContent = result.body.name;

          var drop = document.createElement('button');
          drop.type = 'button';
          drop.className = 'drop';
          drop.dataset.dropService = String(result.body.id);
          drop.dataset.count = '0';
          drop.setAttribute('aria-label', 'Delete the service ' + result.body.name);
          drop.innerHTML = '&times;';

          chip.append(pick, drop);
          groupPicker.insertBefore(chip, groupPicker.querySelector('.add'));
          groupField.value = String(result.body.id);
          paintGroups();
        }

        dialog.close();
      })
      .catch(function () { fail('Could not reach the server.'); })
      .finally(function () { save.disabled = false; });
  });

  // ---- tags -------------------------------------------------------------
  var tagPicker = document.querySelector('[data-tag-picker]');
  var tags = document.getElementById('tags');

  function current() {
    return tags.value.split(',').map(function (t) { return t.trim(); }).filter(Boolean);
  }

  function paintTags() {
    var have = current().map(function (t) { return t.toLowerCase(); });
    tagPicker.querySelectorAll('.chip').forEach(function (chip) {
      chip.dataset.on = have.indexOf(chip.dataset.value.toLowerCase()) > -1 ? 'true' : 'false';
    });
  }

  if (tagPicker && tags) {
    paintTags();
    tags.addEventListener('input', paintTags);

    tagPicker.addEventListener('click', function (event) {
      var chip = event.target.closest('.chip');
      if (!chip) { return; }

      var tag = chip.dataset.value;

      if (event.target.closest('.pick')) {
        var list = current();
        var at = list.findIndex(function (t) { return t.toLowerCase() === tag.toLowerCase(); });

        if (at > -1) { list.splice(at, 1); } else { list.push(tag); }

        tags.value = list.join(', ');
        return paintTags();
      }

      if (!event.target.closest('[data-drop-tag]')) { return; }

      window.pharosConfirm({
        title: 'Remove the tag ' + tag + '?',
        body: 'It is taken off <strong>every component</strong> that carries it. Tags are only text on a component, so there is nothing else to delete — and nothing else changes.',
        action: 'Remove everywhere',
      }).then(function (agreed) {
        if (!agreed) { return; }

        send(@json(url('/admin/components/tags')) + '/' + encodeURIComponent(tag), 'DELETE')
          .then(function (response) {
            if (!response.ok) { return; }

            var list = current().filter(function (t) { return t.toLowerCase() !== tag.toLowerCase(); });
            tags.value = list.join(', ');
            chip.remove();
            paintTags();
          });
      });
    });
  }
})();
</script>
@endsection
