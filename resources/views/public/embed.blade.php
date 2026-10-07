{{ __('(function () {
  \'use strict\';
  var script = document.currentScript;
  if (!script || !script.parentNode) return;
  var link = document.createElement(\'a\');
  link.href =') }} {!! json_encode($statusUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}{{ __(';
  link.target = \'_blank\'; link.rel = \'noopener noreferrer\';
  link.setAttribute(\'aria-live\', \'polite\');
  link.style.cssText = \'display:inline-flex;align-items:center;gap:8px;padding:9px 14px;border:1px solid #cbd5e1;border-radius:8px;font:14px system-ui,sans-serif;color:#334155;background:#fff;text-decoration:none\';
  var dot = document.createElement(\'span\');
  dot.textContent = \'●\'; dot.setAttribute(\'aria-hidden\', \'true\');
  var label = document.createElement(\'span\');
  link.appendChild(dot); link.appendChild(label); script.parentNode.insertBefore(link, script.nextSibling);
  var unavailable =') }} {!! json_encode(__('Status unavailable'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}{{ __(';
  label.textContent = unavailable;
  function refresh() {
    if (!link.isConnected) return;
    var controller = new AbortController();
    var timer = setTimeout(function () { controller.abort(); }, 10000);
    fetch(') }}{!! json_encode($dataUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}{{ __(', {credentials:\'omit\', signal:controller.signal})
      .then(function (response) { if (!response.ok) throw new Error(\'unavailable\'); return response.json(); })
      .then(function (data) { label.textContent = data.name + \': \' + data.label; dot.style.color = [\'\',\'#237a47\',\'#946500\',\'#b52d39\',\'#b52d39\',\'#4466aa\'][data.status] || \'#64748b\'; })
      .catch(function () { label.textContent = unavailable; dot.style.color = \'#64748b\'; })
      .finally(function () { clearTimeout(timer); if (link.isConnected) setTimeout(refresh, 60000); });
  }
  refresh();
}());') }}
