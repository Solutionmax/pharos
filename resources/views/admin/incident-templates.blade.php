@extends('layouts.admin')
@section('title', 'Incident templates')
@section('content')
@include('partials.pagehead', [
  'title' => 'Incident templates',
  'sub' => 'Ready wording for incidents that happen more than once',
  'back' => ['url' => \App\Services\PageUrls::route('admin.incidents'), 'label' => 'Incidents'],
  'action' => ['url' => \App\Services\PageUrls::route('admin.incidents.templates.create'), 'label' => 'New template'],
])

<div class="op-cols">
  <section class="op-card" aria-labelledby="templates-title">
    <header><h3 id="templates-title">Templates for {{ app(\App\Services\PageContext::class)->page()->name }}</h3><span class="hint">{{ $templates->count() }} {{ \Illuminate\Support\Str::plural('template', $templates->count()) }}</span></header>
    @if ($templates->isEmpty())
      <div class="op-empty" style="border:0;border-radius:0 0 16px 16px">
        @include('partials.icon', ['name' => 'empty', 'size' => 28])
        <b>No templates yet.</b>
        <span>Save the wording for a recurring incident once, then start from it in Report an incident or from n8n.</span>
        <a class="btn" href="{{ \App\Services\PageUrls::route('admin.incidents.templates.create') }}">New template</a>
      </div>
    @else
      <div class="scroll"><table class="op-table">
        <thead><tr><th>Template</th><th class="hide-sm">Title it writes</th><th class="hide-sm">API name</th><th><span class="sr-only">Actions</span></th></tr></thead>
        <tbody>
        @foreach ($templates as $template)
          <tr>
            <td><b>{{ $template->name }}</b><div class="sub">{{ \Illuminate\Support\Str::limit($template->body_template, 90) }}</div></td>
            <td class="hide-sm">{{ $template->title_template }}</td>
            <td class="hide-sm"><code class="mono" style="font-size:12px">{{ $template->slug }}</code></td>
            <td class="right"><span class="rowacts">
              <a href="{{ \App\Services\PageUrls::route('admin.incidents.templates.edit', $template) }}">Edit</a>
              <form method="POST" action="{{ \App\Services\PageUrls::route('admin.incidents.templates.destroy', $template) }}"
                    data-confirm-title="Delete {{ $template->name }}?"
                    data-confirm="Incidents made with it stay as they are. <strong>API calls that name it stop working.</strong>"
                    data-confirm-action="Delete template">
                @csrf @method('DELETE')
                <button type="submit">Delete</button>
              </form>
            </span></td>
          </tr>
        @endforeach
        </tbody>
      </table></div>
    @endif
  </section>

  <aside class="op-side">
    <section class="op-card">
      <header><h3>Placeholders</h3></header>
      <div class="bd" style="display:flex;flex-direction:column;gap:10px;font-size:13px;color:var(--ink-2)">
        <p>Write <code class="mono">@{{ component }}</code> or any other word in double braces. The API fills them from <code class="mono">vars</code>; in Report an incident you replace them by hand.</p>
        <div class="ix-code"><pre id="template-api">{"template": "{{ $templates->first()?->slug ?? 'mail-down' }}", "vars": {"component": "SMTP"}, "status": "investigating"}</pre><button type="button" class="ix-copy" data-copy-target="template-api">Copy</button></div>
        <p class="op-dim">POST that to <code class="mono">{{ \App\Services\PageUrls::api('incidents') }}</code> with a write token.</p>
      </div>
    </section>
  </aside>
</div>
<script defer src="{{ asset('assets/pharos-ops.js') }}?v=0.7.0"></script>
@endsection
